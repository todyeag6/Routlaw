<?php
/**
 * PHPStan stubs for ROUTLAW (no side-effects).
 *
 * config/autoload.php has runtime side-effects (env detection, $_SERVER
 * checks, session start, secret loading) that must NOT run during static
 * analysis. This file provides only the type/constant declarations PHPStan
 * needs to resolve symbols, following the scanFiles (not bootstrapFiles)
 * pattern.
 *
 * Usage in phpstan.neon:
 *   scanFiles:
 *       - stubs.php
 */
declare(strict_types=1);

namespace Routlaw;

/** @var array<string,string> $_ENV populated by config/autoload.php at runtime. */
// phpcs:disable

// Constants used across src/ that config/autoload.php defines at runtime.
\defined('ROUTLAW_NO_SESSION') || \define('ROUTLAW_NO_SESSION', false);
