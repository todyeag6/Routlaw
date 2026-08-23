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

$migDir = $root . '/migrations';
$applied = 0;
for ($i = 0; $i <= 16; $i++) {
    $matches = glob($migDir . '/' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '_*.sql');
    if ($matches === []) {
        echo "NO MIGRATION FILE FOR $i (expected; migrations may not be sequential)\n";
        continue;
    }
    echo 'Applying ' . basename($matches[0]) . ' ... ';
    \Routlaw\Db\Connection::applyFile($m, $matches[0]);
    echo "OK\n";
    $applied++;
}
echo "MIGRATIONS APPLIED: {$applied}\n";

$res = $m->query('SHOW TABLES');
$n = $res ? $res->num_rows : 0;
echo "TABLES IN `{$dbName}`: {$n}\n";

$r = $m->query('SELECT COUNT(*) AS c FROM roles');
echo "roles rows: " . ($r ? $r->fetch_assoc()['c'] : 'ERR') . "\n";
foreach (['memory_entries', 'system_flags', 'agent_runs', 'tool_calls'] as $t) {
    $chk = $m->query("SHOW TABLES LIKE '{$t}'");
    echo "{$t} present: " . ($chk && $chk->num_rows ? 'YES' : 'NO') . "\n";
}
