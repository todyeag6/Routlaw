<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * Result of output sanitization (T17, HAR-14, LLM05).
 */
final class SanitizedOutput
{
    public function __construct(
        public readonly string $text,
        public readonly bool $sanitized,
    ) {
    }
}
