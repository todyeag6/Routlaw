<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * Result of tool-output validation (T17, HAR-13, LLM05).
 */
final class ToolOutputResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $outputHash,
        public readonly ?string $error,
        public readonly bool $sanitized,
    ) {
    }
}
