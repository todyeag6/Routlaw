<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * A tool the agent harness can invoke. Stub tools (no LLM) implement this to prove the
 * policy pipeline end-to-end. Real tools (carrier.lookup, etc.) will implement the same
 * interface when live LLM wiring is enabled after owner sign-off.
 */
interface AgentTool
{
    public function name(): string;
    /**
     * @param array<string,mixed> $args
     * @return mixed raw tool output (will be schema-validated + sanitized by the harness)
     */
    public function run(array $args, SecretHandleService $secrets): mixed;
}
