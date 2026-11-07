<?php
declare(strict_types=1);
/**
 * 金額運算：全程整數／bcmath，不得使用浮點數。
 * 所有金額以「最小單位」整數儲存（HKD 1 = 100，JPY 1 = 1）。
 * 匯率 rate_e8 = 1 單位外幣等於多少基準貨幣 × 10^8。
 */

const CURRENCY_DP = [
    'HKD' => 2, 'JPY' => 0, 'KRW' => 0, 'TWD' => 2, 'CNY' => 2, 'USD' => 2, 'EUR' => 2,
    'GBP' => 2, 'SGD' => 2, 'MYR' => 2, 'THB' => 2, 'AUD' => 2, 'VND' => 0,
    'MOP' => 2, 'NZD' => 2, 'CAD' => 2, 'CHF' => 2, 'PHP' => 2, 'IDR' => 2, 'INR' => 2,
];
const RATE_SCALE = 100000000;            // 10^8
const MAX_AMOUNT_MINOR = 1000000000000;  // 10^12
const MAX_RATE_E8 = 100000000000000;     // 1 單位外幣最多值 10^6 基準貨幣
const MAX_BASE_MINOR = 1000000000000000; // 換算後上限 10^15，令 3000 筆加總亦不會超出 64-bit

function currency_dp(string $cur): int
{
    if (!isset(CURRENCY_DP[$cur])) throw new InvalidArgumentException('unsupported currency');
    return CURRENCY_DP[$cur];
}

function is_currency(mixed $cur): bool
{
    return is_string($cur) && isset(CURRENCY_DP[$cur]);
}

/** "-12.5" + dp 2 → -1250；格式不符或小數位超過 dp 時回傳 null */
function parse_decimal(mixed $s, int $dp, bool $allowNegative = true): ?int
{
    if (is_int($s)) $s = (string) $s;
    if (!is_string($s)) return null;
    $s = trim(str_replace(',', '', $s));
    if (!preg_match('/^(-?)(\d{1,15})(?:\.(\d+))?$/', $s, $m)) return null;
    if ($m[1] === '-' && !$allowNegative) return null;
    $frac = $m[3] ?? '';
    $frac = rtrim($frac, '0');
    if (strlen($frac) > $dp) return null;
    $digits = ltrim($m[2] . str_pad($frac, $dp, '0'), '0');
    if ($digits === '') return 0;
    if (strlen($digits) > 18) return null;
    $v = (int) $digits;
    return $m[1] === '-' ? -$v : $v;
}

/** 整數最小單位 → 十進制字串，例如 -1250, 2 → "-12.50" */
function format_minor(int $v, int $dp): string
{
    $neg = $v < 0;
    $s = (string) abs($v);
    if ($dp > 0) {
        $s = str_pad($s, $dp + 1, '0', STR_PAD_LEFT);
        $s = substr($s, 0, -$dp) . '.' . substr($s, -$dp);
    }
    return ($neg ? '-' : '') . $s;
}

/** 匯率字串 → rate_e8；必須為正數、最多 8 位小數 */
function parse_rate(mixed $s): ?int
{
    if (is_int($s)) $s = (string) $s;
    if (!is_string($s)) return null;
    $v = parse_decimal($s, 8, false);
    if ($v === null || $v <= 0 || $v > MAX_RATE_E8) return null;
    return $v;
}

function format_rate(int $rateE8): string
{
    $s = format_minor($rateE8, 8);
    return rtrim(rtrim($s, '0'), '.');
}

/** 正整數字串除法，四捨五入（.5 向絕對值較大方向）。 */
function bc_div_round_half_up(string $num, string $den): string
{
    $neg = str_starts_with($num, '-');
    $abs = $neg ? substr($num, 1) : $num;
    $q = bcdiv($abs, $den, 0);
    $r = bcmod($abs, $den, 0);
    if (bccomp(bcmul($r, '2', 0), $den, 0) >= 0) $q = bcadd($q, '1', 0);
    return ($neg && $q !== '0') ? '-' . $q : $q;
}

/**
 * base_minor = round_half_up(amount_minor × rate_e8 × 10^dp_base / (10^8 × 10^dp_src))
 * 負數（贏得的錢）以對稱方式四捨五入。
 */
function convert_to_base(int $amountMinor, int $rateE8, int $dpSrc, int $dpBase): int
{
    $num = bcmul(bcmul((string) $amountMinor, (string) $rateE8, 0), bcpow('10', (string) $dpBase, 0), 0);
    $den = bcmul((string) RATE_SCALE, bcpow('10', (string) $dpSrc, 0), 0);
    $q = bc_div_round_half_up($num, $den);
    if (bccomp(ltrim($q, '-'), (string) MAX_BASE_MINOR, 0) > 0) throw new RangeException('converted amount too large');
    return (int) $q;
}

/**
 * 最大餘數法分攤。
 * @param int   $total   base_minor（可為負數：先以絕對值分攤，再加回負號）
 * @param array $weights [member_id => weight]（weight ≥ 1）
 * @param array $order   [member_id => sort_order]，小數部分相同時 sort_order 小者先得
 * @return array [member_id => share_minor]，加總必定等於 $total
 */
function allocate_shares(int $total, array $weights, array $order): array
{
    if (!$weights) throw new InvalidArgumentException('no sharers');
    $sign = $total < 0 ? -1 : 1;
    $abs = (string) abs($total);
    $W = '0';
    foreach ($weights as $w) {
        if (!is_int($w) || $w < 1) throw new InvalidArgumentException('bad weight');
        $W = bcadd($W, (string) $w, 0);
    }
    $shares = [];
    $rems = [];
    $given = '0';
    foreach ($weights as $id => $w) {
        $p = bcmul($abs, (string) $w, 0);
        $floor = bcdiv($p, $W, 0);
        $shares[$id] = $floor;
        $rems[$id] = bcmod($p, $W, 0);   // 小數部分 × W，可直接比較
        $given = bcadd($given, $floor, 0);
    }
    $left = (int) bcsub($abs, $given, 0);
    $ids = array_keys($weights);
    usort($ids, function ($a, $b) use ($rems, $order) {
        $c = bccomp($rems[$b], $rems[$a], 0);
        if ($c !== 0) return $c;
        return ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: $a <=> $b;
    });
    for ($i = 0; $i < $left; $i++) {
        $id = $ids[$i];
        $shares[$id] = bcadd($shares[$id], '1', 0);
    }
    $out = [];
    $sum = 0;
    foreach ($shares as $id => $s) {
        $out[$id] = $sign * (int) $s;
        $sum += $out[$id];
    }
    if ($sum !== $total) throw new LogicException('allocation mismatch');
    return $out;
}

/**
 * 每人淨額 = 已付 − 應付 + 已付出的還款 − 已收的還款
 * @param array $members     [{id, sort_order}]
 * @param array $expenses    [{payer_id, base_minor, shares: [member_id => share_minor]}]
 * @param array $settlements [{from_id, to_id, amount_minor}]
 * @return array [member_id => ['paid'=>, 'owed'=>, 'settled'=>, 'net'=>]]
 */
function compute_balances(array $members, array $expenses, array $settlements): array
{
    $b = [];
    foreach ($members as $m) $b[$m['id']] = ['paid' => 0, 'owed' => 0, 'settled' => 0, 'net' => 0];
    foreach ($expenses as $e) {
        $b[$e['payer_id']]['paid'] += $e['base_minor'];
        foreach ($e['shares'] as $mid => $s) $b[$mid]['owed'] += $s;
    }
    foreach ($settlements as $s) {
        $b[$s['from_id']]['settled'] += $s['amount_minor'];
        $b[$s['to_id']]['settled'] -= $s['amount_minor'];
    }
    foreach ($b as $id => $r) $b[$id]['net'] = $r['paid'] - $r['owed'] + $r['settled'];
    return $b;
}

/**
 * 過數建議：最大欠款人對最大應收人，直到全部歸零。
 * @param array $net   [member_id => net_minor]
 * @param array $order [member_id => sort_order]（金額相同時的次序）
 * @return array [{from_id, to_id, amount_minor}]
 */
function suggest_transfers(array $net, array $order): array
{
    $debt = [];
    $cred = [];
    foreach ($net as $id => $v) {
        if ($v < 0) $debt[$id] = -$v;
        elseif ($v > 0) $cred[$id] = $v;
    }
    $moves = [];
    $pick = function (array $set) use ($order) {
        $best = null;
        foreach ($set as $id => $v) {
            if ($best === null || $v > $set[$best] || ($v === $set[$best] && ($order[$id] ?? 0) < ($order[$best] ?? 0))) $best = $id;
        }
        return $best;
    };
    while ($debt && $cred) {
        $d = $pick($debt);
        $c = $pick($cred);
        $amt = min($debt[$d], $cred[$c]);
        $moves[] = ['from_id' => $d, 'to_id' => $c, 'amount_minor' => $amt];
        $debt[$d] -= $amt;
        $cred[$c] -= $amt;
        if ($debt[$d] === 0) unset($debt[$d]);
        if ($cred[$c] === 0) unset($cred[$c]);
    }
    return $moves;
}
