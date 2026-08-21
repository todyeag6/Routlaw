<?php
declare(strict_types=1);

namespace Routlaw\Gates;

/**
 * Hard-gate outcome for a single gate (FRD §13 / FR-016).
 *
 * Outcomes are exhaustive and deterministic:
 *  - PASS   : gate satisfied, load is compatible on this axis.
 *  - FAIL   : gate violated deterministically (e.g. overweight). Prevents `recommended`.
 *  - ABSTAIN: mandatory reference data missing/unknown → cannot decide → route to human
 *             (never fabricate a value, BR-005). Prevents `recommended`, sets `needs_review`.
 */
final class GateResult
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const ABSTAIN = 'abstain';

    /**
     * @param string $gate       Gate identifier (e.g. 'weight', 'dimension', 'equipment_completeness', 'cdl', 'hazmat', 'hos', 'eld').
     * @param string $outcome    self::PASS | self::FAIL | self::ABSTAIN.
     * @param string $reason     Machine-readable reason code (e.g. 'overweight', 'weight_unknown').
     * @param string $message    Human-readable explanation (hard-fail reasons visible, FRD §6.1 / §19.2).
     * @param array<string,mixed> $detail Optional structured detail (values compared, etc.).
     */
    public function __construct(
        public readonly string $gate,
        public readonly string $outcome,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $detail = []
    ) {
    }

    public function isPass(): bool   { return $this->outcome === self::PASS; }
    public function isFail(): bool   { return $this->outcome === self::FAIL; }
    public function isAbstain(): bool { return $this->outcome === self::ABSTAIN; }
}
