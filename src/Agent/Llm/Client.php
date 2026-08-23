<?php
declare(strict_types=1);

namespace Routlaw\Agent\Llm;

/**
 * T19 LLM client abstraction (FR-038/039, Decision #2/#11 lifted 2026-08-23).
 *
 * The local LLM is a REASONING TOOL only (FRD §2.2 "policy outside the model").
 * This interface is the only surface the harness touches; it cannot read/write
 * proposals, flags, or memory directly. All policy/status transitions stay in PHP.
 */
interface Client
{
    /** Default local model for the operator's 6 GB-class GPU (qwen3.5:4b, keyless Ollama). */
    public const DEFAULT_LOCAL_MODEL = 'qwen3.5:4b';

    /**
     * One chat turn against the local model.
     *
     * @param list<array<string,mixed>> $messages Prior messages (system/user/assistant).
     * @param array<string,mixed> $options e.g. num_ctx, temperature. May include 'tools'.
     * @return array<string,mixed> Decoded /v1/chat/completions message (role, content, tool_calls?).
     */
    public function chat(array $messages, array $options = []): array;
}
