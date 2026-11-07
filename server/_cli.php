<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/store.php';

function cli_config(): array
{
    $path = getenv('SPLIT_EXP_CONFIG') ?: dirname(__DIR__) . '/data/config.php';
    foreach ($GLOBALS['argv'] as $a) if (str_starts_with($a, '--config=')) $path = substr($a, 9);
    if (!is_file($path)) { fwrite(STDERR, "config not found: {$path}\n"); exit(2); }
    return require $path;
}

function cli_flag(string $name): ?string
{
    foreach ($GLOBALS['argv'] as $a) {
        if ($a === "--{$name}") return '1';
        if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
    }
    return null;
}
