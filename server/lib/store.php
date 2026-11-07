<?php
declare(strict_types=1);
/**
 * 資料層與業務邏輯。HTTP 層（api.php）與 CLI 工具共用。
 */
require_once __DIR__ . '/money.php';

const MAX_MEMBERS = 50;
const MAX_EXPENSES = 3000;
const MAX_TRIP_NAME = 60;
const MAX_DESC = 100;
const MAX_MEMBER_NAME = 30;
const MAX_WEIGHT = 1000;
const DEFAULT_DESC = '未命名使費';

final class ApiError extends Exception
{
    public function __construct(public int $status, public string $errCode, string $message, public array $extra = [])
    {
        parent::__construct($message);
    }
}

function fail(int $status, string $code, string $message, array $extra = []): never
{
    throw new ApiError($status, $code, $message, $extra);
}

// ---------------------------------------------------------------- DB

function db_open(string $path, bool $persistent = false): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        // 持久連線：FPM worker 保持 SQLite 開啟，避免每個請求關閉時都要 checkpoint 並刪除 WAL（DS218 硬碟上約 400 ms）
        PDO::ATTR_PERSISTENT => $persistent,
    ]);
    if ($persistent) {
        try { $pdo->exec('ROLLBACK'); } catch (PDOException) {}   // 上一個請求異常中止時遺留的交易
    }
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='trips'")->fetchColumn();
    if (!$has) $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
    return $pdo;
}

/** BEGIN IMMEDIATE：寫入鎖在交易開始時取得，並發寫入會排隊（busy_timeout）而不是失敗。 */
function db_tx(PDO $pdo, callable $fn): mixed
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn();
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function q(PDO $pdo, string $sql, array $args = []): PDOStatement
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st;
}

// ---------------------------------------------------------------- Token

/** random_bytes(16) → base62，固定 22 字元 */
function token_new(): string
{
    $n = '0';
    foreach (str_split(random_bytes(16)) as $byte) $n = bcadd(bcmul($n, '256', 0), (string) ord($byte), 0);
    $alpha = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $s = '';
    while (bccomp($n, '0', 0) > 0) {
        $s = $alpha[(int) bcmod($n, '62', 0)] . $s;
        $n = bcdiv($n, '62', 0);
    }
    return str_pad($s, 22, '0', STR_PAD_LEFT);
}

function token_hash(string $t): string
{
    return hash('sha256', $t);
}

function token_valid_format(mixed $t): bool
{
    return is_string($t) && preg_match('/^[0-9A-Za-z]{22}$/', $t) === 1;
}

/** @return array{trip: array, mode: string}|null */
function trip_by_token(PDO $pdo, mixed $token): ?array
{
    // 格式不符亦照樣做一次雜湊與查詢，令回應時間與「格式正確但不存在」一致
    $h = token_hash(is_string($token) ? $token : '');
    $row = q($pdo, 'SELECT * FROM trips WHERE edit_token_hash = ? OR view_token_hash = ?', [$h, $h])->fetch();
    if (!$row || !token_valid_format($token)) return null;
    $mode = hash_equals($row['edit_token_hash'], $h) ? 'edit' : 'view';
    return ['trip' => $row, 'mode' => $mode];
}

// ---------------------------------------------------------------- 驗證

function clean_text(mixed $v, int $max, string $field, string $label, bool $allowEmpty = false): string
{
    if ($v === null) $v = '';
    if (!is_string($v)) fail(422, 'invalid_' . $field, "{$label}格式不正確。");
    $v = trim(preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u', '', $v) ?? '');
    if ($v === '' && !$allowEmpty) fail(422, 'empty_' . $field, "{$label}不可留空。");
    if (mb_strlen($v) > $max) fail(422, 'too_long_' . $field, "{$label}最多 {$max} 字。");
    return $v;
}

function check_currency(mixed $cur): string
{
    if (!is_currency($cur)) fail(422, 'unsupported_currency', '不支援此貨幣。');
    return $cur;
}

function check_date(mixed $d): string
{
    if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        fail(422, 'invalid_date', '日期格式須為 YYYY-MM-DD。');
    }
    return $d;
}

// ---------------------------------------------------------------- 行程

/** 每次寫入：版本加一、更新活躍時間、寫 audit_log */
function trip_touch(PDO $pdo, int $tripId, string $action, array $payload): void
{
    $now = now_iso();
    q($pdo, 'UPDATE trips SET version = version + 1, last_active_at = ? WHERE id = ?', [$now, $tripId]);
    q($pdo, 'INSERT INTO audit_log (trip_id, action, payload_json, at) VALUES (?, ?, ?, ?)',
        [$tripId, $action, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
}

/** @return array{id:int, edit_token:string, view_token:string} */
function trip_create(PDO $pdo, mixed $name, mixed $base, mixed $memberNames): array
{
    $name = clean_text($name, MAX_TRIP_NAME, 'trip_name', '行程名稱');
    $base = check_currency($base);
    if (!is_array($memberNames) || !array_is_list($memberNames)) fail(422, 'invalid_members', '成員名單格式不正確。');
    if (count($memberNames) < 1) fail(422, 'no_members', '至少要有一位成員。');
    if (count($memberNames) > MAX_MEMBERS) fail(422, 'too_many_members', '每個行程最多 ' . MAX_MEMBERS . ' 位成員。');
    $names = [];
    foreach ($memberNames as $n) {
        $n = clean_text($n, MAX_MEMBER_NAME, 'member_name', '成員名稱');
        if (in_array($n, $names, true)) fail(422, 'duplicate_member', "成員名稱「{$n}」重複。");
        $names[] = $n;
    }
    $edit = token_new();
    $view = token_new();
    $id = db_tx($pdo, function () use ($pdo, $name, $base, $names, $edit, $view) {
        $now = now_iso();
        q($pdo, 'INSERT INTO trips (edit_token_hash, view_token_hash, name, base_currency, version, created_at, last_active_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [token_hash($edit), token_hash($view), $name, $base, $now, $now]);
        $id = (int) $pdo->lastInsertId();
        foreach ($names as $i => $n) {
            q($pdo, 'INSERT INTO members (trip_id, display_name, sort_order, archived) VALUES (?, ?, ?, 0)', [$id, $n, $i + 1]);
        }
        trip_touch($pdo, $id, 'trip.create', ['name' => $name, 'base_currency' => $base, 'members' => $names]);
        return $id;
    });
    return ['id' => $id, 'edit_token' => $edit, 'view_token' => $view];
}

function trip_row(PDO $pdo, int $id): array
{
    return q($pdo, 'SELECT * FROM trips WHERE id = ?', [$id])->fetch();
}

function trip_members(PDO $pdo, int $tripId): array
{
    return q($pdo, 'SELECT id, display_name, sort_order, archived FROM members WHERE trip_id = ? ORDER BY sort_order, id', [$tripId])->fetchAll();
}

function trip_rates(PDO $pdo, int $tripId): array
{
    $out = [];
    foreach (q($pdo, 'SELECT currency, rate_e8 FROM trip_rates WHERE trip_id = ? ORDER BY currency', [$tripId]) as $r) $out[$r['currency']] = (int) $r['rate_e8'];
    return $out;
}

/** 單筆使費的輸出格式 */
function expense_view(array $e, array $shares, string $base): array
{
    $dp = currency_dp($e['currency']);
    return [
        'id' => (int) $e['id'],
        'description' => $e['description'],
        'spent_on' => $e['spent_on'],
        'payer_id' => (int) $e['payer_id'],
        'amount_minor' => (int) $e['amount_minor'],
        'amount' => format_minor((int) $e['amount_minor'], $dp),
        'currency' => $e['currency'],
        'rate_e8' => (int) $e['rate_e8'],
        'rate' => format_rate((int) $e['rate_e8']),
        'base_minor' => (int) $e['base_minor'],
        'split_mode' => $e['split_mode'],
        'shares' => $shares,
        'created_by' => $e['created_by'] === null ? null : (int) $e['created_by'],
        'created_at' => $e['created_at'],
        'updated_at' => $e['updated_at'],
        'version' => (int) $e['version'],
    ];
}

function expense_load(PDO $pdo, int $tripId, int $id): ?array
{
    $e = q($pdo, 'SELECT * FROM expenses WHERE id = ? AND trip_id = ? AND deleted_at IS NULL', [$id, $tripId])->fetch();
    if (!$e) return null;
    $sh = q($pdo, 'SELECT member_id, weight, share_minor FROM shares WHERE expense_id = ?', [$id])->fetchAll();
    $e['_shares'] = array_map(fn($s) => ['member_id' => (int) $s['member_id'], 'weight' => (int) $s['weight'], 'share_minor' => (int) $s['share_minor']], $sh);
    return $e;
}

/** GET /api/trip 的完整內容 */
function trip_state(PDO $pdo, int $tripId, string $mode): array
{
    $t = trip_row($pdo, $tripId);
    $base = $t['base_currency'];
    $members = trip_members($pdo, $tripId);
    $order = [];
    foreach ($members as $m) $order[(int) $m['id']] = (int) $m['sort_order'];

    $rows = q($pdo, 'SELECT * FROM expenses WHERE trip_id = ? AND deleted_at IS NULL ORDER BY spent_on DESC, id DESC', [$tripId])->fetchAll();
    $shareRows = q($pdo, 'SELECT s.expense_id, s.member_id, s.weight, s.share_minor FROM shares s JOIN expenses e ON e.id = s.expense_id WHERE e.trip_id = ? AND e.deleted_at IS NULL', [$tripId])->fetchAll();
    $byExp = [];
    foreach ($shareRows as $s) $byExp[(int) $s['expense_id']][] = ['member_id' => (int) $s['member_id'], 'weight' => (int) $s['weight'], 'share_minor' => (int) $s['share_minor']];

    $expenses = [];
    $calc = [];
    $total = 0;
    foreach ($rows as $e) {
        $sh = $byExp[(int) $e['id']] ?? [];
        usort($sh, fn($a, $b) => ($order[$a['member_id']] ?? 0) <=> ($order[$b['member_id']] ?? 0));
        $expenses[] = expense_view($e, $sh, $base);
        $m = [];
        foreach ($sh as $s) $m[$s['member_id']] = $s['share_minor'];
        $calc[] = ['payer_id' => (int) $e['payer_id'], 'base_minor' => (int) $e['base_minor'], 'shares' => $m];
        $total += (int) $e['base_minor'];
    }
    $setRows = q($pdo, 'SELECT id, from_id, to_id, amount_minor, settled_on FROM settlements WHERE trip_id = ? AND deleted_at IS NULL ORDER BY settled_on, id', [$tripId])->fetchAll();
    $settlements = array_map(fn($s) => ['id' => (int) $s['id'], 'from_id' => (int) $s['from_id'], 'to_id' => (int) $s['to_id'], 'amount_minor' => (int) $s['amount_minor'], 'settled_on' => $s['settled_on']], $setRows);

    $bal = compute_balances(array_map(fn($m) => ['id' => (int) $m['id']], $members), $calc, $settlements);
    $balances = [];
    $net = [];
    foreach ($members as $m) {
        $id = (int) $m['id'];
        $r = $bal[$id];
        $balances[] = ['member_id' => $id, 'paid_minor' => $r['paid'], 'owed_minor' => $r['owed'], 'settled_minor' => $r['settled'], 'net_minor' => $r['net']];
        $net[$id] = $r['net'];
    }
    $rates = [];
    foreach (trip_rates($pdo, $tripId) as $cur => $r) $rates[] = ['currency' => $cur, 'rate_e8' => $r, 'rate' => format_rate($r)];

    return [
        'trip' => [
            'id' => (int) $t['id'],
            'name' => $t['name'],
            'base_currency' => $base,
            'version' => (int) $t['version'],
            'created_at' => $t['created_at'],
            'last_active_at' => $t['last_active_at'],
            'mode' => $mode,
        ],
        'currencies' => CURRENCY_DP,
        'members' => array_map(fn($m) => ['id' => (int) $m['id'], 'display_name' => $m['display_name'], 'sort_order' => (int) $m['sort_order'], 'archived' => (bool) $m['archived']], $members),
        'rates' => $rates,
        'expenses' => $expenses,
        'settlements' => $settlements,
        'balances' => $balances,
        'suggestions' => suggest_transfers($net, $order),
        'total_minor' => $total,
    ];
}

function trip_patch(PDO $pdo, int $tripId, array $in): void
{
    db_tx($pdo, function () use ($pdo, $tripId, $in) {
        $t = trip_row($pdo, $tripId);
        $changes = [];
        if (array_key_exists('name', $in)) {
            $changes['name'] = clean_text($in['name'], MAX_TRIP_NAME, 'trip_name', '行程名稱');
        }
        if (array_key_exists('base_currency', $in)) {
            $base = check_currency($in['base_currency']);
            if ($base !== $t['base_currency']) {
                $n = (int) q($pdo, 'SELECT COUNT(*) FROM expenses WHERE trip_id = ? AND deleted_at IS NULL', [$tripId])->fetchColumn();
                if ($n > 0) fail(409, 'base_currency_locked', '行程已有使費紀錄，不能再更改基準貨幣，否則舊帳的換算會失效。');
                $changes['base_currency'] = $base;
            }
        }
        if (!$changes) fail(422, 'nothing_to_change', '沒有可更改的欄位。');
        foreach ($changes as $k => $v) q($pdo, "UPDATE trips SET {$k} = ? WHERE id = ?", [$v, $tripId]);
        // 匯率表以基準貨幣為單位，基準貨幣改變後舊匯率已無意義
        if (isset($changes['base_currency'])) q($pdo, 'DELETE FROM trip_rates WHERE trip_id = ?', [$tripId]);
        trip_touch($pdo, $tripId, 'trip.update', $changes);
    });
}

/** PUT /api/trip/rates  {rates: {"JPY": "0.0511", ...}} — 取代整個匯率表；不影響已存在的使費 */
function rates_put(PDO $pdo, int $tripId, mixed $rates): void
{
    if (!is_array($rates) || (array_is_list($rates) && $rates)) fail(422, 'invalid_rates', '匯率表格式不正確。');
    db_tx($pdo, function () use ($pdo, $tripId, $rates) {
        $base = trip_row($pdo, $tripId)['base_currency'];
        $clean = [];
        foreach ($rates as $cur => $r) {
            $cur = check_currency((string) $cur);
            if ($cur === $base) continue;   // 基準貨幣固定為 1
            $e8 = parse_rate($r);
            if ($e8 === null) fail(422, 'invalid_rate', "{$cur} 的匯率必須是正數，最多 8 位小數。");
            $clean[$cur] = $e8;
        }
        q($pdo, 'DELETE FROM trip_rates WHERE trip_id = ?', [$tripId]);
        foreach ($clean as $cur => $e8) q($pdo, 'INSERT INTO trip_rates (trip_id, currency, rate_e8) VALUES (?, ?, ?)', [$tripId, $cur, $e8]);
        trip_touch($pdo, $tripId, 'trip.rates', array_map('format_rate', $clean));
    });
}

// ---------------------------------------------------------------- 成員

function member_add(PDO $pdo, int $tripId, mixed $name): int
{
    $name = clean_text($name, MAX_MEMBER_NAME, 'member_name', '成員名稱');
    return db_tx($pdo, function () use ($pdo, $tripId, $name) {
        $n = (int) q($pdo, 'SELECT COUNT(*) FROM members WHERE trip_id = ?', [$tripId])->fetchColumn();
        if ($n >= MAX_MEMBERS) fail(422, 'too_many_members', '每個行程最多 ' . MAX_MEMBERS . ' 位成員（包括已封存的成員）。');
        if (q($pdo, 'SELECT 1 FROM members WHERE trip_id = ? AND display_name = ?', [$tripId, $name])->fetchColumn()) {
            fail(422, 'duplicate_member', "成員名稱「{$name}」已存在。");
        }
        $max = (int) q($pdo, 'SELECT COALESCE(MAX(sort_order), 0) FROM members WHERE trip_id = ?', [$tripId])->fetchColumn();
        q($pdo, 'INSERT INTO members (trip_id, display_name, sort_order, archived) VALUES (?, ?, ?, 0)', [$tripId, $name, $max + 1]);
        $id = (int) $pdo->lastInsertId();
        trip_touch($pdo, $tripId, 'member.create', ['member_id' => $id, 'display_name' => $name]);
        return $id;
    });
}

function member_patch(PDO $pdo, int $tripId, int $memberId, array $in): void
{
    db_tx($pdo, function () use ($pdo, $tripId, $memberId, $in) {
        $m = q($pdo, 'SELECT * FROM members WHERE id = ? AND trip_id = ?', [$memberId, $tripId])->fetch();
        if (!$m) fail(404, 'member_not_found', '找不到此成員。');
        $changes = [];
        if (array_key_exists('display_name', $in)) {
            $name = clean_text($in['display_name'], MAX_MEMBER_NAME, 'member_name', '成員名稱');
            if ($name !== $m['display_name'] && q($pdo, 'SELECT 1 FROM members WHERE trip_id = ? AND display_name = ? AND id <> ?', [$tripId, $name, $memberId])->fetchColumn()) {
                fail(422, 'duplicate_member', "成員名稱「{$name}」已存在。");
            }
            $changes['display_name'] = $name;
        }
        if (array_key_exists('sort_order', $in)) {
            if (!is_int($in['sort_order']) || $in['sort_order'] < 0 || $in['sort_order'] > 1000000) fail(422, 'invalid_sort_order', '排序必須是非負整數。');
            $changes['sort_order'] = $in['sort_order'];
        }
        if (array_key_exists('archived', $in)) {
            if (!is_bool($in['archived'])) fail(422, 'invalid_archived', 'archived 必須是 true 或 false。');
            $changes['archived'] = $in['archived'] ? 1 : 0;
        }
        if (!$changes) fail(422, 'nothing_to_change', '沒有可更改的欄位。');
        foreach ($changes as $k => $v) q($pdo, "UPDATE members SET {$k} = ? WHERE id = ?", [$v, $memberId]);
        trip_touch($pdo, $tripId, 'member.update', ['member_id' => $memberId] + $changes);
    });
}

// ---------------------------------------------------------------- 使費

/**
 * 由請求內容（新增或合併現有）計算一筆使費的完整欄位與 shares。
 * 封存的成員不可被新選為付款人或分攤人；修改舊使費時，原本已在該筆內的封存成員可以保留。
 */
function expense_build(PDO $pdo, array $trip, array $in, ?array $existing): array
{
    $tripId = (int) $trip['id'];
    $base = $trip['base_currency'];
    $members = [];
    foreach (trip_members($pdo, $tripId) as $m) $members[(int) $m['id']] = $m;
    $already = [];
    if ($existing) {
        $already[(int) $existing['payer_id']] = true;
        foreach ($existing['_shares'] as $s) $already[$s['member_id']] = true;
    }
    $usable = function (mixed $id) use ($members, $already): bool {
        return is_int($id) && isset($members[$id]) && (!$members[$id]['archived'] || isset($already[$id]));
    };

    $pick = fn(string $k, mixed $def) => array_key_exists($k, $in) ? $in[$k] : $def;

    $desc = clean_text($pick('description', $existing['description'] ?? ''), MAX_DESC, 'description', '使費描述', true);
    if ($desc === '') $desc = DEFAULT_DESC;
    $spentOn = check_date($pick('spent_on', $existing['spent_on'] ?? gmdate('Y-m-d')));

    $currency = check_currency($pick('currency', $existing['currency'] ?? $base));
    $dp = currency_dp($currency);

    if (array_key_exists('amount', $in)) {
        $amount = parse_decimal($in['amount'], $dp);
        if ($amount === null) fail(422, 'invalid_amount', $dp === 0 ? "{$currency} 金額必須是整數。" : "金額格式不正確（{$currency} 最多 {$dp} 位小數）。");
    } elseif ($existing && $existing['currency'] === $currency) {
        $amount = (int) $existing['amount_minor'];
    } else {
        fail(422, 'missing_amount', '請輸入金額。');
    }
    if ($amount === 0) fail(422, 'zero_amount', '金額不可為零。');
    if (abs($amount) > MAX_AMOUNT_MINOR) fail(422, 'amount_too_large', '金額超出上限。');

    // 匯率快照：本筆指定 > 原有快照（貨幣不變時）> 行程目前匯率
    if ($currency === $base) {
        $rate = RATE_SCALE;
    } elseif (array_key_exists('rate', $in) && $in['rate'] !== null && $in['rate'] !== '') {
        $rate = parse_rate($in['rate']);
        if ($rate === null) fail(422, 'invalid_rate', '匯率必須是正數，最多 8 位小數。');
    } elseif ($existing && $existing['currency'] === $currency) {
        $rate = (int) $existing['rate_e8'];
    } else {
        $rates = trip_rates($pdo, $tripId);
        if (!isset($rates[$currency])) fail(422, 'rate_missing', "行程未設定 {$currency} 的匯率，請先在設定內加入，或在此筆填寫匯率。");
        $rate = $rates[$currency];
    }
    try {
        $baseMinor = convert_to_base($amount, $rate, $dp, currency_dp($base));
    } catch (RangeException) {
        fail(422, 'amount_too_large', '換算後金額超出上限。');
    }
    if ($baseMinor === 0) fail(422, 'zero_after_conversion', '換算成基準貨幣後金額為零，請檢查金額或匯率。');

    $payer = $pick('payer_id', $existing ? (int) $existing['payer_id'] : null);
    if (!$usable($payer)) fail(422, 'invalid_payer', '付款人不存在或已封存。');

    $mode = $pick('split_mode', $existing['split_mode'] ?? 'equal');
    if (!in_array($mode, ['equal', 'weight'], true)) fail(422, 'invalid_split_mode', '不支援此分攤方式。');

    if (array_key_exists('sharers', $in)) {
        $raw = $in['sharers'];
        if (!is_array($raw) || !array_is_list($raw) || !$raw) fail(422, 'no_sharers', '分攤人至少要選一位。');
        $weights = [];
        foreach ($raw as $s) {
            if (is_int($s)) { $id = $s; $w = 1; }
            elseif (is_array($s)) { $id = $s['member_id'] ?? null; $w = $s['weight'] ?? 1; }
            else fail(422, 'invalid_sharers', '分攤人格式不正確。');
            if (!$usable($id)) fail(422, 'invalid_sharer', '分攤人不存在或已封存。');
            if (!is_int($w) || $w < 1 || $w > MAX_WEIGHT) fail(422, 'invalid_weight', '份數必須是 1 至 ' . MAX_WEIGHT . ' 的整數。');
            if ($mode === 'equal') $w = 1;
            if (isset($weights[$id])) fail(422, 'duplicate_sharer', '分攤人重複。');
            $weights[$id] = $w;
        }
    } elseif ($existing) {
        $weights = [];
        foreach ($existing['_shares'] as $s) $weights[$s['member_id']] = $mode === 'equal' ? 1 : $s['weight'];
    } else {
        fail(422, 'no_sharers', '分攤人至少要選一位。');
    }

    $order = [];
    foreach ($members as $id => $m) $order[$id] = (int) $m['sort_order'];
    $shares = allocate_shares($baseMinor, $weights, $order);
    if (array_sum($shares) !== $baseMinor) fail(500, 'allocation_mismatch', '分攤計算出錯，已拒絕寫入。');

    $createdBy = $existing ? ($existing['created_by'] === null ? null : (int) $existing['created_by']) : ($in['created_by'] ?? null);
    if ($createdBy !== null && !(is_int($createdBy) && isset($members[$createdBy]))) $createdBy = null;

    return [
        'row' => [
            'description' => $desc, 'spent_on' => $spentOn, 'payer_id' => $payer,
            'amount_minor' => $amount, 'currency' => $currency, 'rate_e8' => $rate,
            'base_minor' => $baseMinor, 'split_mode' => $mode, 'created_by' => $createdBy,
        ],
        'weights' => $weights,
        'shares' => $shares,
    ];
}

function shares_write(PDO $pdo, int $expenseId, array $weights, array $shares): void
{
    q($pdo, 'DELETE FROM shares WHERE expense_id = ?', [$expenseId]);
    foreach ($shares as $mid => $s) {
        q($pdo, 'INSERT INTO shares (expense_id, member_id, weight, share_minor) VALUES (?, ?, ?, ?)', [$expenseId, $mid, $weights[$mid], $s]);
    }
    $sum = (int) q($pdo, 'SELECT COALESCE(SUM(share_minor), 0) FROM shares WHERE expense_id = ?', [$expenseId])->fetchColumn();
    $base = (int) q($pdo, 'SELECT base_minor FROM expenses WHERE id = ?', [$expenseId])->fetchColumn();
    if ($sum !== $base) fail(500, 'allocation_mismatch', '分攤加總與換算金額不符，已拒絕寫入。');
}

function expense_create(PDO $pdo, int $tripId, array $in): int
{
    return db_tx($pdo, function () use ($pdo, $tripId, $in) {
        $n = (int) q($pdo, 'SELECT COUNT(*) FROM expenses WHERE trip_id = ? AND deleted_at IS NULL', [$tripId])->fetchColumn();
        if ($n >= MAX_EXPENSES) fail(422, 'too_many_expenses', '每個行程最多 ' . MAX_EXPENSES . ' 筆使費。');
        $b = expense_build($pdo, trip_row($pdo, $tripId), $in, null);
        $r = $b['row'];
        $now = now_iso();
        q($pdo, 'INSERT INTO expenses (trip_id, description, spent_on, payer_id, amount_minor, currency, rate_e8, base_minor, split_mode, created_by, created_at, updated_at, version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$tripId, $r['description'], $r['spent_on'], $r['payer_id'], $r['amount_minor'], $r['currency'], $r['rate_e8'], $r['base_minor'], $r['split_mode'], $r['created_by'], $now, $now]);
        $id = (int) $pdo->lastInsertId();
        shares_write($pdo, $id, $b['weights'], $b['shares']);
        trip_touch($pdo, $tripId, 'expense.create', ['expense_id' => $id] + $r + ['shares' => $b['shares']]);
        return $id;
    });
}

function expense_patch(PDO $pdo, int $tripId, int $id, array $in, ?int $ifMatch): void
{
    db_tx($pdo, function () use ($pdo, $tripId, $id, $in, $ifMatch) {
        $e = expense_load($pdo, $tripId, $id);
        if (!$e) fail(404, 'expense_not_found', '找不到這筆使費，可能已被刪除。');
        $trip = trip_row($pdo, $tripId);
        if ($ifMatch === null) fail(428, 'if_match_required', '修改使費時必須附上版本號（If-Match）。');
        if ($ifMatch !== (int) $e['version']) {
            fail(409, 'version_conflict', '這筆使費剛被其他人修改，請檢查最新內容後再修改。',
                ['current' => expense_view($e, $e['_shares'], $trip['base_currency'])]);
        }
        $b = expense_build($pdo, $trip, $in, $e);
        $r = $b['row'];
        q($pdo, 'UPDATE expenses SET description = ?, spent_on = ?, payer_id = ?, amount_minor = ?, currency = ?, rate_e8 = ?, base_minor = ?, split_mode = ?,
                 updated_at = ?, version = version + 1 WHERE id = ? AND version = ?',
            [$r['description'], $r['spent_on'], $r['payer_id'], $r['amount_minor'], $r['currency'], $r['rate_e8'], $r['base_minor'], $r['split_mode'], now_iso(), $id, $ifMatch]);
        shares_write($pdo, $id, $b['weights'], $b['shares']);
        trip_touch($pdo, $tripId, 'expense.update', ['expense_id' => $id, 'from_version' => $ifMatch] + $r + ['shares' => $b['shares']]);
    });
}

function expense_delete(PDO $pdo, int $tripId, int $id, ?int $ifMatch): void
{
    db_tx($pdo, function () use ($pdo, $tripId, $id, $ifMatch) {
        $e = expense_load($pdo, $tripId, $id);
        if (!$e) fail(404, 'expense_not_found', '找不到這筆使費，可能已被刪除。');
        if ($ifMatch !== null && $ifMatch !== (int) $e['version']) {
            $trip = trip_row($pdo, $tripId);
            fail(409, 'version_conflict', '這筆使費剛被其他人修改，請檢查最新內容後再決定是否刪除。',
                ['current' => expense_view($e, $e['_shares'], $trip['base_currency'])]);
        }
        q($pdo, 'UPDATE expenses SET deleted_at = ?, version = version + 1 WHERE id = ?', [now_iso(), $id]);
        trip_touch($pdo, $tripId, 'expense.delete', ['expense_id' => $id]);
    });
}

// ---------------------------------------------------------------- 限流

/** 固定時間窗口限流；超出時回傳需等待的秒數，否則 0 */
function rate_limit_hit(PDO $pdo, string $bucket, int $limit, int $window): int
{
    $now = time();
    $start = intdiv($now, $window) * $window;
    q($pdo, 'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1', [$bucket, $start]);
    $hits = (int) q($pdo, 'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $start])->fetchColumn();
    if (random_int(1, 200) === 1) q($pdo, 'DELETE FROM rate_limits WHERE window_start < ?', [$now - 7200]);
    return $hits > $limit ? max(1, $start + $window - $now) : 0;
}

// ---------------------------------------------------------------- 維護

/** 刪除一個行程及其所有紀錄（cleanup.php 用） */
function trip_purge(PDO $pdo, int $tripId): void
{
    db_tx($pdo, function () use ($pdo, $tripId) {
        q($pdo, 'DELETE FROM shares WHERE expense_id IN (SELECT id FROM expenses WHERE trip_id = ?)', [$tripId]);
        q($pdo, 'DELETE FROM expenses WHERE trip_id = ?', [$tripId]);
        q($pdo, 'DELETE FROM settlements WHERE trip_id = ?', [$tripId]);
        q($pdo, 'DELETE FROM trip_rates WHERE trip_id = ?', [$tripId]);
        q($pdo, 'DELETE FROM members WHERE trip_id = ?', [$tripId]);
        q($pdo, 'DELETE FROM audit_log WHERE trip_id = ?', [$tripId]);
        q($pdo, 'DELETE FROM trips WHERE id = ?', [$tripId]);
    });
}

/** VACUUM INTO 帶時間戳備份，保留最近 $keep 份；回傳備份路徑 */
function db_backup(PDO $pdo, string $dir, int $keep = 30): string
{
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('cannot create backup dir');
    $path = $dir . '/app-' . gmdate('Ymd-His') . '.sqlite';
    if (file_exists($path)) $path = substr($path, 0, -7) . '-' . bin2hex(random_bytes(2)) . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
    $files = glob($dir . '/app-*.sqlite') ?: [];
    sort($files);
    while (count($files) > $keep) @unlink(array_shift($files));
    return $path;
}
