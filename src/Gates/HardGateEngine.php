<?php
declare(strict_types=1);

namespace Routlaw\Gates;

/**
 * HardGateEngine — deterministic compatibility gates that run BEFORE any scoring.
 *
 * T10.1 (this file): weight / dimension / equipment-completeness gates evaluated from
 * REAL table columns only:
 *   - loads.weight_lbs            vs equipment_profiles.payload_capacity_lbs
 *   - load length/width (optional) vs equipment_profiles.deck_length_ft / deck_width_ft
 *   - equipment_profiles.status = 'approved' AND is_complete = 1
 *
 * T10.2 adds the CDL/hazmat/HOS/ELD compliance flag (FR-016) as honest ABSTAIN when
 * reference data is absent — never fabricated.
 *
 * BR-005: any missing mandatory reference datum → ABSTAIN (route to human), never a guess.
 */
final class HardGateEngine
{
    /**
     * Evaluate the deterministic equipment/compatibility gates.
     *
     * @param array<string,mixed> $load        Normalized load record (keys: weight_lbs, length_ft, width_ft, ...).
     * @param array<string,mixed>|null $equipment Equipment profile row, or null if none assigned.
     * @return list<GateResult>
     */
    public function evaluateEquipment(array $load, ?array $equipment): array
    {
        return [
            $this->gateWeight($load, $equipment),
            $this->gateDimension($load, $equipment),
            $this->gateEquipmentCompleteness($equipment),
        ];
    }

    /**
     * Weight gate: load weight must not exceed equipment payload capacity.
     * Missing equipment payload OR missing load weight → ABSTAIN (cannot decide).
     *
     * @param array<string,mixed> $load
     * @param array<string,mixed>|null $equipment
     */
    private function gateWeight(array $load, ?array $equipment): GateResult
    {
        $weight = $this->nullOrInt($load['weight_lbs'] ?? null);
        if ($equipment === null) {
            return new GateResult('weight', GateResult::ABSTAIN, 'no_equipment',
                'No equipment profile assigned; cannot evaluate weight compatibility.', []);
        }
        $capacity = $this->nullOrInt($equipment['payload_capacity_lbs'] ?? null);
        if ($weight === null) {
            return new GateResult('weight', GateResult::ABSTAIN, 'weight_unknown',
                'Load weight is missing; cannot evaluate weight compatibility.', []);
        }
        if ($capacity === null) {
            return new GateResult('weight', GateResult::ABSTAIN, 'capacity_unknown',
                'Equipment payload capacity is missing; cannot evaluate weight compatibility.', []);
        }
        if ($weight > $capacity) {
            return new GateResult('weight', GateResult::FAIL, 'overweight',
                sprintf('Load weight %d lbs exceeds equipment payload capacity %d lbs.', $weight, $capacity),
                ['weight_lbs' => $weight, 'payload_capacity_lbs' => $capacity]);
        }
        return new GateResult('weight', GateResult::PASS, 'within_payload',
            sprintf('Load weight %d lbs within payload capacity %d lbs.', $weight, $capacity),
            ['weight_lbs' => $weight, 'payload_capacity_lbs' => $capacity]);
    }

    /**
     * Dimension gate: if the load declares length/width, it must fit the deck.
     * Not all loads declare dimensions — only ABSTAIN when the equipment deck size is missing.
     *
     * @param array<string,mixed> $load
     * @param array<string,mixed>|null $equipment
     */
    private function gateDimension(array $load, ?array $equipment): GateResult
    {
        if ($equipment === null) {
            return new GateResult('dimension', GateResult::ABSTAIN, 'no_equipment',
                'No equipment profile assigned; cannot evaluate dimension compatibility.', []);
        }
        $length = $this->nullOrFloat($load['length_ft'] ?? null);
        $width = $this->nullOrFloat($load['width_ft'] ?? null);
        if ($length === null && $width === null) {
            // Load declares no dimensions — nothing to check against the deck; pass (not abstain).
            return new GateResult('dimension', GateResult::PASS, 'no_dimensions_declared',
                'Load declares no dimensions; dimension gate not applicable.', []);
        }
        $deckLen = $this->nullOrFloat($equipment['deck_length_ft'] ?? null);
        $deckWid = $this->nullOrFloat($equipment['deck_width_ft'] ?? null);
        if (($length !== null && $deckLen === null) || ($width !== null && $deckWid === null)) {
            return new GateResult('dimension', GateResult::ABSTAIN, 'deck_size_unknown',
                'Equipment deck dimensions are missing; cannot evaluate dimension compatibility.', []);
        }
        if ($length !== null && $deckLen !== null && $length > $deckLen) {
            return new GateResult('dimension', GateResult::FAIL, 'too_long',
                sprintf('Load length %.2f ft exceeds deck length %.2f ft.', $length, $deckLen),
                ['length_ft' => $length, 'deck_length_ft' => $deckLen]);
        }
        if ($width !== null && $deckWid !== null && $width > $deckWid) {
            return new GateResult('dimension', GateResult::FAIL, 'too_wide',
                sprintf('Load width %.2f ft exceeds deck width %.2f ft.', $width, $deckWid),
                ['width_ft' => $width, 'deck_width_ft' => $deckWid]);
        }
        return new GateResult('dimension', GateResult::PASS, 'fits_deck',
            'Load dimensions fit the equipment deck.', []);
    }

    /**
     * Equipment completeness gate: an incomplete or non-approved equipment profile cannot
     * support a `recommended` decision (FRD §19.2: incomplete equipment profile → uncertainty).
     *
     * @param array<string,mixed>|null $equipment
     */
    private function gateEquipmentCompleteness(?array $equipment): GateResult
    {
        if ($equipment === null) {
            return new GateResult('equipment_completeness', GateResult::ABSTAIN, 'no_equipment',
                'No equipment profile assigned.', []);
        }
        $status = (string) ($equipment['status'] ?? '');
        $complete = (int) ($equipment['is_complete'] ?? 0);
        if ($status !== 'approved') {
            return new GateResult('equipment_completeness', GateResult::ABSTAIN, 'equipment_not_approved',
                sprintf("Equipment profile status is '%s', not approved; cannot recommend.", $status),
                ['status' => $status]);
        }
        if ($complete !== 1) {
            return new GateResult('equipment_completeness', GateResult::ABSTAIN, 'equipment_incomplete',
                'Equipment profile is incomplete; cannot recommend.', ['is_complete' => $complete]);
        }
        return new GateResult('equipment_completeness', GateResult::PASS, 'equipment_approved_complete',
            'Equipment profile is approved and complete.', []);
    }

    private function nullOrInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    private function nullOrFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (float) $v;
    }
}
