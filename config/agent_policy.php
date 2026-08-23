<?php
declare(strict_types=1);

/**
 * config/agent_policy.php — ROUTLAW T17 agent tool policy (server-side, authoritative).
 *
 * This file is the SOLE source of truth for which tools the agent harness may invoke,
 * and under what conditions. It is loaded by Routlaw\Agent\ToolAllowlist at runtime.
 *
 * CRITICAL (HAR-04 / LLM06 / FR-012): this array is read-only at runtime. There is NO
 * code path by which model output can append, remove, or rewrite an entry. Editing this
 * file is a human/role-gated administration action (permission 'agent.policy.change'),
 * never a content-ingestion action. The byte-for-byte immutability of this file is an
 * explicit test assertion (ToolAllowlistTest::test_model_cannot_mutate_allowlist_or_policy_file).
 *
 * @return array{agent_versions: array<string,array{tools: list<string>}>, policy_versions: array<string,array{tools: list<string>}>, tools: array<string, array{requires_approval: bool, required_permission: string, writes_regulated: bool, egress: list<string>, output_schema: array}>}
 */
return [
    // MVP agent + policy versions (extend as needed; harness validates against these).
    'agent_versions' => [
        'agent.mvp.v1' => ['tools' => [
            'load.search', 'carrier.lookup', 'extraction.run', 'draft.message',
            'carrier.update', 'document.share', 'memory.write', 'memory.read',
        ]],
    ],
    'policy_versions' => [
        'policy.mvp.v1' => ['tools' => [
            'load.search', 'carrier.lookup', 'extraction.run', 'draft.message',
            'carrier.update', 'document.share', 'memory.write', 'memory.read',
        ]],
    ],
    // Per-tool policy. `egress` hosts are only relevant for tools that make outbound calls.
    'tools' => [
        'load.search' => [
            'requires_approval' => false,
            'required_permission' => 'loads.create',
            'writes_regulated' => false,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['load_id' => ['type' => 'integer']], 'required' => ['load_id']],
        ],
        'carrier.lookup' => [
            'requires_approval' => false,
            'required_permission' => 'carriers.view',
            'writes_regulated' => false,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['carrier_id' => ['type' => 'integer']], 'required' => ['carrier_id']],
        ],
        'extraction.run' => [
            'requires_approval' => false,
            'required_permission' => 'loads.create',
            'writes_regulated' => false,
            'egress' => ['api.routlaw.local'],
            'output_schema' => ['type' => 'object', 'properties' => ['extraction_id' => ['type' => 'integer']], 'required' => ['extraction_id']],
        ],
        'draft.message' => [
            'requires_approval' => false,
            'required_permission' => 'approvals.outbound',
            'writes_regulated' => false,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['draft_id' => ['type' => 'integer']], 'required' => ['draft_id']],
        ],
        'carrier.update' => [
            // Writes a regulated entity (carrier profile) -> requires a payload-bound approval.
            'requires_approval' => true,
            'required_permission' => 'carriers.edit',
            'writes_regulated' => true,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['carrier_id' => ['type' => 'integer']], 'required' => ['carrier_id']],
        ],
        'document.share' => [
            'requires_approval' => true,
            'required_permission' => 'documents.share',
            'writes_regulated' => true,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['document_id' => ['type' => 'integer']], 'required' => ['document_id']],
        ],
        'memory.write' => [
            'requires_approval' => false,
            'required_permission' => 'loads.create',
            'writes_regulated' => false,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer'], 'content' => ['type' => 'string']], 'required' => ['entry_id', 'content']],
        ],
        'memory.read' => [
            'requires_approval' => false,
            'required_permission' => 'carriers.view',
            'writes_regulated' => false,
            'egress' => [],
            'output_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ],
    ],
];
