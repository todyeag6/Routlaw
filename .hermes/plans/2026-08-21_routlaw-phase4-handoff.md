# ROUTLAW — Phase 4 Session Handoff Prompt

> Generated 2026-08-21 from `2026-08-21_095000-routlaw-build-plan.md` (the authoritative
> build plan, 207 lines) using the `execution-ready-planning` skill. Phase 4 = T10–T13
> (hard gates, economics, decision alternatives). Phase 1–3 are DONE + PUSHED
> (origin/master HEAD e6fe080; 109 tests, PHPStan L8 clean, guard PASS).

Copy the fenced block below as the kickoff prompt for the Phase 4 session.

---

```text
Read C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md
FIRST and in FULL (207 lines). It is authoritative — do not re-derive, re-plan,
or re-audit. Phase 4 scope is the "### Phase 4" section (lines ~99–103): T10–T13.

You are continuing ROUTLAW (All-PHP 8.3.33 / XAMPP / MariaDB 10.11.14, no
Python/Docker/Redis). Phases 1–3 are complete and pushed to origin/master at
HEAD e6fe080: clean tree, 109 tests green, PHPStan L8 clean, guard.php PASS.
Load these skills at session start:
  - routlaw              (project conventions: stack, TDD gate, tenant-scoping,
                         bind_param/regex lessons, Phase 3 recap — REQUIRED)
  - test-driven-development     (RED→GREEN→verify per task)
  - requesting-code-review     (two-stage: spec compliance → code quality)
  - subagent-driven-development (fresh subagent per task, two-stage review)
  - php-static-analysis-patterns (PHPStan L8, bind_param pitfalls)
  - php-mariadb-schema-hardening (shared-hosting DB conventions, DEFINER=CURRENT_USER)

EXECUTION LOOP: Each Phase 4 task TDD-first (failing test → implement → verify).
Per task: spec-compliance review vs the FR/BR ID in the task heading, then
code-quality review. Commit per checkpoint. Do NOT push without explicit approval.

SCOPE (this session only): Phase 4 — T10 (FR-015/016 hard compatibility gates),
T11 (FR-052/053 total-mile/time economics, no fabricated values), T12
(FR-054/055/056/059 decision alternatives with reasons/risks/confidence), T13
(FR-057/058/060/061 counterparty/facility observations + predicted-vs-actual
variance). ~8–12 bite-sized tasks. STOP at the Phase 4 exit gate: all Phase-4
tests green, guard PASS, PHPStan L8 clean, no cross-tenant leakage, hard-gate
violation rate = 0 in the critical suite (FRD §19.3). Do NOT proceed to Phase 5.
Report and wait.

NON-NEGOTIABLES:
- Secrets: RL_* env via gitignored config/secrets.local.php; guard.php must PASS.
  Never hardcode.
- Tenant scoping: every entity carries company_id from day one; write paths
  scoped (WHERE id=? AND company_id=?).
- Parameterized SQL only (SEC-006). Verify schema against LIVE DB, not file greps.
- Use /c/php83/php.exe (NOT XAMPP 8.2). Run phpunit + phpstan + guard after every
  task; read ACTUAL output, not proxies.
- Soft-delete regulated entities (softDelete()), never hard-delete.
- Do not touch the aiwebscapes-platform Docker stack (off-limits).

OPEN (report at exit, don't block): AI provider (#2/#11, recommend local Ollama,
needs owner sign-off — blocks T9 live LLM call only, not Phase 4); Hostinger
TRIGGER grant (confirm plan grants it); FRD PDF missing (docx only); BRD R4 cites
GenAI LLM Top 10 "2025" → superseded by 2026.

CONFIRM BEFORE T10: Plan §Phase 4 lists hard-gate inputs (weight/dimension/
equipment/hazmat/CDL/HOS/ELD). The dimension/equipment reference data source is
not yet loaded — default assumption: derive gates from the existing carriers/
equipment_profiles tables + FRD §13 inputs; do NOT scaffold a new reference
service. Confirm this default, then proceed without further check-ins.

REPORT at end:
- Which tasks completed, with the commit for each.
- Phase 4 exit-gate results as ACTUAL command output (phpunit / phpstan / guard).
- Anything blocked, and what you need to unblock it.
```

---

## Notes for the controller (not part of the prompt)

- **Ground truth already banked** (from this session, do not re-verify): stack is
  All-PHP 8.3/XAMPP/MariaDB 10.11.14 (plan cites 10.4 — live probe wins); PHP binary
  `/c/php83/php.exe`; migrations `000`–`006` applied; `leads`/`source_records`/`loads`
  tables live-verified. Repo `docs/` committed at `0578321`.
- **Phase 4 decision alternatives (T12)** and **economics (T11)** must reproduce from
  stored input versions and **abstain rather than fabricate** missing mandatory inputs
  (same BR-005 / no-hallucination discipline as T8). Carry that contract forward.
- **Hard gates (T10)** are deterministic and run BEFORE scoring; a hard-gate violation
  prevents `recommended` status and routes to human review — not a soft penalty.
- **Do NOT** begin Phase 5 (T14+ drafting/approval queue) in this session; cut at the
  Phase 4 exit gate per the user's stop instruction.
