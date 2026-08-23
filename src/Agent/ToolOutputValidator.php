<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * ToolOutputValidator — T17 output schema validation (HAR-13, LLM05).
 *
 * Every tool declares a JSON Schema in config/agent_policy.php. This validator:
 *   1. Confirms the output is an object/array shape matching the declared schema
 *      (required keys present; declared property types correct).
 *   2. Scans all string leaves for injection markers (script/onerror/javascript:/
 *      data: URIs) and rejects output that carries an injection payload, because
 *      untrusted content must stay data-only (FR-012 / HAR-04).
 *
 * Rejected output is never passed downstream. A SHA-256 output_hash is computed for
 * the audit trail. This is defense-in-depth on top of ToolCallAuthorizer.
 */
final class ToolOutputValidator
{
    /** Injection markers that must never survive in tool/model output passed downstream. */
    private const INJECTION_PATTERNS = [
        '/<script\b/i', '/on(error|load|click|mouseover)\s*=/i', '#javascript:#i', '#data:text/html#i',
        '/<\s*iframe\b/i', '/<\s*img\b[^>]*on\w+\s*=/i',
    ];

    public function __construct(private ToolAllowlist $allowlist)
    {
    }

    /**
     * @param array<string,mixed> $output
     */
    public function validate(string $tool, array $output): ToolOutputResult
    {
        $schema = $this->allowlist->outputSchema($tool);
        $encoded = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', is_string($encoded) ? $encoded : '');

        // Tools without a declared schema are validated leniently (presence only).
        if ($schema === null) {
            return new ToolOutputResult(true, $hash, null, false);
        }

        // (1) Required fields present.
        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $key) {
                if (!array_key_exists($key, $output)) {
                    return new ToolOutputResult(false, $hash, "missing required field: {$key}", false);
                }
            }
        }

        // (2) Declared property types correct.
        $props = $schema['properties'] ?? [];
        if (is_array($props)) {
            foreach ($props as $key => $spec) {
                if (!array_key_exists($key, $output)) {
                    continue; // optional property
                }
                $expected = is_array($spec) ? ($spec['type'] ?? null) : null;
                if ($expected !== null && !$this->typeMatches($output[$key], $expected)) {
                    return new ToolOutputResult(false, $hash, "field {$key} type mismatch (expected {$expected})", false);
                }
            }
        }

        // (3) Injection scan on all string leaves.
        $sanitized = false;
        if ($this->containsInjection($output)) {
            return new ToolOutputResult(false, $hash, 'injection payload detected in tool output (HAR-04)', false);
        }

        return new ToolOutputResult(true, $hash, null, $sanitized);
    }

    private function typeMatches(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'integer' => is_int($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array', 'object' => is_array($value),
            default => true,
        };
    }

    /**
     * @param mixed $value
     */
    private function containsInjection($value): bool
    {
        if (is_string($value)) {
            foreach (self::INJECTION_PATTERNS as $pat) {
                if (preg_match($pat, $value) === 1) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($this->containsInjection($v)) {
                    return true;
                }
            }
        }
        return false;
    }
}
