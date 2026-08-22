# ROUTLAW — Phase 5 Session Handoff Prompt

> Generated 2026-08-21 from `2026-08-21_095000-routlaw-build-plan.md` (the authoritative
> build plan, 207 lines) using the `execution-ready-planning` skill. Phase 5 = T14–T16
> (drafting, approval queue, private documents). Phases 1–4 are DONE + PUSHED
> (origin/master HEAD 1f8c04c; 168 tests, PHPStan L8 clean, guard PASS).
>
> Phase 4 corrected the Phase-4 handoff doc's over-confident T10 assumption (hard gates
> cannot be fully "derived from existing carriers/equipment_profiles" — hazmat/HOS/ELD/CDL
> reference data does not exist; BR-005 enforced via ABSTAIN). That correction is banked in
> the Phase 4 commit history and the `routlaw` skill — do not re-litigate it.

Copy the fenced block below as the kickoff prompt for the Phase 5 session.

---

```text
Read C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md
FIRST and in FULL (207 lines). It is authoritative — do not re-derive, re-plan, or
re-audit. Phase 5 scope is the "### Phase 5" section (lines ~105–108): T14–T16.

You are continuing ROUTLAW (All-PHP 8.3.33 / XAMPP / MariaDB 10.11.14, no
Python/Docker/Redis). Phases 1–4 are complete and pushed to origin/master at
HEAD 1f8c04c: clean tree, 168 tests green, PHPStan L8 clean, guard.php PASS.
Load these skills at session start:
  - routlaw              (project conventions: stack, TDD gate, tenant-scoping,
                         bind_param pitfalls, BR-005 no-fabrication, Phase 3/4 recap —
                         REQUIRED; reloaded and current as of 2026-08-21)
  - test-driven-development     (RED→GREEN→verify per task)
  - requesting-code-review     (two-stage: spec compliance → code quality)
  - subagent-driven-development (fresh subagent per task, two-stage review)
  - php-static-analysis-patterns (PHPStan L8, bind_param pitfalls)
  - php-mariadb-schema-hardening (shared-hosting DB conventions, DEFINER=CURRENT_USER)

EXECUTION LOOP: Each Phase 5 task TDD-first (failing test → implement → verify).
Per task: spec-compliance review vs the FR/BR ID in the task heading, then
code-quality review. Commit per checkpoint. Do NOT push without explicit approval.

SCOPE (this session only): Phase 5 — T14 (FR-021 message drafting, editable,
source-linked, CANNOT self-send), T15 (FR-022/023/024 approval queue: payload-bound,
replay-safe, modified payload → new approval), T16 (FR-025/026/027/028 private
document upload: MIME/sig check, randomized key, no exec, no public path; classification
≠ legal validity; human verification; approval-gated sharing, no direct model share).
~8–10 bite-sized tasks. STOP at the Phase 5 exit gate: all Phase-5 tests green, guard
PASS, PHPStan L8 clean, no cross-tenant leakage in messages/documents/approvals, payload
tamper/replay rejected (FRD §19.3 analog for Phase 5). Do NOT proceed to Phase 6.
Report and wait.

NON-NEGOTIABLES:
- Secrets: RL_* env via gitignored config/secrets.local.php; guard.php must PASS.
  Never hardcode.
- Tenant scoping: every entity carries company_id from day one; write paths scoped
  (WHERE id=? AND company_id=?); every task has a cross-tenant isolation assertion.
- Parameterized SQL only (SEC-006). Verify schema against LIVE DB (throwaway
  routlaw_test_* / routlaw_verify_* databases), not file greps.
- Use /c/php83/php.exe (NOT XAMPP 8.2). Run phpunit + phpstan + guard after every
  task; capture ACTUAL exit codes (do NOT pipe phpstan/phpunit through tail/head —
  see routlaw skill "Verification" section — a piped command masks the real exit code).
- Soft-delete regulated entities (softDelete()) + audit event; never hard-delete.
- bind_param: build the types string from a params array and run
  DecisionService::assertBindArity (already in src/Decisions/DecisionService.php) —
  a count-only check does NOT catch a shifted type char that silently truncates a
  trailing column (burned Phase 4 until the guard was added).
- Do not touch the aiwebscapes-platform Docker stack (off-limits).

PHASE 5 SPECIFIC CONTRACTS (from build plan lines 105–108, verbatim FR IDs):
- T14 FR-021: message drafting MUST be editable, source-linked (link to the decision/
  load/lead it serves), and MUST NOT be able to self-send (outbound send requires a
  distinct human/approval action; the drafting service and the send path are separate
  and the draft store cannot trigger delivery on its own).
- T15 FR-022/023/024: approval request carries actor + action + resource +
  payload-hash + expiry. It is PAYLOAD-BOUND and REPLAY-SAFE: any modification of the
  payload invalidates the approval (new approval required). Test MUST prove approval
  replay and tampered-payload rejection.
- T16 FR-025/026/027/028: private document upload with MIME + signature check,
  randomized storage key, no executable content, no public web path (non-web dir
  outside docroot with a signed-access PHP endpoint — matches the locked ADR from
  2026-08-21). Classification is a label, NOT legal validity. Human verification +
  approval-gated sharing; the model/agent CANNOT directly share a document.

OPEN (report at exit, don't block): AI provider (#2/#11, recommend local Ollama,
needs owner sign-off — blocks only any live-LLM call, not Phase 5's drafting/queue/
document scaffolding). Hostinger TRIGGER grant unconfirmed. FRD PDF missing (docx only).
BRD R4 cites GenAI LLM Top 10 "2025" → superseded by 2026 (fix to "2026").

REPORT at end:
- Which tasks completed, with the commit for each.
- Phase 5 exit-gate results as ACTUAL command output (phpunit / phpstan / guard).
- Anything blocked, and what you need to unblock it.
```

---

## Notes for the controller (not part of the prompt)

- **Ground truth already banked** (from this session, do not re-verify): stack is
  All-PHP 8.3/XAMPP/MariaDB 10.11.14 (plan cites 10.4 — live probe wins); PHP binary
  `/c/php83/php.exe`; migrations `000`–`009` applied (Phase 4 added `007` cdl_status,
  `008` gate_results, `009` decision_cases + 5 related tables). Repo `docs/` committed.
- **Phase 5 is greenfield within the existing stack** (verified 2026-08-21): no
  `approval_queue`, `messages`/`message_drafts`, or `documents` tables or services exist
  yet. `src/Mail/` already exists — T14's "cannot self-send" guard should integrate with
  it, not duplicate it. Decision/observation/economics/gate services from Phase 4
  (`src/Decisions`, `src/Economics`, `src/Gates`) are the natural neighbors for T14–T16
  persistence and should be reused, not re-scaffolded.
