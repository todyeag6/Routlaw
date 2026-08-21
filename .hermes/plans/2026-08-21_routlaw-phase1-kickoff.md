# ROUTLAW — Phase 1 Handoff Kickoff (fresh session)

> Source of truth for this work: `C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md` (207 lines). Read it FIRST and in FULL before writing any code. It is authoritative; do not re-derive, re-plan, or re-audit. This file is the launch prompt only.

## 0. Ground Truth (verified against the machine, 2026-08-21)

Prior plan prose implied "Repo Scaffolding (T1.1–T1.5)" was still to do. **It is DONE and committed.** Do NOT rebuild it.

| Prior plan said | Verified reality | Impact |
|---|---|---|
| §3 T1.1–T1.5 pending scaffold | Committed `cc8af1f` (scaffold+security) + `8446fa2` (infra vhost). `git log` shows both. | Skip §3; start at **Phase 1** (plan §4, line 84). |
| No foundation code exists | `src/` has Guard.php, Security/Html.php, Security/Csrf.php, Security/Headers.php, Db/Connection.php; `migrations/000_init_foundation.sql` (tenant-scoped companies/users/roles/permissions/user_role_assignments/async_jobs). | Reuse these; extend, don't rewrite. |
| Apache/:8080 needs setup | `:8080` vhost live (DocumentRoot=public/); `:80` (mokimi) intact. phpunit 16/16 green. | No infra task in Phase 1. |

**First action in the new session:** re-confirm the env so you're not trusting this note blindly:
```
cd C:/xampp/htdocs/Routlaw
git log --oneline -3
C:/php83/php.exe vendor/bin/phpunit --no-coverage   # expect 16/16, 1 skipped
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/   # expect 200
```

## 1. Plan path + authority
- Plan: `C:/xampp/htdocs/Routlaw/.hermes/plans/2026-08-21_095000-routlaw-build-plan.md`
- Phase 1 section: **§4 "Phase 1 — Foundation: identity, roles, tenant model, audit"**, tasks **T1 (auth), T2 (RBAC), T3 (audit)** (plan lines 84–87).
- Requirement IDs to cite verbatim in every task heading: BR-001/017/020, MOD-02/03/15, FR-001/002/003/004/005/029/030, SEC-002/003/018, A11Y-* as relevant.

## 2. Skills to load (by name, do not guess)
- `test-driven-development` — RED→GREEN per task, vertical slices, watch it fail first.
- `requesting-code-review` — two-stage review (spec compliance → code quality) before each commit; `[verified]` gate.
- `php-static-analysis-patterns` — PHPStan L8 / PHP 8.3 typed-array + **Argon2id** patterns (directly applicable to T1 auth/RBAC).
- (Optional) `falsification-probes` — prove a negative test can FAIL before trusting it passed.
- Do NOT load `mokimi` and apply it blindly — ROUTLAW is a separate project; mokimi is only a *pattern source* (HTML-escape/CSRF/enforced-CSP carry-over already ported into `src/Security/*`).

## 3. Execution loop (per task)
1. Write the **failing test first** (RED). Run it; confirm it fails for the right reason (feature missing, not a typo).
2. Implement **minimal** code to pass (GREEN). Run it; confirm pass.
3. Run the **full suite** (`C:/php83/php.exe vendor/bin/phpunit --no-coverage`) + **guard** (`C:/php83/php.exe scripts/guard.php`, must exit 0) — no regressions, no new secret/unsafe-inline.
4. **Two-stage review**: (a) spec compliance vs the cited plan requirement IDs; (b) code quality (PHPStan-clean, parameterized SQL, no secrets, session hardening intact).
5. **Commit per checkpoint** (repo-local identity already set: `ROUTLAW Dev <dev@routlaw.local>`). `git add <files>` then commit. **DO NOT push** unless explicitly told.

## 4. Scope + STOP point
- **Build only Phase 1**: T1 (auth), T2 (RBAC), T3 (audit). Roughly 9–12 bite-sized tasks.
- **STOP at the Phase 1 exit gate** (all Phase-1 tests green, guard PASS, auth/RBAC/audit verified, no cross-tenant or cross-role leakage in negative tests). Report and wait.
- Do NOT continue into Phase 2 (carrier/equipment/cost) — that is a separate session.

## 5. Execute continuously
Proceed through Phase 1 tasks without pausing for check-ins after each task. Pause ONLY at the Phase 1 exit gate.

## 6. Non-negotiables (easy to get wrong — restated)
- **Stack locked**: All-PHP 8.3 / XAMPP / MariaDB. NO Python, NO Docker/WSL2, NO Redis. Queue = MariaDB `async_jobs` (already in schema).
- **ASVS Level 2** gating for the authenticated app (plan §2.7).
- **Tenant-scoped from day one**: `company_id` on every operational row; schema already has it. Cross-tenant retrieval/leakage test must return zero unauthorized rows.
- **Auth**: Argon2id (FR-002); session cookie Secure + HttpOnly + SameSite (SEC-002); MFA path stubbed for privileged (SEC-018) — implement the hook, flag if policy unsigned.
- **SQL**: parameterized only (SEC-006). No model-generated SQL anywhere.
- **CSP**: `Headers::emit()` already emits an **enforced** policy with NO `unsafe-inline` in script-src OR style-src. Do NOT weaken it.
- **config/autoload.php** already loads the PSR-4 autoloader — every new class under `src/` resolves automatically. Don't break that bootstrap.
- **Secrets**: gitignored `config/secrets.local.php` + `$_ENV['RL_*']`. Never commit secrets; `.gitignore` already excludes them + `/vendor/`.

## 7. What NOT to do
- Do NOT re-scaffold T1.1–T1.5 (done + committed).
- Do NOT `taskkill` Apache — it's a shared XAMPP instance (mokimi depends on it); it auto-respawns and a kill disrupts other vhosts. The `:8080` vhost is already live.
- Do NOT add Redis/Python/Docker or a separate agent service.
- Do NOT commit `/vendor/`, `composer.lock` is fine to keep tracked.
- Do NOT invent answers to open FRD §23 decisions (below) — carry them to the exit gate as an open report.

## 8. Open items — carry to exit gate, do NOT block, do NOT invent
Phase 1 (auth/roles/tenant/audit) does **not** gate on these. Report them as open at the Phase 1 exit; do not bake assumptions into code.
- FRD §23 #2/#11 AI provider + data-processing terms (recommend local Ollama; needs owner sign-off).
- #3–#6 initial region / map provider / rate defaults / eligibility rules.
- #8 CRM/TMS/accounting sequence. #9 retention schedule. #11 evidence thresholds.
- #12 BRD R4 cites "OWASP GenAI LLM Top 10 (2025)" — plan already targets **2026**; the doc text still needs the owner's edit (not a code task).
No confirmation needed before starting Phase 1 — proceed.

## 9. Report format
End the session with **actual command output** (phpunit summary, guard exit code, curl probe codes for any new endpoint, git log of commits made) — not a description of expected output. Show the real probe, not an assertion.
