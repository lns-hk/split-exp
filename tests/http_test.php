<?php
/**
 * HTTP 驗收測試（項目 7–10、12–16）：
 *   php tests/http_test.php https://example.com/split-exp-staging [--resolve=example.com:443:203.0.113.10] [--skip-create-limit]
 *   Run it against a STAGING deployment.
 * 注意：會建立 5 個測試行程並觸發「建立行程」限流（同一 IP 一小時內不能再建立）。
 */
declare(strict_types=1);

if (empty($argv[1]) || str_starts_with($argv[1], '--')) { fwrite(STDERR, "usage: php tests/http_test.php <base-url> [--resolve=host:443:ip] [--skip-create-limit]\n"); exit(2); }
$base = rtrim($argv[1], '/');
$resolve = null;
$skipCreateLimit = false;
foreach ($argv as $a) {
    if (str_starts_with($a, '--resolve=')) $resolve = substr($a, 10);
    if ($a === '--skip-create-limit') $skipCreateLimit = true;
}
$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? 'PASS' : 'FAIL'), "  {$name}", $detail !== '' ? "  — {$detail}" : '', "\n";
}

function req_handle(string $method, string $url, array $headers = [], $body = null, bool $pathAsIs = false)
{
    global $resolve;
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = "{$k}: {$v}";
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, [$resolve]);
    if ($pathAsIs) curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
    return $ch;
}
function parse_resp($ch, string $raw): array
{
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hs);
    $bodyStr = substr($raw, $hs);
    $hdr = [];
    foreach (explode("\r\n", $head) as $line) if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
    return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $hdr, 'body' => json_decode($bodyStr, true), 'raw' => $bodyStr, 'time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME)];
}
function req(string $method, string $path, array $headers = [], $body = null, bool $pathAsIs = false): array
{
    global $base;
    $ch = req_handle($method, str_starts_with($path, 'http') ? $path : $base . $path, $headers, $body, $pathAsIs);
    $raw = curl_exec($ch);
    return parse_resp($ch, (string) $raw);
}
/** 兩個請求同時送出 */
function parallel(array $reqs): array
{
    global $base;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($reqs as $r) { $ch = req_handle($r[0], $base . $r[1], $r[2] ?? [], $r[3] ?? null); curl_multi_add_handle($mh, $ch); $hs[] = $ch; }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $ch) { $out[] = parse_resp($ch, (string) curl_multi_getcontent($ch)); curl_multi_remove_handle($mh, $ch); }
    return $out;
}
$tok = fn(string $t) => ['X-Trip-Token' => $t];
function mk(string $name, array $members): array
{
    $r = req('POST', '/api/trips', [], ['name' => $name, 'base_currency' => 'HKD', 'members' => $members]);
    if ($r['status'] !== 201) { fwrite(STDERR, "create failed: {$r['status']} {$r['raw']}\n"); exit(2); }
    return $r['body'];
}

echo "target: {$base}\n";
$A = mk('測試 A', ['甲', '乙', '丙']);
$B = mk('測試 B', ['丁', '戊']);

// 7. A 的 token 無法讀取 B（只能讀到 A 自己）；B 的成員不能用於 A
$ra = req('GET', '/api/trip', $tok($A['edit_token']));
$rb = req('GET', '/api/trip', $tok($B['edit_token']));
$bMember = $rb['body']['members'][0]['id'];
$aMember = $ra['body']['members'][0]['id'];
$cross = req('PATCH', '/api/members/' . $bMember, $tok($A['edit_token']), ['display_name' => '入侵']);
$crossExp = req('POST', '/api/expenses', $tok($A['edit_token']), ['amount' => '10', 'currency' => 'HKD', 'payer_id' => $bMember, 'sharers' => [$bMember]]);
check('7 trip isolation', $ra['body']['trip']['id'] === $A['trip_id'] && $rb['body']['trip']['id'] === $B['trip_id'] && $cross['status'] === 404 && $crossExp['status'] === 422,
    "A token → trip #{$ra['body']['trip']['id']}; PATCH B's member with A token → {$cross['status']}; B's member as payer in A → {$crossExp['status']}");
$fake = substr($A['edit_token'], 0, 21) . ($A['edit_token'][21] === 'a' ? 'b' : 'a');
$rf = req('GET', '/api/trip', $tok($fake));
check('7b tampered token → 404', $rf['status'] === 404, "status {$rf['status']} body " . $rf['raw']);

// 8. 唯讀 token 寫入 → 403
$w = req('POST', '/api/expenses', $tok($A['view_token']), ['amount' => '10', 'currency' => 'HKD', 'payer_id' => $aMember, 'sharers' => [$aMember]]);
$w2 = req('PATCH', '/api/trip', $tok($A['view_token']), ['name' => 'x']);
$rv = req('GET', '/api/trip', $tok($A['view_token']));
check('8 view token cannot write', $w['status'] === 403 && $w2['status'] === 403 && $rv['status'] === 200 && $rv['body']['trip']['mode'] === 'view', "POST {$w['status']}, PATCH {$w2['status']}, GET {$rv['status']} mode={$rv['body']['trip']['mode']}");

// 9. 不存在 token → 404，回應時間無明顯差異
$tE = $tN = [];
for ($i = 0; $i < 25; $i++) {
    $x = req('GET', '/api/trip', $tok($A['view_token']) + ['X-If-None-Match' => $rv['headers']['etag']]);
    $tE[] = $x['time'];
    $y = req('GET', '/api/trip', $tok(str_repeat('Z', 22)));
    $tN[] = $y['time'];
}
sort($tE); sort($tN);
$medE = $tE[12] * 1000; $medN = $tN[12] * 1000;
check('9 unknown token 404, similar timing', $y['status'] === 404 && abs($medE - $medN) < 25,
    sprintf('404 body %s; median existing(304) %.1f ms vs unknown %.1f ms', $y['raw'], $medE, $medN));

// 10. 資料庫無法經網址下載
$host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
$probes = [
    $host . '/data/app.sqlite', $host . '/data/config.php', $base . '/data/app.sqlite', $base . '/../data/app.sqlite',
    $base . '/api/../../data/app.sqlite', $base . '/api/index.php/../../../data/app.sqlite', $base . '/app.sqlite',
    $base . '/.htaccess', $base . '/api/.htaccess', $base . '/%2e%2e/data/app.sqlite', $base . '/server/lib/api.php',
];
$leak = [];
$codes = [];
foreach ($probes as $p) {
    $r = req('GET', $p, [], null, true);
    $codes[] = $r['status'];
    if ($r['status'] === 200 && (str_starts_with($r['raw'], 'SQLite format') || str_contains($r['raw'], 'RewriteEngine') || str_contains($r['raw'], 'ip_salt'))) $leak[] = $p;
}
check('10 db not downloadable', !$leak, 'statuses ' . implode(',', $codes) . ($leak ? ' LEAK: ' . implode(' ', $leak) : ''));

// 12. 兩個瀏覽器同時新增使費，兩筆皆保留
$ids = array_column($ra['body']['members'], 'id');
$before = count($ra['body']['expenses']);
$p = parallel([
    ['POST', '/api/expenses', $tok($A['edit_token']), ['description' => '並發一', 'amount' => '100', 'currency' => 'HKD', 'payer_id' => $ids[0], 'sharers' => $ids]],
    ['POST', '/api/expenses', $tok($A['edit_token']), ['description' => '並發二', 'amount' => '200', 'currency' => 'HKD', 'payer_id' => $ids[1], 'sharers' => $ids]],
]);
$after = req('GET', '/api/trip', $tok($A['edit_token']));
$descs = array_column($after['body']['expenses'], 'description');
check('12 concurrent adds both kept', $p[0]['status'] === 201 && $p[1]['status'] === 201 && in_array('並發一', $descs, true) && in_array('並發二', $descs, true) && count($descs) === $before + 2,
    "statuses {$p[0]['status']},{$p[1]['status']}; expenses {$before} → " . count($descs));

// 13. 兩個瀏覽器同時修改同一筆，後到者 409
$e = $after['body']['expenses'][0];
$p = parallel([
    ['PATCH', '/api/expenses/' . $e['id'], $tok($A['edit_token']) + ['If-Match' => (string) $e['version']], ['description' => '甲改的']],
    ['PATCH', '/api/expenses/' . $e['id'], $tok($A['edit_token']) + ['If-Match' => (string) $e['version']], ['description' => '乙改的']],
]);
$st = array_column($p, 'status');
sort($st);
$loser = $p[0]['status'] === 409 ? $p[0] : $p[1];
check('13 concurrent edit → one 409', $st === [200, 409] && isset($loser['body']['current']['version']) && $loser['body']['current']['version'] === $e['version'] + 1,
    'statuses ' . implode(',', array_column($p, 'status')) . '; 409 carries current version ' . ($loser['body']['current']['version'] ?? '—') . " ({$loser['body']['current']['description']})");
$noIf = req('PATCH', '/api/expenses/' . $e['id'], $tok($A['edit_token']), ['description' => '冇版本']);
check('13b PATCH without If-Match rejected', $noIf['status'] === 428, "status {$noIf['status']}");

// 14. 數據未變時輪詢 304
$g1 = req('GET', '/api/trip', $tok($A['edit_token']));
$g2 = req('GET', '/api/trip', $tok($A['edit_token']) + ['If-None-Match' => $g1['headers']['etag'] ?? '', 'X-If-None-Match' => $g1['headers']['etag'] ?? '']);
$g2std = req('GET', '/api/trip', $tok($A['edit_token']) + ['If-None-Match' => $g1['headers']['etag'] ?? '']);
echo '      (standard If-None-Match alone → ', $g2std['status'], $g2std['status'] === 304 ? '' : ' — stripped by the edge proxy', ")\n";
req('PATCH', '/api/trip', $tok($A['edit_token']), ['name' => '測試 A2']);
$g3 = req('GET', '/api/trip', $tok($A['edit_token']) + ['X-If-None-Match' => $g1['headers']['etag'] ?? '']);
check('14 unchanged poll → 304', $g2['status'] === 304 && $g2['raw'] === '' && $g3['status'] === 200, "etag {$g1['headers']['etag']}: unchanged {$g2['status']}, after a write {$g3['status']} etag {$g3['headers']['etag']}");

// 16. 第 51 位成員 → 422
$C = mk('測試 C', array_map(fn($i) => "成員{$i}", range(1, 50)));
$m51 = req('POST', '/api/members', $tok($C['edit_token']), ['display_name' => '第五十一']);
check('16 51st member → 422', $m51['status'] === 422, "status {$m51['status']} {$m51['body']['error']}: {$m51['body']['message']}");

// 其他：基準貨幣鎖定、錯誤格式
$lock = req('PATCH', '/api/trip', $tok($A['edit_token']), ['base_currency' => 'JPY']);
check('x base currency locked after expenses → 409', $lock['status'] === 409, "{$lock['body']['error']}: {$lock['body']['message']}");
$bad = req('POST', '/api/expenses', $tok($A['edit_token']), ['amount' => '4800.5', 'currency' => 'JPY', 'payer_id' => $ids[0], 'sharers' => $ids]);
check('x error has only code + message', $bad['status'] === 422 && array_keys($bad['body']) === ['error', 'message'] && !preg_match('#/volume|\.php|Stack#', $bad['raw']), $bad['raw']);
$ct = req('GET', '/api/trip', $tok($A['edit_token']));
check('x API headers', str_starts_with($ct['headers']['content-type'] ?? '', 'application/json; charset=utf-8') && ($ct['headers']['cache-control'] ?? '') === 'no-store', ($ct['headers']['content-type'] ?? '') . ' / ' . ($ct['headers']['cache-control'] ?? ''));

// 15. 同一 IP 第六次建立行程 → 429（已建立 A、B、C 三個）
if (!$skipCreateLimit) {
    $codes = [];
    for ($i = 4; $i <= 6; $i++) {
        $r = req('POST', '/api/trips', [], ['name' => "限流 {$i}", 'base_currency' => 'HKD', 'members' => ['甲']]);
        $codes[] = $r['status'];
    }
    check('15 6th trip in an hour → 429', $codes === [201, 201, 429] && isset($r['headers']['retry-after']), 'creates #4–#6: ' . implode(',', $codes) . '; Retry-After ' . ($r['headers']['retry-after'] ?? '—'));
}

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
