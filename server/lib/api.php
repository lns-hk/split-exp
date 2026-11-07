<?php
declare(strict_types=1);
/**
 * HTTP 入口邏輯。公開目錄的 split-exp/api/index.php 只 require 本檔。
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/fx.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function config(): array
{
    static $c = null;
    if ($c === null) {
        $path = defined('SPLIT_EXP_CONFIG') ? SPLIT_EXP_CONFIG : dirname(__DIR__, 2) . '/data/config.php';
        $c = require $path;
        if (!empty($c['error_log'])) ini_set('error_log', $c['error_log']);
    }
    return $c;
}

function send_json(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $k => $v) header("{$k}: {$v}");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_error(int $status, string $code, string $message, array $extra = [], array $headers = []): never
{
    send_json($status, ['error' => $code, 'message' => $message] + $extra, $headers);
}

/** 真實用戶 IP：只在請求來自受信任的反向代理（config trusted_proxies）時才採用 X-Forwarded-For 的最後一項 */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = config()['trusted_proxies'] ?? [];
    if (in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($parts);
        if (filter_var($last, FILTER_VALIDATE_IP)) return $last;
    }
    return $remote;
}

function ip_hash(): string
{
    return hash('sha256', (config()['ip_salt'] ?? '') . '|' . client_ip());
}

function limit(PDO $pdo, string $kind): void
{
    [$max, $window] = match ($kind) {
        'create' => [5, 3600],
        'write' => [60, 60],
        'read' => [120, 60],
    };
    $wait = rate_limit_hit($pdo, $kind . ':' . ip_hash(), $max, $window);
    if ($wait > 0) send_error(429, 'rate_limited', '請求太頻繁，請稍後再試。', ['retry_after' => $wait], ['Retry-After' => (string) $wait]);
}

function read_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 65536) fail(413, 'body_too_large', '請求內容太大。');
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j) || (array_is_list($j) && $j)) fail(400, 'invalid_json', '請求內容必須是 JSON 物件。');
    return $j;
}

function if_match_version(): ?int
{
    $h = $_SERVER['HTTP_IF_MATCH'] ?? null;
    if ($h === null || $h === '') return null;
    $h = trim(str_replace(['W/', '"'], '', $h));
    if (!preg_match('/^\d{1,9}$/', $h)) fail(400, 'invalid_if_match', 'If-Match 必須是版本號。');
    return (int) $h;
}

const TURNSTILE_ACTION = 'create_trip';
const TURNSTILE_ACTION_ADMIN = 'admin_login';

/** Secret 優先取 config.php，否則讀 data/turnstile_secret.php（PHP 檔，就算被直接存取也只會被執行、不會被下載） */
function turnstile_secret(): string
{
    $c = config();
    if (!empty($c['turnstile_secret'])) return (string) $c['turnstile_secret'];
    $f = $c['turnstile_secret_file'] ?? '';
    if ($f !== '' && is_file($f)) {
        $v = require $f;
        if (is_string($v)) return trim($v);
    }
    return '';
}

/** Canonical siteverify：success === true、action 相符、hostname 在允許清單內 */
function turnstile_verify(mixed $token, string $action = TURNSTILE_ACTION): void
{
    $secret = turnstile_secret();
    if ($secret === '') {
        error_log("[split-exp] WARNING: turnstile secret not configured; {$action} is not bot-protected");
        return;
    }
    $hosts = array_values(array_filter(array_map('trim', config()['turnstile_hostnames'] ?? [])));
    if (!is_string($token) || $token === '' || strlen($token) > 2048 || !$hosts) fail(403, 'turnstile_failed', '請先完成人機驗證。');
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => client_ip()]),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $hdr = http_get_last_response_headers() ?? [];
    $ok200 = isset($hdr[0]) && preg_match('#^HTTP/\S+ 200#', $hdr[0]);
    $j = ($res !== false && $ok200) ? json_decode($res, true) : null;
    if (!is_array($j)) {
        error_log('[split-exp] turnstile siteverify unreachable or non-200');
        fail(403, 'turnstile_unavailable', '暫時無法完成人機驗證，請稍後再試。');
    }
    if (($j['success'] ?? false) !== true || ($j['action'] ?? null) !== $action || !in_array($j['hostname'] ?? '', $hosts, true)) {
        fail(403, 'turnstile_failed', '人機驗證失敗，請重新驗證。');
    }
}

function public_urls(int $id, string $edit, string $view): array
{
    $base = rtrim(config()['base_url'] ?? '/', '/') . '/';
    return ['edit_url' => $base . 't/' . $edit, 'view_url' => $base . 't/' . $view];
}

/** 以 X-Trip-Token 取得行程；無效一律 404 */
function require_trip(PDO $pdo, bool $write): array
{
    $t = trip_by_token($pdo, $_SERVER['HTTP_X_TRIP_TOKEN'] ?? '');
    if (!$t) fail(404, 'not_found', '找不到此行程。');
    if ($write && $t['mode'] !== 'edit') fail(403, 'read_only', '這是唯讀連結，不能修改行程。');
    return $t;
}

function respond_state(PDO $pdo, int $tripId, string $mode, int $status = 200, array $extra = []): never
{
    $s = trip_state($pdo, $tripId, $mode);
    $etag = '"' . $tripId . '-' . $s['trip']['version'] . '-' . $mode . '"';
    send_json($status, $s + $extra, ['ETag' => $etag]);
}

function route(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos = strpos($uri, '/api/');
    $path = $pos === false ? '' : trim(substr($uri, $pos + 5), '/');

    $pdo = db_open(config()['db'], true);

    if ($path === 'config' && $method === 'GET') {
        limit($pdo, 'read');
        send_json(200, ['turnstile_sitekey' => config()['turnstile_sitekey'] ?? '', 'currencies' => CURRENCY_DP]);
    }

    if ($path === 'trips' && $method === 'POST') {
        limit($pdo, 'create');
        $in = read_body();
        turnstile_verify($in['turnstile_token'] ?? null);
        $r = trip_create($pdo, $in['name'] ?? null, $in['base_currency'] ?? null, $in['members'] ?? null);
        // 明文 token 只在這個回應出現一次
        send_json(201, ['trip_id' => $r['id'], 'edit_token' => $r['edit_token'], 'view_token' => $r['view_token']] + public_urls($r['id'], $r['edit_token'], $r['view_token']));
    }

    if ($path === 'trip' && $method === 'GET') {
        limit($pdo, 'read');
        $t = require_trip($pdo, false);
        $etag = '"' . $t['trip']['id'] . '-' . $t['trip']['version'] . '-' . $t['mode'] . '"';
        // 有些反向代理（例如開了 proxy_cache 的 nginx）會移除 If-None-Match，前端同時以 X-If-None-Match 傳送
        $inm = ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') ?: ($_SERVER['HTTP_X_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && in_array($etag, array_map(fn($x) => trim(str_replace('W/', '', $x)), explode(',', $inm)), true)) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: no-store');
            exit;
        }
        respond_state($pdo, (int) $t['trip']['id'], $t['mode']);
    }

    if ($path === 'fx' && $method === 'GET') {
        // 今日匯率（相對行程基準貨幣）：需有效 token（唯讀亦可），結果只供參考
        limit($pdo, 'read');
        $t = require_trip($pdo, false);
        $list = array_values(array_unique(array_filter(explode(',', (string) ($_GET['c'] ?? '')), 'is_currency')));
        if (!$list || count($list) > 20) fail(422, 'invalid_currencies', '請指定 1 至 20 種支援的貨幣。');
        $base = $t['trip']['base_currency'];
        send_json(200, ['base_currency' => $base] + fx_today($pdo, $base, $list));
    }

    $writeRoutes = [
        ['PATCH', '#^trip$#'], ['PUT', '#^trip/rates$#'], ['POST', '#^members$#'], ['PATCH', '#^members/(\d{1,12})$#'],
        ['POST', '#^expenses$#'], ['PATCH', '#^expenses/(\d{1,12})$#'], ['DELETE', '#^expenses/(\d{1,12})$#'],
    ];
    $matched = null;
    $known = false;
    foreach ($writeRoutes as [$m, $re]) {
        if (preg_match($re, $path, $mm)) {
            $known = true;
            if ($m === $method) { $matched = [$m, $re, isset($mm[1]) ? (int) $mm[1] : null]; break; }
        }
    }
    if (!$matched) {
        if ($known || in_array($path, ['trip', 'trips', 'config', 'fx'], true)) fail(405, 'method_not_allowed', '不支援此請求方法。');
        fail(404, 'not_found', '找不到此 API。');
    }

    limit($pdo, 'write');
    $t = require_trip($pdo, true);
    $tripId = (int) $t['trip']['id'];
    $in = read_body();
    [$m, $re, $id] = $matched;

    switch ($re) {
        case '#^trip$#':
            trip_patch($pdo, $tripId, $in);
            break;
        case '#^trip/rates$#':
            rates_put($pdo, $tripId, $in['rates'] ?? null);
            break;
        case '#^members$#':
            $newId = member_add($pdo, $tripId, $in['display_name'] ?? null);
            respond_state($pdo, $tripId, 'edit', 201, ['created_id' => $newId]);
        case '#^members/(\d{1,12})$#':
            member_patch($pdo, $tripId, $id, $in);
            break;
        case '#^expenses$#':
            $newId = expense_create($pdo, $tripId, $in);
            respond_state($pdo, $tripId, 'edit', 201, ['created_id' => $newId]);
        case '#^expenses/(\d{1,12})$#':
            if ($m === 'PATCH') expense_patch($pdo, $tripId, $id, $in, if_match_version());
            else expense_delete($pdo, $tripId, $id, if_match_version());
            break;
    }
    respond_state($pdo, $tripId, 'edit');
}

function run(): void
{
    try {
        route();
    } catch (ApiError $e) {
        send_error($e->status, $e->errCode, $e->getMessage(), $e->extra);
    } catch (Throwable $e) {
        error_log('[split-exp] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'locked')) send_error(503, 'busy', '伺服器忙碌，請稍後再試。', [], ['Retry-After' => '2']);
        send_error(500, 'internal', '伺服器發生錯誤，請稍後再試。');
    }
}
