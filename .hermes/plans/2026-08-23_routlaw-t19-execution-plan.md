# ROUTLAW T19 — Self-Correction, Learning & Non-Self-Promotion (with local LLM)

**Phase:** 6, task 3 of 3 (T17 done, T18 done). Per build-plan §4 Phase 6 line 113.
**Owner sign-off:** Decision #2/#11 lifted 2026-08-23 — local Ollama (keyless, self-hosted) authorized.
**Recon (verified live):** Ollama 0.32.15 on `127.0.0.1:11434` (HTTP 200). Default model `qwen3.5:4b`
(Q4_K_M, tools+thinking+vision, fits 6 GB GTX 1660 Ti). OpenViking `:1933` already reports `ollama: ok`.

## Scope (FRs)
- **FR-038** Self-correction verifier — after a run, verify output stayed within authorized scope / matched approved intent.
- **FR-039** Post-outcome learning — capture outcome-linked lessons (provenance-tagged, tenant-scoped).
- **FR-041** Proposals **cannot self-promote** — improvements are versioned proposals on a status lifecycle (draft → evaluated → approved → staged → rollback); the model can only *create a draft*, never advance its own status.

FR-040 (cross-tenant leakage gate) already shipped under T18.

## Hard boundary (non-negotiable, FRD §2.2 "policy outside the model")
The local LLM is a **reasoning tool only**. All of the following are **server-side PHP, never prompt-influenced**:
- Proposal status transitions (SelfPromotionGuard) — `draft` is the only status a model output may set.
- Self-correction PASS/FAIL verdict — rule-based PHP; LLM reasoning is advisory input, not the gate.
- Tenant/role scoping of all learning writes + retrieval (reuse T18 MemoryStore).

## Architecture
- `src/Agent/Llm/Client.php` — interface (`chat()`, `chatWithTools()`).
- `src/Agent/Llm/OllamaClient.php` — OpenAI-compatible `/v1/chat/completions`, keyless local default; `RL_AI_BASE_URL`/`RL_AI_MODEL` from config; `options.num_ctx` set high for tool use. No prompt can set policy/status.
- `src/Agent/SelfPromotionGuard.php` — proposal state machine; `createDraft()` (model-allowed) vs `advance()` (requires explicit approver id + payload hash; model output CANNOT call this).
- `src/Agent/SelfCorrectionVerifier.php` — rule-based verdict over a run's tool_calls+outputs vs the approved plan; LLM may annotate reasons, PHP decides.
- `src/Agent/OutcomeLearning.php` — stores outcome-linked learning via MemoryStore (tag `kind=learning`); LLM summarizes, PHP scopes/versions.
- `AgentHarness::reflect()` — optional LLM step (self-correction + learning capture) behind the guard; no-LLM path (stub) for unit tests.

## Migration 017 (Hostinger-safe, idempotent)
- `learning_proposals` (id, company_id, run_id NULL, status, payload_hash, payload_json, version, created_by, approved_by NULL, created_at) + `system_flags` reuse.
- `proposal_status_history` (id, proposal_id, from_status, to_status, actor_id, reason, created_at) for rollback/audit.
- ADD COLUMN only where extending existing tables; no reorder/rename (T17 migration-extension trap).

## Tests (TDD)
- Schema test: 017 applies, tables + columns present, existing column order preserved.
- SelfPromotionGuard: model-supplied "approve myself" payload → rejected; only `advance()` with approver id transitions; rollback works.
- SelfCorrectionVerifier: out-of-scope tool call → FAIL; in-scope + schema-valid → PASS; LLM annotation is advisory.
- OutcomeLearning: learning written tenant-scoped; cross-tenant retrieval leakage = 0 (same gate as T18).
- **LLM integration**: real `OllamaClient` test GATED on `ROUTLAW_LLM_INTEGRATION=1` (skipped by default so phpunit is GPU-free + fast); structural tests use a stub `Llm\Client`.

## Gate (before "done")
- `phpunit` green (new + 271 baseline), `phpstan` L8 on `src/Agent`, `guard.php` clean.
- Live LLM probe: one real `qwen3.5:4b` tool-call/reflect against `127.0.0.1:11434` succeeds (manual/integration, not in default suite).

## Out of scope
- T20+ (Phase 7), live Gmail OAuth, SSDLC — separate sign-off.
- Model training/fine-tune — inference only.
