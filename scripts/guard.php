<?php
declare(strict_types=1);

/**
 * Standing quality guard CLI (ROUTLAW build-plan §3 T1.2).
 * Exit 1 on any violation (fail-closed); exit 0 when clean.
 * Usage: php scripts/guard.php [--paths=dir1,dir2]
 */

require __DIR__ . '/../vendor/autoload.php';

use Routlaw\Guard;

$paths = ['src', 'config', 'public', 'bin'];
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--paths=')) {
        $paths = array_filter(explode(',', substr($arg, strlen('--paths='))), 'strlen');
    }
}

$violations = Guard::scanPaths($paths);

if ($violations !== []) {
    fwrite(STDERR, "GUARD FAILURES:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}

echo "GUARD PASS: no hardcoded-secret violations in " . implode(', ', $paths) . "\n";
exit(0);
