<?php
declare(strict_types=1);

namespace Routlaw\Gates;

/**
 * Aggregate hard-gate evaluation result.
 *
 * outcome is the decision-blocking summary:
 *  - 'clear'        → all gates PASS → recommendation is permitted.
 *  - 'blocked'      → at least one FAIL → cannot be recommended (route to reject / human).
 *  - 'needs_review' → at least one ABSTAIN (uncertainty) → cannot be recommended, human review.
 *
 * Recommended is allowed ONLY when outcome is 'clear' (gates run BEFORE scoring).
 */
final class GateEvaluation
{
    public const CLEAR = 'clear';
    public const BLOCKED = 'blocked';
    public const NEEDS_REVIEW = 'needs_review';

    /**
     * @param string $outcome   self::CLEAR | self::BLOCKED | self::NEEDS_REVIEW.
     * @param list<GateResult> $results
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $results
    ) {
    }

    public function isClear(): bool       { return $this->outcome === self::CLEAR; }
    public function isBlocked(): bool     { return $this->outcome === self::BLOCKED; }
    public function isNeedsReview(): bool { return $this->outcome === self::NEEDS_REVIEW; }

    /** Hard gates run before scoring: a recommendation is permitted only when all gates clear. */
    public function recommendedAllowed(): bool
    {
        return $this->outcome === self::CLEAR;
    }
}
