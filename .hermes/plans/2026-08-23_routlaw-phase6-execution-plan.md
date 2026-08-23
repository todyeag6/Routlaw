# ROUTLAW — Phase 6 Execution Plan (T17 + T18)

> Authoritative for the executing session. Read in FULL before starting. Do NOT re-derive, re-plan, or re-audit the build plan; the `routlaw` skill + this file are the source of truth.
> Generated 2026-08-23 from: `2026-08-21_095000-routlaw-build-plan.md` (§4 Phase 6 lines 110–113, §5.2 LLM01–10 lines 132–143, §6 gates lines 174–181), `2026-08-22_routlaw-phase6-handoff.md`, and a LIVE repo/schema probe performed while drafting this plan.
> Pre-condition: Phase 5 DONE + PUSHED (origin/master `c018c64`; 219 tests green; PHPStan L8 clean; guard.php PASS). Phase 6 builds the *structural* harness — testable with ZERO live LLM calls.

---

## §0 Ground Truth (verified while drafting, not assumed)

| Prior claim (handoff / kickoff) | Verified reality (this session) | Impact |
|---|---|---|
| "HEAD `c018c64` (Phase 5 T16.5)" | ✅ `git log` top = `c018c64` | Repo matches. |
| "working tree clean" | ✅ only the kickoff `.md` is untracked | Safe to commit per checkpoint. |
| "Migrations `000`–`015` applied" | ✅ source files `000_init_foundation` … `015_document_token_expiry` exist (16 files) | Source confirmed. **Live-DB application is NOT yet proven** → P6-0 must run the schema probe and apply `016` to the test DB. |
| "`agent_runs`, `tool_calls`, `async_jobs` available for T17/T18" | ✅ all three exist. `agent_runs` cols: `id, company_id, correlation_id, workflow_type, agent_version, policy_version, status, started_at, finished_at, created_at, updated_at`. `tool_calls` cols: `id, company_id, agent_run_id, tool_name, request_hash, result_status, created_at`. | T17 reuses these. **No budget/kill-switch columns and no allowlist-decision columns exist yet** → P6-1 adds them via `016`. |
| "memory entries table exists" | ❌ **FALSE** — no `memory_entries` / `agent_memory` table anywhere in `migrations/`. | T18 requires NEW schema. P6-1 authors `016_agent_harness.sql` including `memory_entries`. The handoff's "existing schema available for T18" line is WRONG for memory. |
| "Local Ollama keyless harness" | ✅ FRD requires it; Decision #2/#11 OPEN; owner NOT signed off. | No live LLM call in any task. Provider wired only via `RL_AI_PROVIDER` env if owner signs off mid-session (they have not). |
| "219 tests green baseline" | Carried from handoff; not re-run here. | P6-11 gate asserts full suite stays green (protect baseline, not just new tests). |

---

## §1 Open Questions (GATES — resolve before the task they name; do not invent answers)

**OQ-1 — T19 scope.** The build-plan (line 113) defines Phase 6 = **T17 + T18 + T19** (T19 = self-correction verifier, post-outcome learning, proposals-that-cannot-self-promote, FR-038/039/040/041). The handoff narrows *this session* to **T17 + T18 only** and its STOP condition forbids Phase 7 but is silent on T19. **Decision needed:** is T19 deferred to a later session, or silently dropped? This plan scopes T17+T18 only and ends at the Phase 6 exit gate; T19 is NOT included. If owner wants T19 in this session, it must be appended as a separate plan after sign-off.

**OQ-2 — FR-040 / FR-ID drift.** Three docs disagree on FR→task ownership:
- build-plan T18 (112): `FR-034..037`, with the cross-tenant leakage test.
- build-plan T19 (113): `FR-038/039/040/041`.
- handoff T18 spec-contracts (lines 75–78): cite `FR-034/036` **and `FR-040`** ("memory retrieval returns only entries whose tenant/role match the caller, filtered BEFORE ranking — test asserts zero cross-tenant leakage").
- kickoff T18 scope (line 40): `FR-034/037/040`.

`FR-040` is claimed by BOTH T18 and T19 across the docs, and the leakage=0 gate is described under both. The FRD (source of truth) is not in this repo as text. **Recommended resolution (record as ADR `docs/adr/NNNN-phase6-fr-map.md`):** T18 owns memory *storage + tenant/role-filtered retrieval* and the cross-tenant leakage = 0 gate; map the leakage gate to `FR-040` under T18 (consistent with handoff lines 75–78 and kickoff line 40). Treat `FR-038/039/041` (self-correction / learning / self-promote) as T19. The executing session MUST NOT proceed on P6-9's retrieval gate until this ADR exists. **Do not guess the FR numbers — owner confirms.**

---

## §2 Scope (this session only)

**In:** T17 (secure agent harness — structural, no LLM) + T18 (tenant/role-scoped memory). All testable without a live model.
**Out:** T19 (learning/self-promote), Phase 7 (integrations), any live LLM call, `RL_AI_PROVIDER` wiring.
**Stop point:** Phase 6 exit gate (P6-11). Report and WAIT. Do NOT proceed to T19 or Phase 7 without owner sign-off on Decision #2/#11.

**Non-negotiables (carry forward — easiest to get wrong):**
- `/c/php83/php.exe` ONLY (XAMPP 8.2 lacks sodium; breaks crypto + gate).
- TDD gate after EVERY task, capture real exit codes (no `| tail`/`head` masking):
  ```bash
  /c/php83/php.exe -l <file>
  /c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M > /tmp/ps.txt 2>&1; echo "PS=$?"
  /c/php83/php.exe vendor/bin/phpunit --no-coverage > /tmp/full.txt 2>&1; echo "PU=$?"
  /c/php83/php.exe scripts/guard.php; echo "GUARD=$?"
  ```
- Tenant scoping: every row carries `company_id`; cross-tenant leakage is a release blocker — every task has an isolation assertion. Memory retrieval filters `company_id` + `min_role` BEFORE any search (LLM02/PRIV-004, LLM08).
- `assertBindArity` (arity AND per-param type) on every `bind_param` — count-only checks miss silent column corruption (burned a prior live session).
- Parameterized SQL only — **no model-generated SQL** (SEC-006).
- Policy/allowlist is server-side PHP, immutable by model output (mirrors T9 `PolicyEnforcement`). Structural, not prompt text.
- Soft-delete + audit on regulated writes; append-only audit (trigger BR-017).
- `async_jobs` claim-lock = the queue (no Redis; no duplicate side effects — FR-033/NFR-005).
- Agent write to a regulated entity → human-approved, payload-bound approval via T15 `ApprovalService`.
- Do NOT push without explicit approval. Commit per checkpoint.

---

## §3 Tasks (10–12 budget; one session, end at gate)

### P6-0 — Ground-truth live re-verify + git identity + DB probe
- Run `git status`, confirm `HEAD=c018c64`, tree clean except kickoff.
- Confirm git identity set repo-local (`git config user.name/email`); if empty, set repo-local only.
- Run the live schema probe: if `scripts/db_schema_probe.php` exists, run it against the test DB; else write a throwaway probe in `$LOCALAPPDATA/Temp/` that reads `information_schema` for `agent_runs`/`tool_calls`/`async_jobs` columns + trigger DEFINER on `audit_events`. **Confirm 000–015 actually applied** and capture the EXACT current columns of `agent_runs`/`tool_calls` so `016` does not double-add.
- Record findings (no code change yet). Gate: probe output captured; column inventory saved to `docs/phase6-schema-probe.md`.

### P6-1 — Migration `016_agent_harness.sql` (new schema, Hostinger-safe)
- Extend `agent_runs`: `ADD COLUMN IF NOT EXISTS step_count INT UNSIGNED NOT NULL DEFAULT 0`, `token_estimate INT UNSIGNED NOT NULL DEFAULT 0`, `cost_cents INT UNSIGNED NOT NULL DEFAULT 0`, `budget_status ENUM('ok','exceeded','killed') NOT NULL DEFAULT 'ok'`.
- Extend `tool_calls`: `ADD COLUMN IF NOT EXISTS allowed TINYINT(1) NOT NULL DEFAULT 1`, `deny_reason VARCHAR(255) NULL`, `output_hash VARCHAR(64) NULL`, `sanitized TINYINT(1) NOT NULL DEFAULT 0`.
- New `system_flags` (`key` VARCHAR(96) PK, `value` JSON NULL, `company_id` INT UNSIGNED NULL, `updated_at`) — hosts the global kill switch (`key='agent_kill_switch'`).
- New `memory_entries` (T18): `id, company_id, min_role TINYINT UNSIGNED NOT NULL DEFAULT 1, content MEDIUMTEXT, provenance_json JSON (agent_run_id, tool_call_id, source), contradiction_of BIGINT UNSIGNED NULL, status ENUM('active','contradicted','expired') NOT NULL DEFAULT 'active', expires_at DATETIME NULL, revalidated_at DATETIME NULL, created_at, updated_at`, FKs to `companies` + `agent_runs` (CASCADE), keys `idx_mem_company`, `idx_mem_role`, `idx_mem_status`. (Semantic search is pluggable — see P6-9; default impl uses MariaDB FTS or a scored `LIKE`, NOT a real embedding model, because no Python/Redis/LLM in stack.)
- Conventions: `utf8mb4`, `IF NOT EXISTS`/`ADD COLUMN IF NOT EXISTS`, `DEFINER=CURRENT_USER` on any trigger (none required here), idempotent re-apply. Apply to the throwaway test DB; re-run probe to confirm.
- Gate: `php -l` clean; probe shows new cols/tables; no regression in existing schema.

### P6-2 — T17 ToolAllowlist (immutable, server-side PHP) — HAR-01/02/04, LLM06
- `src/Agent/ToolAllowlist.php`: allowlist loaded from a **hardcoded PHP config array** (`config/agent_policy.php`, not DB, not prompt). `isAllowed(string $tool, string $agentVersion, string $policyVersion): bool`; `listFor(string $agentVersion): array`.
- Immutability test (mirrors T9 `PolicyEnforcement`): feed a crafted "model output" payload attempting to add/remove a tool or rewrite the allowlist; assert the in-memory + on-disk snapshot is **byte-for-byte unchanged** (injection fixture). Assert a tool not in the list is rejected even if the model "claims" it exists.
- Gate: TDD red→green; self-grant attempt blocked; snapshot immutable; PHPStan L8 clean.

### P6-3 — T17 ToolCallAuthorizer (per-call authz + role/permission + ApprovalService reuse) — HAR-01/02/09, LLM06
- `src/Agent/ToolCallAuthorizer.php`: for each tool call, evaluate (a) allowlist membership, (b) caller `company_id` + `role`, (c) required `permission` code per tool (new seed perms if needed), (d) if the tool writes a regulated entity → require a payload-bound `ApprovalRequest` via T15 `ApprovalService` (reuse, do not re-implement).
- On deny: record `tool_calls` row with `allowed=0`, `deny_reason`, `result_status='blocked'`, and emit an `audit_events` row (`actor_type='agent'`). **No side effect executes.**
- Test: a denied call must NOT perform its write (assert DB state unchanged); a regulated write without an approved payload-bound approval is blocked; a model self-grant (claiming a tool not in allowlist) is blocked.
- Gate: TDD; blocked-call-no-side-effect test passes; audit row present; PHPStan L8.

### P6-4 — T17 ToolOutputValidator (schema validation) — HAR-13, LLM05
- `src/Agent/ToolOutputValidator.php`: every tool declares a JSON Schema (in `config/agent_policy.php`); validator rejects output that fails schema. Record `output_hash` on `tool_calls`.
- Test: malformed tool output rejected (not passed to model/downstream); output containing injection payload is rejected OR flagged; valid output passes + hash recorded.
- Gate: TDD; malformed/injection rejection verified; PHPStan L8.

### P6-5 — T17 OutputSanitizer — HAR-14, LLM05, SEC-005/014
- `src/Agent/OutputSanitizer.php`: encode model/tool text before HTML context; strip/escape unsafe markup; validate links/attachments. Mark `tool_calls.sanitized=1` when applied.
- Test: XSS payload (`<script>`, `onerror=`, `javascript:`) in model output is neutralized on encode; safe text passes through unaltered semantically.
- Gate: TDD; XSS neutralization verified; PHPStan L8.

### P6-6 — T17 BudgetEnforcer + KillSwitch — HAR-08, FR-032, LLM10, SEC-017
- `src/Agent/BudgetEnforcer.php`: per `agent_run` hard caps for steps / tokens / cost / time / concurrency (from `config/agent_policy.php`). Increments `agent_runs.step_count` etc.; on exceed → set `budget_status='exceeded'`, halt run, audit.
- `src/Agent/KillSwitch.php`: reads `system_flags['agent_kill_switch']`; when active, **no** tool executes for any run; sets `budget_status='killed'`.
- Test: exceeding step budget halts the run (no further tool executes); kill switch active → first tool call blocked for all runs; budgets are per-run, not global.
- Gate: TDD; budget-exceeded + kill-active verified; PHPStan L8.

### P6-7 — T17 SecretHandleService (secrets isolation) — HAR-06, LLM07, SEC-008/009
- `src/Agent/SecretHandleService.php`: tools receive **opaque handles** (`sec_*` token); real secret resolved server-side only from `config/secrets.local.php` (or the secrets store), never returned to the model. Handle is unguessable + non-reversible (random token map, not an encoding of the secret).
- Test: model-facing tool input contains only the opaque handle; resolving the handle server-side returns the secret; the handle cannot be reversed to the secret; a handle the model fabricates is rejected.
- Gate: TDD; no-raw-secret + non-reversible verified; PHPStan L8.

### P6-8 — T17 EgressPolicy + PolicyEnforcement snapshot immutability — HAR-07/09, LLM07
- `src/Agent/EgressPolicy.php`: each tool declares allowed egress (host allowlist); a tool attempting an out-of-policy URL is blocked. (Structural: enforce in the tool-wrapper, testable without real network.)
- Reuse T9 injection-fixture pattern: assert the combined allowlist + egress + authz policy snapshot is byte-for-byte unchanged after a model-attempted mutation (policy-outside-model, LLM07).
- Test: out-of-policy egress blocked; policy snapshot immutable under injection attempt.
- Gate: TDD; both verified; PHPStan L8.

### P6-9 — T18 MemoryStore (provenance, tenant/role-filtered retrieval, contradiction, expiry) — FR-034/035/036/037 (+ leakage gate per OQ-2 ADR)
- `src/Memory/MemoryStore.php` + `MemoryEntry.php` value object: `write(content, company_id, min_role, provenance{agent_run_id, tool_call_id, source})`; `retrieve(caller_company_id, caller_role, query)` → **filter `WHERE company_id = ? AND min_role <= ?` BEFORE any semantic/FTS scoring** (LLM08, PRIV-004). Contradiction: writing an entry that conflicts with an existing active entry sets `status='contradicted'` on the prior one + links `contradiction_of` — never silent overwrite (LLM04). Expiry: `expires_at` + `revalidated_at` enforced on read (LLM04).
- Test (HARD GATE, §19.3 analog): seed entries for company A (roles 1–5) and company B; `retrieve` as company A dispatcher MUST return **zero** company-B rows and only rows with `min_role <= caller_role`. Assert leakage count = 0. Contradiction test: second conflicting write flags the first, both retrievable with status. Expiry test: expired entry excluded.
- Gate: TDD; **cross-tenant leakage = 0 assertion present and passing**; OQ-2 ADR exists before this task runs.

### P6-10 — AgentHarness orchestration integration (no LLM) — ties T17+T18
- `src/Agent/AgentHarness.php`: per run → load allowlist + policy → for each step: authorize → budget check (kill-switch) → execute stub tool → validate output → sanitize → (if memory write) route through `MemoryStore` with provenance → record `tool_calls` + `audit_events`, all `company_id`-scoped. Run loop is bounded by budget (no infinite loop, FR-032).
- Drive with **stub tools only** (no live LLM): a fake "model" that emits a self-grant attempt, an out-of-policy egress, a regulated write without approval, and a cross-tenant memory read — assert each is blocked and leakage = 0.
- Test: the critical-suite assertion — **no model self-grant or scope-expansion succeeds**; all blocked paths emit audit rows; `company_id` isolation holds end-to-end.
- Gate: TDD; critical-suite self-grant/scope-expansion = 0; PHPStan L8.

### P6-11 — Phase 6 exit gate (STOP here)
- Run full gate (capturing real exit codes): `php -l` on all new files; PHPStan L8 clean on `src/`; full phpunit `--no-coverage` GREEN (baseline 219 preserved, no regression); `scripts/guard.php` PASS.
- Assert: cross-tenant retrieval leakage = 0 (P6-9 + P6-10); no model self-grant/scope-expansion in critical suite; `RL_AI_PROVIDER` NOT wired (no live LLM); `agent_runs`/`tool_calls` budget + decision columns present and used.
- Commit the final checkpoint. **Report status and WAIT.** Do NOT start T19 or Phase 7.

---

## §4 Skills to load at session start
`routlaw` (REQUIRED), `test-driven-development`, `subagent-driven-development`, `php-static-analysis-patterns`, `php-mariadb-schema-hardening`. Load `ai-infra-audit` / `local-llm-agent-setup` ONLY if owner signs off the AI-provider decision mid-session (they have not).

## §5 What NOT to do
- No live LLM call. No `RL_AI_PROVIDER` wiring. No real model generation anywhere.
- Do not re-implement `ApprovalService` (T15) or `PolicyEnforcement` (T9) — reuse them.
- Do not create memory embeddings / vector infra (stack has no Python/Redis/LLM) — pluggable interface, FTS/LIKE default.
- Do not push without explicit approval.
- Do not resolve OQ-1/OQ-2 by guessing — record ADR / ask owner.
