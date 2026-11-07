<?php
// 每日備份：php backup.php [--config=…] [--keep=30]
require __DIR__ . '/_cli.php';
$c = cli_config();
$pdo = db_open($c['db']);
$path = db_backup($pdo, $c['backup_dir'], (int) (cli_flag('keep') ?? 30));
echo 'backup: ', $path, ' (', filesize($path), " bytes)\n";
