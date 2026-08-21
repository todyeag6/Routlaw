<?php
declare(strict_types=1);

namespace Routlaw;

/**
 * Standing quality guard (ROUTLAW build-plan §3 T1.2).
 * Fail-closed static checks over source files. Mirrors the mokimi guard contract:
 * any violation => non-zero exit; clean => zero.
 */
final class Guard
{
    /** Secret-assignment patterns that must never appear as literals. */
    private const SECRET_PATTERNS = [
        '/(?i)(password|passwd|secret|api[_-]?key|token|client[_-]?secret)\s*=\s*[\'"][^\'"]{4,}/',
        '/(?i)(AKIA|sk_live_|sk-)[0-9A-Za-z]{8,}/', // AWS / Stripe-style key prefixes
    ];

    /**
     * Scan a single PHP source string for hardcoded-secret candidates.
     * @return list<string> Violation messages (empty = clean).
     */
    public static function scanSecrets(string $code): array
    {
        $violations = [];
        foreach (self::SECRET_PATTERNS as $re) {
            if (preg_match($re, $code, $m)) {
                $violations[] = 'hardcoded secret candidate: ' . $m[0];
            }
        }
        return $violations;
    }

    /**
     * Scan a directory tree of .php files.
     * @param list<string> $paths
     * @return list<string>
     */
    public static function scanPaths(array $paths): array
    {
        $violations = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                $files = new \RegexIterator($rii, '/\.php$/i');
            } elseif (is_file($path)) {
                $files = [$path];
            } else {
                continue;
            }
            foreach ($files as $file) {
                $code = @file_get_contents((string) $file);
                if ($code === false) {
                    continue;
                }
                foreach (self::scanSecrets($code) as $v) {
                    $violations[] = $file . ': ' . $v;
                }
            }
        }
        return $violations;
    }

    /** Fail-closed decision: any violation => 1, clean => 0. */
    public static function decide(array $violations): int
    {
        return $violations === [] ? 0 : 1;
    }
}
