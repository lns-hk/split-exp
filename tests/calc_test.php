<?php
// 計算驗收測試（項目 1–6）：php tests/calc_test.php
declare(strict_types=1);
require __DIR__ . '/../server/lib/store.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? 'PASS' : 'FAIL'), "  {$name}", $detail !== '' ? "  — {$detail}" : '', "\n";
}
$fmt = fn(array $a, int $dp) => implode(', ', array_map(fn($v) => format_minor($v, $dp), array_values($a)));
$order3 = [1 => 1, 2 => 2, 3 => 3];
$order4 = [1 => 1, 2 => 2, 3 => 3, 4 => 4];

// 1. HKD 100.00 三人平均分
$s = allocate_shares(10000, [1 => 1, 2 => 1, 3 => 1], $order3);
check('1 HKD 100.00 / 3', array_values($s) === [3334, 3333, 3333] && array_sum($s) === 10000, $fmt($s, 2) . ' sum ' . format_minor(array_sum($s), 2));

// 2. JPY 4800 @ 0.0511 四人分
$b = convert_to_base(4800, parse_rate('0.0511'), 0, 2);
$s = allocate_shares($b, [1 => 1, 2 => 1, 3 => 1, 4 => 1], $order4);
check('2 JPY 4800 @0.0511 / 4', $b === 24528 && array_values($s) === [6132, 6132, 6132, 6132], format_minor($b, 2) . ' HKD → ' . $fmt($s, 2));

// 3. JPY 1000 @ 0.0511 三人分
$b = convert_to_base(1000, parse_rate('0.0511'), 0, 2);
$s = allocate_shares($b, [1 => 1, 2 => 1, 3 => 1], $order3);
check('3 JPY 1000 @0.0511 / 3', $b === 5110 && array_values($s) === [1704, 1703, 1703], format_minor($b, 2) . ' HKD → ' . $fmt($s, 2));

// 4. JPY 1 @ 0.0511
$b = convert_to_base(1, parse_rate('0.0511'), 0, 2);
check('4 JPY 1 @0.0511', $b === 5, format_minor($b, 2) . ' HKD');

// 額外：四捨五入與負數對稱
check('x round half up', convert_to_base(1, parse_rate('0.055'), 0, 2) === 6 && convert_to_base(1, parse_rate('0.0549'), 0, 2) === 5, '1 JPY @0.055 = ' . format_minor(convert_to_base(1, parse_rate('0.055'), 0, 2), 2));
check('x negative symmetric', convert_to_base(-1000, parse_rate('0.0511'), 0, 2) === -5110 && array_values(allocate_shares(-5110, [1 => 1, 2 => 1, 3 => 1], $order3)) === [-1704, -1703, -1703]);
check('x no float in parse', parse_decimal('0.1', 2) === 10 && parse_decimal('1.005', 2) === null && parse_decimal('4800', 0) === 4800 && parse_decimal('48.5', 0) === null);
check('x big amount', convert_to_base(MAX_AMOUNT_MINOR, parse_rate('0.0511'), 0, 2) === 5110000000000, 'JPY 10^12 → ' . format_minor(convert_to_base(MAX_AMOUNT_MINOR, parse_rate('0.0511'), 0, 2), 2));
try { convert_to_base(MAX_AMOUNT_MINOR, MAX_RATE_E8, 0, 2); check('x overflow guarded', false); } catch (RangeException) { check('x overflow guarded', true, '10^12 × 10^6 rejected'); }

// 5. 新增使費後修改行程匯率，舊使費 base_minor 不變
$db = sys_get_temp_dir() . '/split-calc-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = db_open($db);
$t = trip_create($pdo, '測試', 'HKD', ['A', 'B', 'C']);
rates_put($pdo, $t['id'], ['JPY' => '0.0511']);
$ids = array_column(trip_members($pdo, $t['id']), 'id');
$eid = expense_create($pdo, $t['id'], ['amount' => '1000', 'currency' => 'JPY', 'payer_id' => $ids[0], 'sharers' => $ids, 'spent_on' => '2026-10-04']);
$before = (int) q($pdo, 'SELECT base_minor FROM expenses WHERE id = ?', [$eid])->fetchColumn();
rates_put($pdo, $t['id'], ['JPY' => '0.0600']);
$after = (int) q($pdo, 'SELECT base_minor FROM expenses WHERE id = ?', [$eid])->fetchColumn();
$eid2 = expense_create($pdo, $t['id'], ['amount' => '1000', 'currency' => 'JPY', 'payer_id' => $ids[0], 'sharers' => $ids, 'spent_on' => '2026-10-04']);
$new = (int) q($pdo, 'SELECT base_minor FROM expenses WHERE id = ?', [$eid2])->fetchColumn();
// 修改舊使費的描述（不帶匯率）也要保留其快照
expense_patch($pdo, $t['id'], $eid, ['description' => '改名'], 1);
$afterEdit = (int) q($pdo, 'SELECT base_minor FROM expenses WHERE id = ?', [$eid])->fetchColumn();
check('5 rate change keeps old base_minor', $before === 5110 && $after === 5110 && $afterEdit === 5110 && $new === 6000,
    "old {$before} → after rate change {$after} → after edit {$afterEdit}; new expense {$new}");

// 6. 隨機 1000 筆
mt_srand(20261004);
$t2 = trip_create($pdo, '隨機', 'HKD', array_map(fn($i) => "M{$i}", range(1, 12)));
rates_put($pdo, $t2['id'], ['JPY' => '0.0511', 'KRW' => '0.0058', 'TWD' => '0.2431', 'VND' => '0.000306', 'USD' => '7.7812', 'THB' => '0.2175']);
$mids = array_map('intval', array_column(trip_members($pdo, $t2['id']), 'id'));
$curs = ['HKD', 'JPY', 'KRW', 'TWD', 'VND', 'USD', 'THB'];
for ($i = 0; $i < 1000; $i++) {
    $cur = $curs[mt_rand(0, 6)];
    $dp = CURRENCY_DP[$cur];
    $amt = mt_rand(1, 9_999_999) * (mt_rand(0, 19) === 0 ? -1 : 1);
    $sh = $mids;
    shuffle($sh);
    $sh = array_slice($sh, 0, mt_rand(1, count($mids)));
    $mode = mt_rand(0, 3) === 0 ? 'weight' : 'equal';
    $sharers = $mode === 'weight' ? array_map(fn($m) => ['member_id' => $m, 'weight' => mt_rand(1, 7)], $sh) : $sh;
    expense_create($pdo, $t2['id'], ['amount' => format_minor($amt, $dp), 'currency' => $cur, 'payer_id' => $mids[mt_rand(0, 11)], 'sharers' => $sharers, 'split_mode' => $mode, 'spent_on' => '2026-10-04']);
}
$bad = (int) $pdo->query("SELECT COUNT(*) FROM expenses e WHERE trip_id = {$t2['id']} AND base_minor <> (SELECT SUM(share_minor) FROM shares s WHERE s.expense_id = e.id)")->fetchColumn();
$n = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE trip_id = {$t2['id']}")->fetchColumn();
$state = trip_state($pdo, $t2['id'], 'edit');
$netSum = array_sum(array_column($state['balances'], 'net_minor'));
// 過數建議執行後全部歸零
$net = array_column($state['balances'], 'net_minor', 'member_id');
foreach ($state['suggestions'] as $m) { $net[$m['from_id']] += $m['amount_minor']; $net[$m['to_id']] -= $m['amount_minor']; }
$zero = count(array_filter($net)) === 0;
check('6 random 1000 expenses', $n === 1000 && $bad === 0 && $netSum === 0 && $zero,
    "{$n} expenses, {$bad} with share sum ≠ base_minor, net sum = {$netSum}, after " . count($state['suggestions']) . ' transfers all zero: ' . ($zero ? 'yes' : 'no'));

// 額外：配額
try { for ($i = 0; $i < 60; $i++) member_add($pdo, $t['id'], "X{$i}"); check('x member quota', false); }
catch (ApiError $e) { check('x member quota 51st → 422', $e->status === 422 && (int) q($pdo, 'SELECT COUNT(*) FROM members WHERE trip_id = ?', [$t['id']])->fetchColumn() === 50, $e->errCode); }

// 額外：token 格式
$tok = token_new();
check('x token base62 22', (bool) preg_match('/^[0-9A-Za-z]{22}$/', $tok), $tok[0] . str_repeat('*', 20) . $tok[21]);
check('x db stores hash only', !q($pdo, 'SELECT 1 FROM trips WHERE edit_token_hash = ? OR view_token_hash = ?', [$t['edit_token'], $t['view_token']])->fetchColumn()
    && trip_by_token($pdo, $t['edit_token'])['mode'] === 'edit' && trip_by_token($pdo, $t['view_token'])['mode'] === 'view' && trip_by_token($pdo, token_new()) === null);

$pdo = null;
foreach (['', '-wal', '-shm'] as $x) @unlink($db . $x);
echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
