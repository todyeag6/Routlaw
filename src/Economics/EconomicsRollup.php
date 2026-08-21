<?php
declare(strict_types=1);

namespace Routlaw\Economics;

/**
 * Total-mile/time economics rollup result (T11.2).
 *
 * isComputed() === false ⇒ ABSTAINED (mandatory input missing) — never a fabricated value
 * (BR-005). When computed, carries the source cost-profile version + effective_from for
 * reproducibility (FR-051), plus the net vs posted_rate.
 */
final class EconomicsRollup
{
    /**
     * @param bool $computed
     * @param string $reason 'computed' | 'no_active_cost_profile' | 'distance_required' | 'posted_rate_required' | 'unknown_unit_type'.
     * @param float|null $totalCost
     * @param int|null $costProfileId
     * @param string|null $effectiveFrom
     * @param string|null $unitType
     * @param float|null $rate
     * @param float|null $postedRate
     * @param float|null $net posted_rate − totalCost
     * @param float|null $distanceMiles
     */
    public function __construct(
        public readonly bool $computed,
        public readonly string $reason,
        public readonly ?float $totalCost,
        public readonly ?int $costProfileId,
        public readonly ?string $effectiveFrom,
        public readonly ?string $unitType,
        public readonly ?float $rate,
        public readonly ?float $postedRate,
        public readonly ?float $net,
        public readonly ?float $distanceMiles
    ) {
    }

    public function isComputed(): bool
    {
        return $this->computed;
    }
}
