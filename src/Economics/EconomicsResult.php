<?php
declare(strict_types=1);

namespace Routlaw\Economics;

/**
 * Result of an economics computation.
 *
 * isComputed() === false ⇒ the figure was ABSTAINED (mandatory input missing), never a
 * fabricated value (BR-005). When computed, the result carries the source cost-profile
 * version + effective_from so the figure is reproducible from stored inputs (FR-051).
 */
final class EconomicsResult
{
    /**
     * @param bool $computed True if a value was derived; false ⇒ abstained.
     * @param float|null $totalCost Derived total cost (null when abstained).
     * @param string $reason Machine-readable reason ('computed', 'no_active_cost_profile', 'distance_required').
     * @param int|null $costProfileId Source carrier_cost_profiles.id.
     * @param string|null $effectiveFrom Source profile effective_from (YYYY-MM-DD).
     * @param string|null $unitType Source profile unit_type.
     * @param float|null $rate Source profile rate.
     */
    public function __construct(
        public readonly bool $computed,
        public readonly ?float $totalCost,
        public readonly string $reason,
        public readonly ?int $costProfileId,
        public readonly ?string $effectiveFrom,
        public readonly ?string $unitType,
        public readonly ?float $rate
    ) {
    }

    public function isComputed(): bool
    {
        return $this->computed;
    }
}
