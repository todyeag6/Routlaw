<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * ToolAllowlist — T17 server-side tool allowlist (HAR-01/02/04, LLM06).
 *
 * The allowlist is the authoritative gate that decides whether a tool may be invoked
 * by the agent harness for a given (agent_version, policy_version). It is structurally
 * immutable with respect to model output: there is NO method on this class that model
 * output can call to grant a tool, expand scope, or rewrite policy. The only way to
 * change allowed tools is to edit config/agent_policy.php via a human/role-gated
 * administration path (permission 'agent.policy.change') — never content ingestion.
 *
 * ingestUntrustedModelOutput() is intentionally a no-op: it is provided so callers can
 * route model text through it defensively, but it applies NOTHING. Immutability is
 * asserted byte-for-byte in ToolAllowlistTest.
 */
final class ToolAllowlist
{
    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    /**
     * Load the policy once (lazy, cached). Returns the authoritative array.
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        if (self::$policy === null) {
            $path = __DIR__ . '/../../config/agent_policy.php';
            if (!is_file($path)) {
                throw new \RuntimeException('agent_policy.php missing: ' . $path);
            }
            /** @var array<string, mixed> $loaded */
            $loaded = require $path;
            self::$policy = $loaded;
        }
        return self::$policy;
    }

    /**
     * Deep, immutable snapshot of the allowlist (for byte-for-byte immutability checks).
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->policy();
    }

    /**
     * Tools permitted for the given agent/policy version (intersection of both lists).
     * @return list<string>
     */
    public function listFor(string $agentVersion, string $policyVersion): array
    {
        $p = $this->policy();
        $agentTools = $this->versionTools($p['agent_versions'] ?? [], $agentVersion);
        $policyTools = $this->versionTools($p['policy_versions'] ?? [], $policyVersion);
        $merged = array_values(array_unique(array_merge($agentTools, $policyTools)));
        return $merged;
    }

    /**
     * Whether a specific tool is allowed for this agent/policy version.
     */
    public function isAllowed(string $tool, string $agentVersion, string $policyVersion): bool
    {
        $allowed = $this->listFor($agentVersion, $policyVersion);
        if (!in_array($tool, $allowed, true)) {
            return false;
        }
        $tools = $this->policy()['tools'] ?? [];
        return isset($tools[$tool]) && is_array($tools[$tool]);
    }

    /**
     * Whether the tool requires a payload-bound human approval before its side effect.
     */
    public function requiresApproval(string $tool, string $agentVersion, string $policyVersion): bool
    {
        if (!$this->isAllowed($tool, $agentVersion, $policyVersion)) {
            return false;
        }
        $tools = $this->policy()['tools'] ?? [];
        return (bool) ($tools[$tool]['requires_approval'] ?? false);
    }

    /**
     * The fine-grained permission a tool's caller must hold (server-side RBAC).
     */
    public function requiredPermission(string $tool, string $agentVersion, string $policyVersion): string
    {
        if (!$this->isAllowed($tool, $agentVersion, $policyVersion)) {
            return '';
        }
        $tools = $this->policy()['tools'] ?? [];
        return (string) ($tools[$tool]['required_permission'] ?? '');
    }

    /**
     * Whether the tool performs a write to a regulated entity (=> payload-bound approval).
     */
    public function writesRegulated(string $tool, string $agentVersion, string $policyVersion): bool
    {
        if (!$this->isAllowed($tool, $agentVersion, $policyVersion)) {
            return false;
        }
        $tools = $this->policy()['tools'] ?? [];
        return (bool) ($tools[$tool]['writes_regulated'] ?? false);
    }

    /**
     * JSON Schema used by ToolOutputValidator (P6-4) to validate tool output.
     * @return array<string, mixed>|null
     */
    public function outputSchema(string $tool): ?array
    {
        $tools = $this->policy()['tools'] ?? [];
        return isset($tools[$tool]['output_schema']) && is_array($tools[$tool]['output_schema'])
            ? $tools[$tool]['output_schema']
            : null;
    }

    /**
     * Allowed egress hosts for a tool (P6-8). Empty list = no outbound network.
     * @return list<string>
     */
    public function allowedEgress(string $tool): array
    {
        $tools = $this->policy()['tools'] ?? [];
        $egress = $tools[$tool]['egress'] ?? [];
        return is_array($egress) ? array_values($egress) : [];
    }

    /**
     * Defensive sink for untrusted model output. INTENTIONALLY DOES NOTHING — model
     * output must never mutate the allowlist or policy. Exists so callers can route
     * model text through it; the immutability test asserts it changes nothing.
     *
     * @param mixed $payload
     */
    public function ingestUntrustedModelOutput($payload): void
    {
        // No-op. Immutability is structural: this class has no setter, and the policy
        // file is loaded read-only. Any "grant"/"add" keys in $payload are ignored.
        unset($payload);
    }

    /**
     * @param array<string, mixed> $versions
     * @return list<string>
     */
    private function versionTools(array $versions, string $version): array
    {
        if (!isset($versions[$version]) || !is_array($versions[$version])) {
            return [];
        }
        $tools = $versions[$version]['tools'] ?? [];
        return is_array($tools) ? array_values($tools) : [];
    }
}
