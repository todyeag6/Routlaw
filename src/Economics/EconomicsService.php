<?php
declare(strict_types=1);

namespace Routlaw\Economics;

use Routlaw\Costs\CostProfileService;

/**
 * T11.1/T11.2 — carrier-specific economics derived from the versioned, tenant-scoped
 * carrier_cost_profiles (FR-051/BR-001/021).
 *
 * Total cost = active profile rate × distance (per_mile) or flat (flat). The active
 * profile is resolved via CostProfileService::getActiveAt (effective-date + completeness
 * logic). The result carries the source profile version + effective_from so the figure
 * is reproducible from stored inputs (FR-051). Missing mandatory input (no active profile,
 * or per_mile without distance) ⇒ ABSTAIN — never a fabricated value (BR-005).
 *
 * Tenant-scoped: every lookup carries company_id (FR-042).
 */
final class EconomicsService
{
    private CostProfileService $costSvc;

    public function __construct(\mysqli $db)
    {
        $this->costSvc = new CostProfileService($db);
    }

    /**
     * Compute total cost for a carrier at a given date.
     *
     * @param int $companyId Tenant scope (FR-042).
     * @param int $carrierId Carrier to price.
     * @param float|null $distanceMiles Distance entered by the operator (null ⇒ abstain for per_mile).
     * @param string $asOf Date to resolve the active profile (YYYY-MM-DD).
     */
    public function totalCost(int $companyId, int $carrierId, ?float $distanceMiles, string $asOf = 'today'): EconomicsResult
    {
        $profile = $this->costSvc->getActiveAt($companyId, $carrierId, $asOf);
        if ($profile === null) {
            return new EconomicsResult(false, null, 'no_active_cost_profile', null, null, null, null);
        }

        $unitType = (string) $profile['unit_type'];
        $rate = (float) $profile['rate'];

        if ($unitType === CostProfileService::UNIT_PER_MILE) {
            if ($distanceMiles === null) {
                return new EconomicsResult(false, null, 'distance_required', null, null, null, null);
            }
            $total = round($rate * $distanceMiles, 2);
        } elseif ($unitType === CostProfileService::UNIT_FLAT) {
            $total = round($rate, 2);
        } elseif ($unitType === CostProfileService::UNIT_PERCENTAGE) {
            // Percentage requires a base; without an entered base it cannot be computed.
            if ($distanceMiles === null) {
                return new EconomicsResult(false, null, 'distance_required', null, null, null, null);
            }
            $total = round($rate / 100.0 * $distanceMiles, 2);
        } else {
            return new EconomicsResult(false, null, 'unknown_unit_type', null, null, null, null);
        }

        return new EconomicsResult(
            true,
            $total,
            'computed',
            (int) $profile['id'],
            (string) $profile['effective_from'],
            $unitType,
            $rate
        );
    }

    /**
     * Compute total-mile/time economics rollup: total cost from the active versioned profile,
     * plus net vs the operator-entered posted_rate. Abstains (never fabricates) when the
     * mandatory inputs are missing (no active profile, or per_mile/percentage without distance,
     * or no posted_rate for net). When computed, tags the source profile version + effective_from
     * so the figure is reproducible (FR-051). Tenant-scoped (FR-042).
     *
     * @param array<string,mixed> $load Load record (posted_rate, ...).
     * @param float|null $distanceMiles Operator-entered distance (null ⇒ abstain for per_mile/percentage).
     * @param string $asOf Date to resolve the active profile.
     */
    public function rollup(int $companyId, int $carrierId, array $load, ?float $distanceMiles, string $asOf = 'today'): EconomicsRollup
    {
        $cost = $this->totalCost($companyId, $carrierId, $distanceMiles, $asOf);
        if (!$cost->isComputed()) {
            return new EconomicsRollup(false, $cost->reason, null, null, null, null, null, null, null, null);
        }

        $postedRate = $this->nullOrFloat($load['posted_rate'] ?? null);
        if ($postedRate === null) {
            return new EconomicsRollup(false, 'posted_rate_required', $cost->totalCost, $cost->costProfileId,
                $cost->effectiveFrom, $cost->unitType, $cost->rate, null, null, null);
        }

        $net = round($postedRate - $cost->totalCost, 2);
        return new EconomicsRollup(true, 'computed', $cost->totalCost, $cost->costProfileId,
            $cost->effectiveFrom, $cost->unitType, $cost->rate, $postedRate, $net, $distanceMiles);
    }

    private function nullOrFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (float) $v;
    }
}
