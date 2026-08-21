<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Gates\GateResult;
use Routlaw\Gates\HardGateEngine;

/**
 * T10.1 — deterministic weight / dimension / equipment-completeness gates.
 * Evaluated from REAL columns only. BR-005: missing mandatory datum → ABSTAIN, never a guess.
 */
final class HardGateTest extends TestCase
{
    private HardGateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new HardGateEngine();
    }

    private function equipment(array $over = []): array
    {
        return array_merge([
            'status' => 'approved',
            'is_complete' => 1,
            'payload_capacity_lbs' => 10000,
            'deck_length_ft' => 40.0,
            'deck_width_ft' => 8.5,
        ], $over);
    }

    public function test_overweight_fails(): void
    {
        $load = ['weight_lbs' => 12000];
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $weight = $this->find($results, 'weight');
        $this->assertTrue($weight->isFail(), 'Overweight must FAIL.');
        $this->assertSame('overweight', $weight->reason);
    }

    public function test_within_payload_passes(): void
    {
        $load = ['weight_lbs' => 8000];
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $this->assertTrue($this->find($results, 'weight')->isPass());
    }

    public function test_missing_load_weight_abstains(): void
    {
        $load = []; // no weight_lbs
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $weight = $this->find($results, 'weight');
        $this->assertTrue($weight->isAbstain(), 'Missing load weight must ABSTAIN, never assume.');
        $this->assertSame('weight_unknown', $weight->reason);
    }

    public function test_missing_equipment_payload_abstains(): void
    {
        $load = ['weight_lbs' => 5000];
        $results = $this->engine->evaluateEquipment($load, $this->equipment(['payload_capacity_lbs' => null]));
        $this->assertTrue($this->find($results, 'weight')->isAbstain(), 'Missing capacity must ABSTAIN, never assume.');
    }

    public function test_no_equipment_abstains_weight(): void
    {
        $load = ['weight_lbs' => 5000];
        $results = $this->engine->evaluateEquipment($load, null);
        $this->assertTrue($this->find($results, 'weight')->isAbstain());
        $this->assertTrue($this->find($results, 'dimension')->isAbstain());
        $this->assertTrue($this->find($results, 'equipment_completeness')->isAbstain());
    }

    public function test_oversize_length_fails(): void
    {
        $load = ['length_ft' => 45.0];
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $dim = $this->find($results, 'dimension');
        $this->assertTrue($dim->isFail(), 'Load longer than deck must FAIL.');
        $this->assertSame('too_long', $dim->reason);
    }

    public function test_dimension_fits_passes(): void
    {
        $load = ['length_ft' => 30.0, 'width_ft' => 8.0];
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $this->assertTrue($this->find($results, 'dimension')->isPass());
    }

    public function test_no_dimensions_declared_passes_not_abstains(): void
    {
        $load = ['weight_lbs' => 5000]; // no length/width
        $results = $this->engine->evaluateEquipment($load, $this->equipment());
        $this->assertTrue($this->find($results, 'dimension')->isPass(), 'Undeclared dimensions must PASS, not abstain.');
    }

    public function test_missing_deck_size_abstains_dimension(): void
    {
        $load = ['length_ft' => 30.0];
        $results = $this->engine->evaluateEquipment($load, $this->equipment(['deck_length_ft' => null]));
        $this->assertTrue($this->find($results, 'dimension')->isAbstain(), 'Missing deck size must ABSTAIN.');
    }

    public function test_incomplete_equipment_abstains(): void
    {
        $results = $this->engine->evaluateEquipment(['weight_lbs' => 5000], $this->equipment(['is_complete' => 0]));
        $eq = $this->find($results, 'equipment_completeness');
        $this->assertTrue($eq->isAbstain(), 'Incomplete equipment must ABSTAIN (FRD §19.2).');
        $this->assertSame('equipment_incomplete', $eq->reason);
    }

    public function test_unapproved_equipment_abstains(): void
    {
        $results = $this->engine->evaluateEquipment(['weight_lbs' => 5000], $this->equipment(['status' => 'draft']));
        $eq = $this->find($results, 'equipment_completeness');
        $this->assertTrue($eq->isAbstain());
        $this->assertSame('equipment_not_approved', $eq->reason);
    }

    public function test_approved_complete_equipment_passes(): void
    {
        $results = $this->engine->evaluateEquipment(['weight_lbs' => 5000], $this->equipment());
        $this->assertTrue($this->find($results, 'equipment_completeness')->isPass());
    }

    // ----- T10.2: CDL / hazmat / HOS / ELD compliance flag (FR-016), honest ABSTAIN -----

    private function carrier(array $over = []): array
    {
        return array_merge(['cdl_status' => 'unknown'], $over);
    }

    public function test_cdl_unknown_abstains(): void
    {
        $results = $this->engine->evaluateCompliance($this->carrier(['cdl_status' => 'unknown']), [], null);
        $cdl = $this->find($results, 'cdl');
        $this->assertTrue($cdl->isAbstain(), 'Unknown CDL status must ABSTAIN, never assume non-CDL.');
        $this->assertSame('cdl_unknown', $cdl->reason);
    }

    public function test_cdl_determined_passes(): void
    {
        foreach (['non_cdl', 'cdl_a', 'cdl_b', 'cdl_c'] as $status) {
            $results = $this->engine->evaluateCompliance($this->carrier(['cdl_status' => $status]), [], null);
            $this->assertTrue($this->find($results, 'cdl')->isPass(), "cdl_status '{$status}' must PASS (determination present).");
        }
    }

    public function test_hazmat_unknown_is_not_applicable_pass_no_fabrication(): void
    {
        $results = $this->engine->evaluateCompliance($this->carrier(), [], null);
        $hz = $this->find($results, 'hazmat');
        $this->assertTrue($hz->isPass(), 'No hazmat declared → not applicable PASS; never invent a class.');
        $this->assertSame('not_applicable', $hz->reason);
    }

    public function test_hos_applicability_unknown_is_assumption_pass(): void
    {
        $results = $this->engine->evaluateCompliance($this->carrier(), [], null);
        $hos = $this->find($results, 'hos');
        $this->assertTrue($hos->isPass(), 'Unknown HOS applicability → PASS assumption, not a hard block (FRD §19.2).');
        $this->assertSame('applicability_unknown_assumption', $hos->reason);
    }

    public function test_eld_applicability_unknown_is_assumption_pass(): void
    {
        $results = $this->engine->evaluateCompliance($this->carrier(), [], null);
        $eld = $this->find($results, 'eld');
        $this->assertTrue($eld->isPass(), 'Unknown ELD applicability → PASS assumption, not a hard block (FRD §19.2).');
        $this->assertSame('applicability_unknown_assumption', $eld->reason);
    }

    private function find(array $results, string $gate): GateResult
    {
        foreach ($results as $r) {
            if ($r->gate === $gate) {
                return $r;
            }
        }
        $this->fail("Gate '{$gate}' not present in results.");
    }
}
