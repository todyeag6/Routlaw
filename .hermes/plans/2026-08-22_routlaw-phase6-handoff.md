# ROUTLAW — Phase 6 Session Handoff Prompt

> Generated 2026-08-22 from `2026-08-21_095000-routlaw-build-plan.md` + Phase 5
> completion. Phase 5 is DONE + PUSHED (origin/master HEAD c018c64; 219 tests,
> PHPStan L8 clean, guard.php PASS). This handoff targets a fresh session and is
> structured per the `execution-ready-planning` skill.
>
> **Pre-condition:** Phase 5 exit gate verified live before this handoff was written.
> Do NOT re-derive the plan — the build plan (lines ~110–114) is authoritative.

## State snapshot (re-verify in first 5 min — trust nothing)
- Branch `master`, origin/master HEAD `c018c64` (Phase 5 T16.5), working tree clean.
- Stack locked: All-PHP **8.3.33** (`/c/php83/php.exe`, has ext-sodium), XAMPP/MariaDB 10.11.14.
  No Python/Docker/Redis. Migrations `000`–`015` applied.
- Key tables available for T16/T17 reuse: `documents`, `approval_requests`,
  `message_drafts`, `audit_events` (append-only via trigger BR-017), `agent_runs`,
  `tool_calls`, `async_jobs`, `users`/`companies`/`roles`/`permissions` +
  `role_permissions` + `user_role_assignments`.
- **Decision #2/#11 still OPEN** (AI provider + data-processing terms): owner has NOT
  signed off on a live LLM. **Do not make any live LLM call.** Phase 6 builds the
  *structural* harness (tool allowlist, authz, budgets, policy-outside-model) which is
  testable without an LLM. If the owner signs off mid-session, wire the provider in
  `config/autoload.php` only via env `RL_AI_PROVIDER`.

## Skills to load at session start
- `routlaw` (REQUIRED — lock, TDD gate, bind_param pitfall, tenant scoping, BR-005)
- `test-driven-development` (RED→GREEN→verify per task)
- `subagent-driven-development` (fresh subagent per task, two-stage review)
- `php-static-analysis-patterns` (PHPStan L8, mysqli)
- `php-mariadb-schema-hardening` (Hostinger-safe DDL, triggers)
- `ai-infra-audit` / `local-llm-agent-setup` (for the Ollama/local model wiring IF owner signs off)

## Scope (this session only): Phase 6 — T17 + T18
- **T17 (AG-01..11 + HAR-01..15):** secure agent harness — tool allowlist, per-call
  authz (FR-003 model), schema validation on every tool output, egress control, secrets
  isolation (opaque handles), resource budgets (step/token/cost/time), kill switch,
  version pinning, output sanitization (HAR-13/14). **LLM01–10 mitigations:**
  untrusted content isolated (FR-012, HAR-04), cross-tenant retrieval leakage = 0
  (§19.3), no self-grant of tools, bounded steps prevent infinite loops (FR-032).
- **T18 (FR-034/037/040):** memory entries — provenance-tagged,
  tenant/role-scoped retrieval BEFORE semantic search (LLM08), contradiction handling,
  expiry/revalidation (LLM04).

## Non-negotiables (carry Phase 5 forward)
- **TDD gate after EVERY task:** capture ACTUAL exit codes (no `| tail`/`head`):
  ```bash
  /c/php83/php.exe -l <file>
  /c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M > /tmp/ps.txt 2>&1; echo "PS=$?"
  /c/php83/php.exe vendor/bin/phpunit --no-coverage > /tmp/full.txt 2>&1; echo "PU=$?"
  /c/php83/php.exe scripts/guard.php; echo "GUARD=$?"
  ```
- `/c/php83/php.exe` only (NOT XAMPP 8.2).
- Tenant scoping: every row carries `company_id`; cross-tenant isolation is a
  release blocker — ASSERT leakage = 0 in at least one test.
- Parameterized SQL only (`assertBindArity` guard on every `bind_param`).
- Soft-delete + audit on regulated writes; append-only audit (trigger-enforced).
- `async_jobs` claim-lock is the queue — no Redis, no duplicate side effects
  (FR-033, NFR-005).
- **Policy outside the model:** agent policy/tool allowlist is read-only PHP,
  structurally incapable of being mutated by model output (mirrors Phase 4 T9
  `PolicyEnforcement` immutability).
- Do NOT push without explicit approval. Commit per checkpoint.

## Phase 6 spec contracts (verbatim FR/BR/HAR IDs)
- **T17 HAR-01/02/04/06/08/09/13/14 LLM01–10:** the harness MUST reject model attempts
  to grant itself tools, expand scope, or bypass authz. Tool allowlist is a
  server-side allowlist evaluated in app code (not prompt text). Budgets are
  hard-capped per agent run (FR-032). Untrusted email/doc/load text is data-only.
- **T17 LLM02/PRIV-004:** retrieval is tenant+role-scoped BEFORE semantic search.
  Cross-tenant retrieval leakage = 0 (§19.3 — this is a hard release gate).
- **T17 LLM06 (excessive agency):** payload-bound approvals gate any side effect that
  alters state (reuses T15 ApprovalService). Draft→sent, document-share, and any
  agent action that writes regulated entities REQUIRE a human-approved payload-bound
  approval.
- **T18 FR-034/036:** memory entries carry `provenance` (agent_run_id, tool_call_id)
  + `tenant_id`/`min_role`; contradictions are flagged, never silent overwritten.
- **T18 FR-040:** memory retrieval returns only entries whose tenant/role match the
  caller, filtered BEFORE ranking — test asserts zero cross-tenant leakage.

## STOP condition
Phase 6 exit gate: all Phase-6 tests green, guard PASS, PHPStan L8 clean,
cross-tenant retrieval leakage = 0, no model self-grant or scope-expansion in the
critical suite (§19.3 analog for Phase 6). **Do NOT proceed to Phase 7 (integrations)
without owner sign-off on the AI provider decision (#2/#11).** Report and wait.
```

## Open items to report
- Decision #12 (BRD R4 → GenAI LLM Top 10 **2026**; FRD PDF missing) — carry from Phase 5.
- Decision #2/#11 (AI provider + data-processing terms) — BLOCKS any live LLM call in T17.
  Local Ollama is the recommended default (keyless, self-hosted, homelab) but owner
  must sign off before `RL_AI_PROVIDER` is wired.
- Hostinger TRIGGER grant for the audit append-only triggers — unverified (carry).
