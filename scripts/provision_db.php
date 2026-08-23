<?php
declare(strict_types=1);
/**
 * scripts/provision_db.php — Provision the canonical ROUTLAW database.
 *
 * All DB credentials come from the project's own config/secrets.local.php (via $_ENV),
 * and all paths are resolved relative to this script's location. No hardcoded host,
 * port, or path assumptions.
 *
 * Mirrors the exact migration-apply path the test suite uses (Routlaw\Db\Connection).
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$secrets = $root . '/config/secrets.local.php';
if (is_file($secrets)) {
    require $secrets;
}

$dbName = $_ENV['RL_DB_NAME']   ?? 'routlaw';
$host   = $_ENV['RL_DB_HOST']   ?? '127.0.0.1';
$user   = $_ENV['RL_DB_USER']   ?? 'root';
$pass   = $_ENV['RL_DB_PASS']   ?? '';
$port   = (int) ($_ENV['RL_DB_PORT'] ?? '3306');

$base = new mysqli($host, $user, $pass, '', $port);
if ($base->connect_error) {
    fwrite(STDERR, "CONN FAIL: {$base->connect_error}\n");
    exit(1);
}
$base->set_charset('utf8mb4');
$base->query("SET SESSION time_zone = '+00:00'");

$base->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if ($base->error) {
    fwrite(STDERR, "CREATE DB FAIL: {$base->error}\n");
    exit(1);
}
echo "DB `{$dbName}` ensured.\n";

$m = new mysqli($host, $user, $pass, $dbName, $port);
if ($m->connect_error) {
    fwrite(STDERR, "CONN TO DB FAIL: {$m->connect_error}\n");
    exit(1);
}
$m->set_charset('utf8mb4');
$m->query("SET SESSION time_zone = '+00:00'");

// Track applied migrations so re-running is idempotent (safe to call repeatedly).
$m->query(
    'CREATE TABLE IF NOT EXISTS migrations_log ('
    . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
    . 'migration VARCHAR(191) NOT NULL UNIQUE, '
    . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ') CHARACTER SET utf8mb4'
);

$migDir = $root . '/migrations';

// Backfill the log if the DB was provisioned by an earlier run that predated tracking.
$logCount = (int) $m->query('SELECT COUNT(*) AS c FROM migrations_log')->fetch_assoc()['c'];
$alreadyProvisioned = $m->query("SHOW TABLES LIKE 'companies'")->num_rows > 0;
if ($logCount === 0 && $alreadyProvisioned) {
    echo "Backfilling migrations_log from existing schema (prior provision, no tracking)...\n";
    $allFiles = glob($migDir . '/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($allFiles);
    foreach ($allFiles as $path) {
        $file = basename($path);
        $m->query("INSERT IGNORE INTO migrations_log (migration) VALUES ('{$m->real_escape_string($file)}')");
    }
    echo "Backfill complete (" . count($allFiles) . " entries).\n";
}

// Discover + apply migrations dynamically (do NOT hardcode a numeric ceiling — a new
// migration file must be applied on the next provision even if this script wasn't updated).
$applied = 0;
$skipped = 0;
$allFiles = glob($migDir . '/[0-9][0-9][0-9]_*.sql') ?: [];
sort($allFiles);
foreach ($allFiles as $path) {
    $file = basename($path);
    $already = $m->query("SELECT 1 FROM migrations_log WHERE migration = '{$m->real_escape_string($file)}' LIMIT 1");
    if ($already && $already->num_rows > 0) {
        echo "SKIP (already applied): {$file}\n";
        $skipped++;
        continue;
    }
    echo "Applying {$file} ... ";
    \Routlaw\Db\Connection::applyFile($m, $path);
    $m->query("INSERT INTO migrations_log (migration) VALUES ('{$m->real_escape_string($file)}')");
    echo "OK\n";
    $applied++;
}
echo "MIGRATIONS APPLIED THIS RUN: {$applied} (skipped already-applied: {$skipped})\n";

$res = $m->query('SHOW TABLES');
$n = $res ? $res->num_rows : 0;
echo "TABLES IN `{$dbName}`: {$n}\n";

$r = $m->query('SELECT COUNT(*) AS c FROM roles');
echo "roles rows: " . ($r ? $r->fetch_assoc()['c'] : 'ERR') . "\n";
foreach (['memory_entries', 'system_flags', 'agent_runs', 'tool_calls'] as $t) {
    $chk = $m->query("SHOW TABLES LIKE '{$t}'");
    echo "{$t} present: " . ($chk && $chk->num_rows ? 'YES' : 'NO') . "\n";
}
