<?php
// 匯率解析測試：php tests/fx_test.php [保存的 Google Finance HTML]
declare(strict_types=1);
require __DIR__ . '/../server/lib/fx.php';
$fails = 0;
function check(string $n, bool $ok, string $d = ''): void { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS' : 'FAIL'), "  {$n}", $d !== '' ? "  — {$d}" : '', "\n"; }
check('e8 plain', fx_rate_e8_from_string('0.0497240316') === 4972403);
check('e8 round half up', fx_rate_e8_from_string('0.000000125') === 13 && fx_rate_e8_from_string('0.000000124') === 12);
check('e8 scientific', fx_rate_e8_from_string('2.9535E-4') === 29535 && fx_rate_e8_from_string('7.7812E0') === 778120000);
check('e8 rejects junk', fx_rate_e8_from_string('-1') === null && fx_rate_e8_from_string('abc') === null && fx_rate_e8_from_string('0') === null);
if (isset($argv[1])) {
    $html = file_get_contents($argv[1]);
    $label = preg_quote('"JPY / HKD"', '/');
    $ok = preg_match('/' . $label . ',\d+,null,\[([0-9.]+(?:[eE][+-]?\d+)?),[^\]]*\],null,[^,]+,null,null,null,\[(\d{9,11})\]/', $html, $m);
    check('parse saved Google page', (bool) $ok, $ok ? "price {$m[1]} → " . format_rate(fx_rate_e8_from_string($m[1])) . ' @ ' . gmdate('c', (int) $m[2]) : 'no match');
}
echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
