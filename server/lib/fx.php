<?php
declare(strict_types=1);
/**
 * 今日匯率：伺服器向 Google Finance 取得（非官方，頁面格式可能改變），每對貨幣快取 1 小時。
 * 失敗時呼叫方應退回行程匯率，不影響記帳。
 */
require_once __DIR__ . '/store.php';

const FX_TTL = 3600;
const FX_DAILY_CAP = 300;
const FX_SOURCE = 'Google Finance';

function fx_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS fx_cache (
        pair TEXT PRIMARY KEY,
        rate_e8 INTEGER NOT NULL,
        quoted_at INTEGER NOT NULL,
        fetched_at INTEGER NOT NULL
    )');
}

/** "4.97240316E-2" / "0.0497240316" → rate_e8（四捨五入至 8 位小數），不用浮點 */
function fx_rate_e8_from_string(string $s): ?int
{
    if (!preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d{1,3}))?$/', $s, $m)) return null;
    $digits = $m[1] . ($m[2] ?? '');
    $exp = (int) ($m[3] ?? 0) - strlen($m[2] ?? '') + 8;   // 值 × 10^8 = digits × 10^exp
    $digits = ltrim($digits, '0') ?: '0';
    if ($exp >= 0) {
        $v = bcmul($digits, bcpow('10', (string) $exp, 0), 0);
    } else {
        $v = bc_div_round_half_up($digits, bcpow('10', (string) -$exp, 0));
    }
    if (bccomp($v, '0', 0) <= 0 || bccomp($v, (string) MAX_RATE_E8, 0) > 0) return null;
    return (int) $v;
}

/** 由 Google Finance 報價頁取出現價與報價時間 */
function fx_fetch_google(string $from, string $to): ?array
{
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36\r\nAccept-Language: en-US,en;q=0.9\r\n",
    ]]);
    $html = @file_get_contents("https://www.google.com/finance/quote/{$from}-{$to}?hl=en", false, $ctx);
    $hdr = http_get_last_response_headers() ?? [];
    if ($html === false || !isset($hdr[0]) || !preg_match('#^HTTP/\S+ 200#', $hdr[0])) return null;
    $label = preg_quote("\"{$from} / {$to}\"", '/');
    // 例：["/g/…",null,"JPY / HKD",3,null,[0.0497240316,0,0,4,4,2],null,0.0497240316,null,null,null,[1791105679],…
    if (!preg_match('/' . $label . ',\d+,null,\[([0-9.]+(?:[eE][+-]?\d+)?),[^\]]*\],null,[^,]+,null,null,null,\[(\d{9,11})\]/', $html, $m)) return null;
    $e8 = fx_rate_e8_from_string($m[1]);
    if ($e8 === null) return null;
    return ['rate_e8' => $e8, 'quoted_at' => (int) $m[2]];
}

/**
 * @return array{rates: array, errors: array}
 */
function fx_today(PDO $pdo, string $base, array $currencies): array
{
    fx_ensure_table($pdo);
    $rates = [];
    $errors = [];
    $now = time();
    foreach ($currencies as $cur) {
        if ($cur === $base) continue;
        $pair = "{$cur}-{$base}";
        $row = q($pdo, 'SELECT rate_e8, quoted_at, fetched_at FROM fx_cache WHERE pair = ?', [$pair])->fetch();
        if (!$row || $now - (int) $row['fetched_at'] > FX_TTL) {
            // 全站每日上限，避免被當作濫用而遭 Google 封鎖
            if (rate_limit_hit($pdo, 'fx-google', FX_DAILY_CAP, 86400) === 0) {
                $got = fx_fetch_google($cur, $base);
                if ($got) {
                    q($pdo, 'INSERT INTO fx_cache (pair, rate_e8, quoted_at, fetched_at) VALUES (?, ?, ?, ?)
                             ON CONFLICT(pair) DO UPDATE SET rate_e8 = excluded.rate_e8, quoted_at = excluded.quoted_at, fetched_at = excluded.fetched_at',
                        [$pair, $got['rate_e8'], $got['quoted_at'], $now]);
                    $row = ['rate_e8' => $got['rate_e8'], 'quoted_at' => $got['quoted_at'], 'fetched_at' => $now];
                } else {
                    error_log("[split-exp] fx: Google Finance parse/fetch failed for {$pair}");
                }
            }
        }
        // 取不到新報價時，24 小時內的舊快取仍可用
        if ($row && $now - (int) $row['fetched_at'] <= 86400) {
            $rates[$cur] = [
                'rate' => format_rate((int) $row['rate_e8']),
                'rate_e8' => (int) $row['rate_e8'],
                'quoted_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['quoted_at']),
                'source' => FX_SOURCE,
            ];
        } else {
            $errors[$cur] = '未能取得今日匯率';
        }
    }
    return ['rates' => $rates, 'errors' => $errors];
}
