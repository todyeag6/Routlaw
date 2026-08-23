<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * Outcome of a single tool step through the harness pipeline.
 */
final class ToolStepResult
{
    public function __construct(
        public readonly string $tool,
        public readonly bool $allowed,
        public readonly ?string $denyReason,
        public readonly bool $budgetOk,
        public readonly bool $killSwitchActive,
        public readonly ?string $outputHash,
        public readonly ?string $error,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->allowed && $this->budgetOk && !$this->killSwitchActive && $this->error === null;
    }
}
