<?php
declare(strict_types=1);

namespace Routlaw\Loads;

/**
 * LoadExtractionService: structured load extraction (FR-013/BR-005).
 *
 * Deterministic, schema-driven extraction from UNTRUSTED text (email body, uploaded
 * doc, pasted load note). It is the local/rule-based implementation used until the
 * owner-approved AI provider (local Ollama, OPEN) is wired into the tool gateway —
 * the schema, confidence, and missing-field contract are provider-agnostic so an LLM
 * adapter can be dropped in behind the same interface.
 *
 * BR-005 / FR-013 HARD RULE: a field value is produced ONLY when the text actually
 * contains evidence for it. Absent fields are returned as null and enumerated in
 * `missing` — NEVER fabricated into a plausible value (no hallucinated completion).
 *
 * Per-field structure: { value, confidence: 'high'|'medium'|'low', provenance: 'text' }.
 * Tenant scoping is enforced by the caller (LoadService.persistExtractedLoad).
 * No Python, no Redis, no Docker — pure PHP 8.3.
 */
final class LoadExtractionService
{
    /** Extraction schema version (FR-013: extraction results are versioned). */
    public const EXTRACTION_VERSION = '1.0';

    /** Schema fields the extractor can populate (subset of loads columns). */
    private const FIELD_SPECS = [
        'origin_city'  => '/(?:\bpick\s*up\b|\borigin\b|\bfrom\b)[:\s,]*([A-Z][a-zA-Z.\' ]+?)(?:,|\b[A-Z]{2}\b|$)/i',
        'origin_state' => '/(?:\bpick\s*up\b|\borigin\b|\bfrom\b)[^.]*?\b([A-Z]{2})\b/i',
        'dest_city'    => '/(?:\bdeliver(?:y)?\b|\bdest\b|\bto\b)[:\s,]*([A-Z][a-zA-Z.\' ]+?)(?:,|\b[A-Z]{2}\b|$)/i',
        'dest_state'   => '/(?:\bdeliver(?:y)?\b|\bdest\b|\bto\b)[^.]*?\b([A-Z]{2})\b/i',
        'commodity'    => '/(?:\bcommodity\b|\bfreight\b|\bcargo\b|\bload\b)[:\s,]*([A-Za-z0-9 .,\'\-]+?)(?:\.|,|weight|rate|$)/i',
        'weight_lbs'   => '/(?:\bweight\b|\bwt\b)[:\s]*([0-9][0-9,]*)\s*(?:lbs?|pounds?)?/i',
        'posted_rate'  => '/(?:\brate\b|\bpay\b|\bprice\b)[:\s]*\$?\s*([0-9][0-9,]*(?:\.[0-9]{2})?)/i',
    ];

    /** Required fields for a minimum viable load (drive missing-field reporting). */
    private const REQUIRED = ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity', 'weight_lbs'];

    /**
     * Extract structured load facts from untrusted text (FR-013/BR-005).
     *
     * @return array{
     *   extraction_version: string,
     *   fields: array<string, array{value: mixed, confidence: string, provenance: string}>,
     *   missing: list<string>,
     *   overall_confidence: string,
     *   review_required: int
     * }
     */
    public function extract(string $text): array
    {
        $fields = [];
        $present = [];

        foreach (self::FIELD_SPECS as $field => $pattern) {
            $value = null;
            $confidence = 'low';
            if (preg_match($pattern, $text, $m) === 1) {
                $raw = trim($m[1]);
                if ($field === 'weight_lbs') {
                    $value = (int) str_replace(',', '', $raw);
                    $confidence = 'high';
                } elseif ($field === 'posted_rate') {
                    $value = (string) ((float) str_replace(',', '', $raw));
                    $confidence = 'high';
                } else {
                    // City/state/commodity: collapse whitespace, uppercase state later.
                    $value = $this->normalizeFreeText($raw, $field);
                    $confidence = $value === '' ? 'low' : 'high';
                }
            }

            $fields[$field] = [
                'value' => $value,
                'confidence' => $confidence,
                'provenance' => $value === null ? 'none' : 'text',
            ];
            if ($value !== null && $value !== '') {
                $present[$field] = true;
            }
        }

        // Missing-field reporting (BR-005: explicit, never hidden).
        $missing = [];
        foreach (self::REQUIRED as $req) {
            if (!isset($present[$req])) {
                $missing[] = $req;
            }
        }

        // Overall confidence: low if any required missing or any non-high, else high.
        $overall = 'high';
        if ($missing !== []) {
            $overall = 'low';
        } else {
            foreach ($fields as $f) {
                if (($f['value'] ?? null) !== null && $f['confidence'] !== 'high') {
                    $overall = 'medium';
                }
            }
        }
        $reviewRequired = $overall === 'low' ? 1 : 0;

        return [
            'extraction_version' => self::EXTRACTION_VERSION,
            'fields' => $fields,
            'missing' => $missing,
            'overall_confidence' => $overall,
            'review_required' => $reviewRequired,
        ];
    }

    private function normalizeFreeText(string $raw, string $field): string
    {
        $v = preg_replace('/\s+/', ' ', trim($raw));
        if ($v === null || $v === '') {
            return '';
        }
        if ($field === 'origin_state' || $field === 'dest_state') {
            // State tokens are already 2-letter from the pattern; normalize case.
            return strtoupper(substr($v, 0, 2));
        }
        return $v;
    }
}
