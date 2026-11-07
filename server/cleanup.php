<?php
// 列出超過 12 個月無活動的行程；加 --execute 才刪除（刪除前先備份）
require __DIR__ . '/_cli.php';
$c = cli_config();
$pdo = db_open($c['db']);
$months = (int) (cli_flag('months') ?? 12);
$cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$months} months"));
$rows = q($pdo, 'SELECT t.id, t.name, t.last_active_at, (SELECT COUNT(*) FROM expenses e WHERE e.trip_id = t.id) AS n
                 FROM trips t WHERE t.last_active_at < ? ORDER BY t.last_active_at', [$cutoff])->fetchAll();
echo "inactive since before {$cutoff}: ", count($rows), " trip(s)\n";
foreach ($rows as $r) printf("  #%d  %s  last_active=%s  expenses=%d\n", $r['id'], $r['name'], $r['last_active_at'], $r['n']);
if (!$rows) exit(0);
if (cli_flag('execute') === null) { echo "dry run — add --execute to delete\n"; exit(0); }
$bk = db_backup($pdo, $c['backup_dir']);
echo "backup: {$bk}\n";
foreach ($rows as $r) { trip_purge($pdo, (int) $r['id']); echo "deleted #{$r['id']}\n"; }
