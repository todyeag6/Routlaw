<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * VerifierVerdict — outcome of a SelfCorrectionVerifier run (FR-038).
 */
final class VerifierVerdict
{
    public function __construct(
        public readonly bool $passed,       // PHP-decided; NOT LLM-controllable
        public readonly string $verdict,     // machine-readable reason code
        public readonly string $reason,      // human-readable explanation
        public readonly string $llmAdvisory, // advisory LLM note; never drives the decision
    ) {}
}
