<?php
declare(strict_types=1);
/**
 * 後台管理 admin/ — 登入由外部 auth 程式庫負責（config 'auth_lib'），建議同時在反向代理限制只准內網存取。
 * 伺服器只存 token 雜湊，舊連結無法再顯示；「重發」會產生新 token，舊連結即時失效，新連結只顯示一次。
 */
require_once __DIR__ . '/api.php';   // config()、client_ip()、store、money
// Sign-in is pluggable: config 'auth_lib' must point at a file defining auth_require(string $app),
// auth_csrf_token(), auth_csrf_check(?string), auth_log(int, string, string, string) and auth_logout_url()
// (php-passkey provides all five). Without one the admin area is disabled and answers 404.
$authLib = config()['auth_lib'] ?? (getenv('SPLIT_EXP_AUTH_LIB') ?: '');
if ($authLib === '' || !is_file($authLib)) { http_response_code(404); exit; }
require_once $authLib;

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_base(): string
{
    return rtrim(config()['base_url'] ?? '/', '/') . '/';
}

function admin_self(): string
{
    return parse_url(admin_base(), PHP_URL_PATH) . 'admin/';
}

// ---------------------------------------------------------------- 版面

function admin_page(string $title, string $body, int $status = 200, string $head = ''): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $base = h(parse_url(admin_base(), PHP_URL_PATH));
    echo "<!DOCTYPE html>\n<html lang=\"zh-Hant-HK\"><head><meta charset=\"utf-8\">"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . "<meta name=\"robots\" content=\"noindex, nofollow\"><meta name=\"referrer\" content=\"no-referrer\">"
        . "<title>" . h($title) . " · 旅行分帳後台</title>"
        . "<link rel=\"stylesheet\" href=\"{$base}assets/app.css\"><link rel=\"stylesheet\" href=\"{$base}assets/admin.css\">"
        . "<script defer src=\"{$base}assets/admin.js\"></script>{$head}</head><body><div class=\"wrap admin\">"
        . $body . "</div></body></html>";
    exit;
}

function admin_header(?string $csrf, string $crumb = ''): string
{
    $self = h(admin_self());
    $out = "<header><h1><a href=\"{$self}\">旅行分帳後台</a>" . ($crumb !== '' ? ' <span class="crumb">／ ' . h($crumb) . '</span>' : '') . "</h1>";
    if ($csrf !== null) {
        $out .= '<span class="inline"><a class="btn ghost sm" href="/admin/">← 管理總覽</a> '
            . '<a class="btn ghost sm" href="' . h(auth_logout_url()) . '">登出</a></span>';
    }
    return $out . "</header>";
}

function admin_csrf_field(string $csrf): string
{
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
}

function admin_money(int $v, string $cur): string
{
    $dp = CURRENCY_DP[$cur] ?? 2;
    $s = format_minor(abs($v), $dp);
    [$i, $f] = array_pad(explode('.', $s, 2), 2, null);
    $i = number_format((int) $i);
    return ($v < 0 ? '−' : '') . $i . ($f !== null ? '.' . $f : '');
}

function admin_time(?string $iso): string
{
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? date('Y-m-d H:i', $t) : h($iso);
}

// ---------------------------------------------------------------- 頁面

function admin_list_page(PDO $pdo, string $csrf, string $flash = ''): never
{
    $rows = $pdo->query("SELECT t.id, t.name, t.base_currency, t.version, t.created_at, t.last_active_at,
            (SELECT COUNT(*) FROM members m WHERE m.trip_id = t.id) AS members,
            (SELECT COUNT(*) FROM members m WHERE m.trip_id = t.id AND m.archived = 1) AS archived,
            (SELECT COUNT(*) FROM expenses e WHERE e.trip_id = t.id AND e.deleted_at IS NULL) AS expenses,
            (SELECT COUNT(*) FROM expenses e WHERE e.trip_id = t.id AND e.deleted_at IS NOT NULL) AS deleted,
            (SELECT COALESCE(SUM(base_minor), 0) FROM expenses e WHERE e.trip_id = t.id AND e.deleted_at IS NULL) AS total
        FROM trips t ORDER BY t.last_active_at DESC")->fetchAll();
    $self = h(admin_self());
    $idle = strtotime('-12 months');
    $tr = '';
    foreach ($rows as $r) {
        $stale = strtotime($r['last_active_at']) < $idle;
        $tr .= '<tr' . ($stale ? ' class="stale"' : '') . '>'
            . '<td class="num">#' . (int) $r['id'] . '</td>'
            . "<td><a href=\"{$self}?trip=" . (int) $r['id'] . '">' . h($r['name']) . '</a>' . ($stale ? ' <span class="tag">閒置逾 12 個月</span>' : '') . '</td>'
            . '<td>' . h($r['base_currency']) . '</td>'
            . '<td class="num">' . (int) $r['members'] . ($r['archived'] ? '<span class="muted">（封存 ' . (int) $r['archived'] . '）</span>' : '') . '</td>'
            . '<td class="num">' . (int) $r['expenses'] . ($r['deleted'] ? '<span class="muted">（已刪 ' . (int) $r['deleted'] . '）</span>' : '') . '</td>'
            . '<td class="num right">' . admin_money((int) $r['total'], $r['base_currency']) . '</td>'
            . '<td class="num">' . admin_time($r['created_at']) . '</td>'
            . '<td class="num">' . admin_time($r['last_active_at']) . '</td>'
            . '<td class="num">' . (int) $r['version'] . '</td></tr>';
    }
    $body = admin_header($csrf)
        . ($flash !== '' ? '<div class="banner info"><span>' . h($flash) . '</span></div>' : '')
        . '<section class="card"><div class="cardhead"><h2>行程（' . count($rows) . '）</h2>'
        . '<span class="muted">按名稱查看內容、重發連結或刪除</span></div>'
        . '<div class="tablewrap"><table><thead><tr><th>ID</th><th>名稱</th><th>基準</th><th>成員</th><th>使費</th><th class="right">總支出</th><th>建立</th><th>最後活動</th><th>版本</th></tr></thead><tbody>'
        . ($tr !== '' ? $tr : '<tr><td colspan="9" class="empty">尚未有行程。</td></tr>')
        . '</tbody></table></div></section>'
        . '<p class="hint">伺服器只儲存連結的雜湊值，無法顯示現有連結。需要連結時請用「重發」：舊連結會即時失效，新連結只顯示一次。</p>';
    admin_page('行程', $body);
}

function admin_trip_page(PDO $pdo, int $id, string $csrf): never
{
    $t = q($pdo, 'SELECT * FROM trips WHERE id = ?', [$id])->fetch();
    if (!$t) admin_page('找不到', admin_header($csrf) . '<div class="banner err"><span>找不到此行程。</span></div>', 404);
    $st = trip_state($pdo, $id, 'view');
    $B = $t['base_currency'];
    $mm = [];
    foreach ($st['members'] as $m) $mm[$m['id']] = $m;
    $name = fn(int $mid) => h($mm[$mid]['display_name'] ?? '?') . (!empty($mm[$mid]['archived']) ? '<span class="muted">（封存）</span>' : '');
    $self = h(admin_self());
    $f = admin_csrf_field($csrf) . '<input type="hidden" name="trip" value="' . $id . '">';

    // 每人淨額 + 過數建議
    $net = '';
    foreach ($st['balances'] as $b) {
        $cls = $b['net_minor'] > 0 ? 'pos' : ($b['net_minor'] < 0 ? 'neg' : 'zero');
        $net .= '<div class="person"><div><div class="name">' . $name($b['member_id']) . '</div><div class="detail num">已墊支 '
            . admin_money($b['paid_minor'], $B) . '　應付 ' . admin_money($b['owed_minor'], $B) . '</div></div>'
            . '<div class="net num ' . $cls . '">' . ($b['net_minor'] > 0 ? '+' : '') . admin_money($b['net_minor'], $B) . '</div></div>';
    }
    $moves = '';
    foreach ($st['suggestions'] as $s) $moves .= '<div class="move"><span>' . $name($s['from_id']) . ' 付給 ' . $name($s['to_id']) . '</span><strong class="num">' . admin_money($s['amount_minor'], $B) . ' ' . h($B) . '</strong></div>';

    // 使費（含已刪除）
    $exp = q($pdo, 'SELECT * FROM expenses WHERE trip_id = ? ORDER BY spent_on DESC, id DESC', [$id])->fetchAll();
    $shares = [];
    foreach (q($pdo, 'SELECT s.expense_id, s.member_id, s.share_minor FROM shares s JOIN expenses e ON e.id = s.expense_id WHERE e.trip_id = ?', [$id]) as $s) $shares[(int) $s['expense_id']][] = (int) $s['member_id'];
    $er = '';
    foreach ($exp as $e) {
        $del = $e['deleted_at'] !== null;
        $er .= '<tr' . ($del ? ' class="deleted"' : '') . '><td class="num">' . h($e['spent_on']) . '</td><td>' . h($e['description']) . ($del ? ' <span class="tag">已刪除</span>' : '') . '</td>'
            . '<td class="num right">' . admin_money((int) $e['amount_minor'], $e['currency']) . ' ' . h($e['currency']) . '</td>'
            . '<td class="num right">' . admin_money((int) $e['base_minor'], $B) . '</td>'
            . '<td class="num">' . ($e['currency'] === $B ? '—' : h(format_rate((int) $e['rate_e8']))) . '</td>'
            . '<td>' . $name((int) $e['payer_id']) . '</td>'
            . '<td>' . implode('、', array_map($name, $shares[(int) $e['id']] ?? [])) . '</td></tr>';
    }

    // 操作紀錄（不含 token）
    $logs = q($pdo, 'SELECT action, payload_json, at FROM audit_log WHERE trip_id = ? ORDER BY id DESC LIMIT 300', [$id])->fetchAll();
    $lr = '';
    foreach ($logs as $l) {
        $p = $l['payload_json'];
        if (mb_strlen($p) > 240) $p = mb_substr($p, 0, 240) . '…';
        $lr .= '<tr><td class="num">' . admin_time($l['at']) . '</td><td><code>' . h($l['action']) . '</code></td><td class="payload">' . h($p) . '</td></tr>';
    }

    $body = admin_header($csrf, $t['name'])
        . '<section class="card"><div class="cardhead"><h2>' . h($t['name']) . '</h2><span class="muted">#' . $id . ' · ' . h($B)
        . ' · 版本 ' . (int) $t['version'] . ' · 建立 ' . admin_time($t['created_at']) . ' · 最後活動 ' . admin_time($t['last_active_at']) . '</span></div>'
        . '<h3>連結</h3><p class="hint">現有連結無法顯示（只存雜湊）。重發後舊連結即時失效，請把新連結轉交給同行的人。</p>'
        . "<form method=\"post\" action=\"{$self}\" class=\"row wrap\">{$f}"
        . '<button class="btn ghost" name="action" value="rotate_edit" data-confirm="重發編輯連結？舊的編輯連結會即時失效。">重發編輯連結</button>'
        . '<button class="btn ghost" name="action" value="rotate_view" data-confirm="重發唯讀連結？舊的唯讀連結會即時失效。">重發唯讀連結</button>'
        . '<button class="btn ghost" name="action" value="rotate_both" data-confirm="兩條連結都重發？舊連結全部即時失效。">兩條都重發</button></form></section>'
        . '<section class="card"><div class="cardhead"><h2>每人淨額</h2><span class="muted">總支出 ' . admin_money($st['total_minor'], $B) . ' ' . h($B) . '</span></div>'
        . ($st['expenses'] ? $net : '<p class="empty">尚未有使費。</p>')
        . '<h3>過數建議</h3>' . ($moves !== '' ? $moves : '<p class="empty">無需過數。</p>') . '</section>'
        . '<section class="card"><div class="cardhead"><h2>使費紀錄</h2><span class="muted">' . count($exp) . ' 筆（含已刪除）</span></div>'
        . '<div class="tablewrap"><table><thead><tr><th>日期</th><th>項目</th><th class="right">金額</th><th class="right">折合 ' . h($B) . '</th><th>匯率</th><th>付款人</th><th>分攤人</th></tr></thead><tbody>'
        . ($er !== '' ? $er : '<tr><td colspan="7" class="empty">—</td></tr>') . '</tbody></table></div></section>'
        . '<section class="card"><div class="cardhead"><h2>操作紀錄</h2><span class="muted">最近 ' . count($logs) . ' 筆</span></div>'
        . '<div class="tablewrap"><table><thead><tr><th>時間</th><th>操作</th><th>內容</th></tr></thead><tbody>' . $lr . '</tbody></table></div></section>'
        . '<section class="card danger-zone"><h2>刪除行程</h2><p>會永久刪除此行程的所有成員、使費、紀錄。刪除前會自動備份整個資料庫。</p>'
        . "<form method=\"post\" action=\"{$self}\">{$f}<label class=\"lbl\" for=\"confirm\">請輸入行程名稱「" . h($t['name']) . "」確認</label>"
        . '<input type="text" id="confirm" name="confirm" autocomplete="off" required>'
        . '<div class="mt"><button class="btn danger" name="action" value="delete">永久刪除</button></div></form></section>';
    admin_page($t['name'], $body);
}

/** 重發：新 token 只出現在這一個回應 */
function admin_rotate(PDO $pdo, int $id, string $which, string $csrf): never
{
    $t = q($pdo, 'SELECT id, name FROM trips WHERE id = ?', [$id])->fetch();
    if (!$t) admin_page('找不到', admin_header($csrf) . '<div class="banner err"><span>找不到此行程。</span></div>', 404);
    $new = [];
    db_tx($pdo, function () use ($pdo, $id, $which, &$new) {
        foreach (($which === 'both' ? ['edit', 'view'] : [$which]) as $k) {
            $tok = token_new();
            q($pdo, "UPDATE trips SET {$k}_token_hash = ? WHERE id = ?", [token_hash($tok), $id]);
            $new[$k] = $tok;
        }
        trip_touch($pdo, $id, 'admin.rotate', ['links' => array_keys($new), 'ip_hash' => substr(ip_hash(), 0, 12)]);
    });
    error_log('[split-exp] admin rotated ' . implode('+', array_keys($new)) . " link(s) of trip #{$id}");
    $base = admin_base();
    $box = '';
    foreach ($new as $k => $tok) {
        $url = $base . 't/' . $tok;
        $box .= '<div class="linkbox"><div class="linkhead"><strong>新的' . ($k === 'edit' ? '編輯' : '唯讀') . '連結</strong>'
            . '<button type="button" class="btn ghost sm" data-copy="' . h($url) . '">複製</button></div>'
            . '<input type="text" readonly class="linkinput num" value="' . h($url) . '" data-select>'
            . '<div class="qrwrap" data-qr="' . h($url) . '"></div></div>';
    }
    $self = h(admin_self());
    $body = admin_header($csrf, $t['name'])
        . '<section class="card"><h2>已重發連結：' . h($t['name']) . '</h2>'
        . '<div class="banner warn"><span>這些連結只會顯示這一次，離開此頁後無法再看到。舊連結已即時失效。</span></div>'
        . $box . "<a class=\"btn\" href=\"{$self}?trip={$id}\">返回行程</a></section>";
    admin_page('已重發連結', $body);
}

function admin_delete(PDO $pdo, int $id, string $confirm, string $csrf): never
{
    $t = q($pdo, 'SELECT id, name FROM trips WHERE id = ?', [$id])->fetch();
    if (!$t) admin_list_page($pdo, $csrf, '找不到此行程，可能已被刪除。');
    if (trim($confirm) !== $t['name']) {
        admin_page('未刪除', admin_header($csrf, $t['name']) . '<div class="banner err"><span>輸入的名稱與行程名稱不符，未有刪除。</span></div>'
            . '<a class="btn" href="' . h(admin_self()) . '?trip=' . $id . '">返回</a>', 422);
    }
    $bk = db_backup($pdo, config()['backup_dir']);
    trip_purge($pdo, $id);
    error_log("[split-exp] admin deleted trip #{$id} (backup " . basename($bk) . ')');
    admin_list_page($pdo, $csrf, '已刪除「' . $t['name'] . '」。刪除前的備份：' . basename($bk));
}

// ---------------------------------------------------------------- 入口

function admin_run(): void
{
    try {
        $me = auth_require('split');           // 由 auth 程式庫決定：未登入 → 登入頁；冇權限 → 403
        $pdo = db_open(config()['db'], true);
        $csrf = auth_csrf_token();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            if (!auth_csrf_check($_POST['csrf'] ?? null)) admin_list_page($pdo, $csrf, '表單已過期，請重新整理再試。');
            $trip = (int) ($_POST['trip'] ?? 0);
            auth_log($me['id'], 'split', $action, "trip #$trip");
            switch ($action) {
                case 'rotate_edit': admin_rotate($pdo, $trip, 'edit', $csrf);
                case 'rotate_view': admin_rotate($pdo, $trip, 'view', $csrf);
                case 'rotate_both': admin_rotate($pdo, $trip, 'both', $csrf);
                case 'delete': admin_delete($pdo, $trip, (string) ($_POST['confirm'] ?? ''), $csrf);
            }
            admin_list_page($pdo, $csrf, '不支援的操作。');
        }

        if (isset($_GET['trip'])) admin_trip_page($pdo, (int) $_GET['trip'], $csrf);
        admin_list_page($pdo, $csrf);
    } catch (Throwable $e) {
        error_log('[split-exp] admin ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '伺服器發生錯誤。';
    }
}
