# ROUTLAW — Phase 2 Handoff Kickoff (fresh session)

> **Source of truth:** Read `C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md` (207 lines) in FULL first. It is authoritative; do not re-derive, re-plan, or re-audit. This file is the launch prompt only.

## 0. Ground Truth (verified against the machine)

| Prior plan said | Verified reality | Impact |
|---|---|---|
| Phase 1 pending | Phase 1 complete, committed at `c8e9897` (47 tests green, PHPStan L8 clean, guard PASS) | Build on Phase 1 foundation (T1/T2/T3 done) |
| Need PHPStan install | Installed at `vendor/bin/phpstan`, level 8 config in `phpstan.neon` | Use `C:/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress` |
| Need composer | Installed at `C:/php83/composer` | Use `C:/php83/php.exe C:/php83/composer` for deps |
| Stack locked to PHP 8.3 | `C:/php83/php.exe` is PHP 8.3.33 with ext-sodium | All analysis + tests use this binary |

**First action in the new session:** re-confirm env:
```bash
cd C:/xampp/htdocs/Routlaw
git log --oneline -3
C:/php83/php.exe vendor/bin/phpunit --no-coverage   # expect 47, 1 skipped
C:/php83/php.exe scripts/guard.php                   # expect exit 0
C:/php83/php.exe vendor/bin/phpstan analyse src/      # expect OK
```

## 1. Plan path + authority

- **Build plan**: `C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md`
- **Phase 2 section**: §4 lines 89-93 — T4 carrier signup, T5 equipment profiles, T6 cost profiles
- **Phase 1 exit gate**: §4 line 87 — T1/T2/T3 complete with negative tests + audit immutability
- **Requirement IDs to cite verbatim**: FR-004/005/006/007/016/051/052, BR-001/021, MOD-04/05, SEC-010, NFR-001/002/003

## 2. Skills to load (by name)

- `test-driven-development` — RED→GREEN per task, vertical slices, watch it fail first
- `requesting-code-review` — two-stage review (spec compliance → code quality) before each commit
- `php-static-analysis-patterns` — PHPStan L8 / PHP 8.3 typed-array patterns
- `phpstan-stubs-over-bootstrap` — PHPStan config using `dynamicConstantNames`, not `bootstrapFiles`

## 3. Execution loop (per task)

1. Write the **failing test first** (RED). Run it; confirm it fails for the right reason.
2. Implement **minimal** code to pass (GREEN). Run it; confirm pass.
3. Run full suite (`C:/php83/php.exe vendor/bin/phpunit --no-coverage`) + guard (`C:/php83/php.exe scripts/guard.php`, must exit 0) + PHPStan (`C:/php83/php.exe vendor/bin/phpstan analyse src/`).
4. **Two-stage review**: (a) spec compliance vs cited requirement IDs; (b) code quality (PHPStan-clean, parameterized SQL, no secrets).
5. **Commit per checkpoint** (identity already set: `ROUTLAW Dev <dev@routlaw.local>`). `git add <files>` then commit. **DO NOT push.**

## 4. Scope + STOP point

- **Build only Phase 2**: T4 (carrier signup + lifecycle), T5 (equipment profiles), T6 (versioned cost profiles). Roughly 9-12 bite-sized tasks.
- **STOP at the Phase 2 exit gate** (all Phase-2 tests green, guard PASS, PHPStan L8 clean, carrier/equipment/cost verified, no cross-tenant leakage). Report and wait.
- Do NOT continue into Phase 3 (leads & loads & extraction).

## 5. Execute continuously

Proceed through Phase 2 tasks without pausing for check-ins after each task. Pause ONLY at the Phase 2 exit gate.

## 6. Non-negotiables (restated)

- **Stack locked**: All-PHP 8.3 / XAMPP / MariaDB. NO Python, NO Docker/WSL2, NO Redis. Queue = `async_jobs` table.
- **ASVS Level 2** gating for the authenticated app.
- **Tenant-scoped from day one**: `company_id` on every operational row. Cross-tenant retrieval/leakage test must return zero unauthorized rows.
- **Auth**: Argon2id (FR-002); session cookie Secure + HttpOnly + SameSite, rotation (SEC-002).
- **SQL**: parameterized only (SEC-006). No model-generated SQL.
- **CSP**: `Headers::emit()` already emits an enforced policy with NO `unsafe-inline`. Do NOT weaken it.
- **Binary**: Use `C:/php83/php.exe` (8.3.33 with sodium). Do NOT use `/c/xampp/php/php.exe` (8.2, no sodium).
- **Secrets**: gitignored `config/secrets.local.php` + `$_ENV['RL_*']`. Never commit secrets.

## 7. What NOT to do

- Do NOT rebuild Phase 1 (T1/T2/T3 done + committed at `c8e9897`).
- Do NOT `taskkill` Apache — shared XAMPP instance, other vhosts depend on it.
- Do NOT add Python/Docker/Redis or a separate agent service.
- Do NOT commit `/vendor/`.
- Do NOT invent answers to open FRD §23 decisions — carry them to the exit gate as an open report.

## 8. Open items — carry to exit gate

Phase 2 does **not** gate on these. Report them as open at the Phase 2 exit:

- FRD §23 #2/#11: AI provider + data-processing terms (recommend local Ollama; needs owner sign-off)
- §23 #3–#6: Initial region / map provider / rate defaults / eligibility rules
- §23 #8: CRM/TMS/accounting sequence
- §23 #9: Retention schedule (audit is append-only — purge jobs not implemented)
- §23 #11: Evidence thresholds
- §23 #12: BRD R4 cites "OWASP GenAI LLM Top 10 (2025)" — plan targets 2026; doc needs owner edit

## 9. Phase 2 tasks (from build plan §4)

### T4. Carrier signup + lifecycle (FR-004/005, BR-001)
Carrier self-registers: email-verify → active. Lifecycle states: `new → needs_documents → under_review → active → suspended → inactive`. Server-side validation, duplicate detection, accessible error messages. Test: cross-tenant signup isolation, duplicate email rejected, state transition guard.

### T5. Equipment profiles (FR-006/007, BR-001/002)
Multiple equipment profiles per carrier. Numeric-range validation (length/width/height/weight). Hard-match constraints (e.g., hazmat class must match equipment spec). Incomplete → never `approved`. Test: range validation, hard constraint enforcement, incomplete profile cannot be approved.

### T6. Versioned cost profiles (FR-051, BR-001/021)
Versioned carrier cost profiles with effective dates, units (per-mile, flat, percentage), and required-input status. Stale/incomplete excluded from quantitative recommendations. Test: version effective-date logic, stale profile exclusion, required-input enforcement.

## 10. Report format

End with **actual command output** — phpunit summary, guard exit code, phpstan result, curl probe codes for any new endpoint, git log of commits made. Show real probe output, not assertions.
