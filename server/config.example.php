<?php
// Copy to data/config.php (outside the document root). 複製為 data/config.php，放在 document root 之外。
return [
    'db'                => __DIR__ . '/app.sqlite',
    'backup_dir'        => __DIR__ . '/backups',
    'error_log'         => __DIR__ . '/php_error.log',
    // Public URL of the app, with trailing slash. Share links are built from it.
    'base_url'          => 'https://example.com/split-exp/',
    // Cloudflare Turnstile (free) on trip creation. Keep the secret out of this file if you like:
    // turnstile_secret.php containing <?php return '…';  Both empty = no bot check (a warning is logged).
    'turnstile_sitekey'     => '',
    'turnstile_secret'      => '',
    'turnstile_secret_file' => __DIR__ . '/turnstile_secret.php',
    // siteverify hostname must be in this list; never include localhost / 127.0.0.1 in production.
    'turnstile_hostnames'   => ['example.com'],
    // Salt for IP hashes (rate limiting). Generate with: php -r 'echo bin2hex(random_bytes(32));'
    'ip_salt'           => 'CHANGE-ME',
    // X-Forwarded-For is trusted only from these addresses (your reverse proxy). Empty = use REMOTE_ADDR.
    'trusted_proxies'   => [],
    // Optional admin area: path to a file providing auth_require() & co. (see README). Empty = admin disabled.
    'auth_lib'          => '',
];
