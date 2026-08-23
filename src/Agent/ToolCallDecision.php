<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * Result of a per-call authorization decision (T17, HAR-01/02/09, LLM06).
 */
final class ToolCallDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $toolName,
        public readonly string $status,        // 'allowed' | 'blocked'
        public readonly ?string $denyReason,
        public readonly ?int $approvalId,
    ) {
    }
}
