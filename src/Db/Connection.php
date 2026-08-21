<?php
declare(strict_types=1);

namespace Routlaw\Db;

/**
 * Minimal mysqli connection helper (SEC-006: parameterization used by callers).
 * Reads ROUTLAW test/local env from $_ENV (secrets.local.php or defaults).
 */
final class Connection
{
    public static function connect(string $dbName = ''): \mysqli
    {
        $host = $_ENV['RL_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['RL_DB_USER'] ?? 'root';
        $pass = $_ENV['RL_DB_PASS'] ?? '';
        $port = (int) ($_ENV['RL_DB_PORT'] ?? 3306);
        $m = new \mysqli($host, $user, $pass, $dbName, $port);
        if ($m->connect_error) {
            throw new \RuntimeException('DB connect failed: ' . $m->connect_error);
        }
        $m->set_charset('utf8mb4');
        // Align session tz with PHP UTC (mokimi lesson: NOW() vs PHP clock).
        $m->query("SET SESSION time_zone = '+00:00'");
        return $m;
    }

    /** Apply a migration SQL file with naive ';' splitting (mirrors mokimi bootstrap). */
    public static function applyFile(\mysqli $m, string $path): void
    {
        $sql = file_get_contents($path);
        // Strip -- line comments (mokimi lesson: comments with ';' must not break split).
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $ok = $m->query($stmt);
            if ($ok === false) {
                throw new \RuntimeException("Migration failed: {$m->error} | SQL: {$stmt}");
            }
        }
    }
}
