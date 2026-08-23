<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * EgressPolicy — T17 outbound egress control (HAR-07/09, LLM07, SSRF defense).
 *
 * Each tool declares an `egress` host allowlist in config/agent_policy.php. A tool with
 * an empty egress list performs NO outbound network calls. Any URL whose host is not on
 * the tool's allowlist is blocked — enforced structurally in the tool wrapper, testable
 * without real network egress.
 *
 * Defense-in-depth: non-http(s) schemes (file://, gopher://) and link-local / private /
 * cloud-metadata addresses are always blocked regardless of allowlist (SSRF).
 */
final class EgressPolicy
{
    /** Addresses that must never be reachable from agent egress (SSRF). */
    private const BLOCKED_HOST_FRAGMENTS = [
        '169.254.169.254',   // cloud metadata
        'localhost', '127.0.0.1', '::1',
        'metadata.google.internal', 'metadata.internal',
    ];

    public function __construct(private ToolAllowlist $allowlist)
    {
    }

    /**
     * Whether the given URL may be fetched by the named tool.
     */
    public function isEgressAllowed(string $tool, string $url): bool
    {
        $allowedHosts = $this->allowlist->allowedEgress($tool);
        if ($allowedHosts === []) {
            return false; // no egress declared => no outbound
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return false; // non-http(s) schemes always blocked
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        foreach (self::BLOCKED_HOST_FRAGMENTS as $bad) {
            if ($host === $bad || str_ends_with($host, '.' . $bad)) {
                return false;
            }
        }
        return in_array($host, $allowedHosts, true);
    }
}
