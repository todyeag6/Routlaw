# ROUTLAW — Phase 3 Handoff Kickoff (fresh session)

> **For the next session:** Read this file FIRST and in FULL. It is authoritative — do not re-derive, re-plan, or re-audit what is already banked below. Phase 2 is DONE and pushed; start at Phase 3.

## 1. CONTEXT (repo / stack / runtime — verified 2026-08-21)

- **Repo:** `C:/xampp/htdocs/Routlaw`, git `master`, HEAD `32227ae` (tracking `origin/master`, clean tree).
- **Stack (LOCKED):** All-PHP 8.3.33 on XAMPP/MariaDB 10.4. No Python, no Docker, no Redis. MariaDB-backed `async_jobs` queue. PHP runs public site + auth app + JSON API + agent orchestrator + CLI worker (`bin/worker.php`) under Windows Task Scheduler.
- **PHP binary (authoritative — DO NOT use XAMPP's 8.2):** `/c/php83/php.exe` (8.3.33, has ext-sodium). XAMPP panel *displays* 8.2 but the app runs 8.3.33.
- **Toolchain (verified live):**
  - Tests: `/c/php83/php.exe vendor/bin/phpunit --no-coverage` → **81 tests, 217 assertions, 1 skipped, 0 failures**.
  - Static analysis: `/c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M` → **[OK] No errors** (Level 8).
  - Secrets guard: `/c/php83/php.exe scripts/guard.php` → **GUARD PASS**.
  - Lint: `/c/php83/php.exe -l <file>`.
- **Composer:** `vendor/` is committed (phpunit + phpstan + phpmailer). `composer.phar` is gitignored (local tool only). To add a dep: run `composer update <pkg>` with the local phar, commit the updated `composer.lock`.

## 2. COMPLETED (real `git log` hashes — never trust memory)

```
32227ae Wire Cloudflare Turnstile + Apple iCloud SMTP (PHPMailer) + secrets placeholders (mokimi pattern)
d2ad377 Best-practice DB hardening: least-privilege prod guard + audited tenant-scoped soft-delete (carriers, equipment)
10a727a Fix #1: audit triggers bind DEFINER=CURRENT_USER (Hostinger shared-safe, no root grant)
6918f5a Review fixes: tenant-scope write paths (T4 approveProfile/fetchProfile + T5 approveProfile)
0524536 Fix T6: listForCarrier selects non-existent status column; add list test
9ab5c2d Phase 2 T4/T5/T6: carrier signup+lifecycle, equipment profiles, cost profiles
c8e9897 Phase 1: T1 Auth (Argon2id+session), T2 RBAC, T3 Audit (append-only + agent trace)
```

- **Phase 1 (T1–T3):** Auth (Argon2id, sessions), RBAC (tenant+role), append-only audit (BEFORE UPDATE/DELETE triggers `SIGNAL '45000'`).
- **Phase 2 (T4–T6):** Carrier signup + lifecycle + status history; equipment profiles (range validation, hard-match, approval gating); versioned cost profiles (effective dates, stale/incomplete exclusion). All tenant-scoped, all TDD, all green.
- **Cross-cutting hardening (this session):** audit triggers now `DEFINER=CURRENT_USER` (was `root@localhost` = Hostinger deploy-blocker); least-privilege prod guard (throws if `RL_ENV=prod` + `RL_DB_USER=root`); audited tenant-scoped soft-delete (`softDelete()` on carriers + equipment, writes `deleted_at/deleted_by/delete_reason` + audit event with `target_id`); `AuditLog::recordSystem()` gained `targetType`/`targetId` params.

## 3. ESTABLISHED CONVENTIONS (load-bearing — do not re-litigate)

- **Secrets (mokimi pattern):** env keys are UPPER_SNAKE `RL_*`. `config/secrets.local.php` is **gitignored** (real dev values); `config/secrets.local.php.example` is committed (placeholders only). Prod reads `RL_*` via hPanel/Cloudflare env. **Never commit real credentials.**
- **Email:** PHPMailer → `smtp.mail.me.com:587` STARTTLS (Apple iCloud, **app-specific password**, not Apple ID password). `src/Mail/Mailer.php` throws "email not configured" when creds empty (dev-safe, explicit).
- **Bot defense:** Cloudflare Turnstile (`src/Security/Turnstile.php`), NOT reCAPTCHA — Turnstile is the correct fit behind Cloudflare. Empty keys ⇒ dev bypass (returns `true`); prod MUST set `RL_TURNSTILE_SITE_KEY`/`RL_TURNSTILE_SECRET_KEY`.
- **Tenant scoping (FR-042):** every operational query carries `company_id` on BOTH read (`WHERE deleted_at IS NULL AND company_id = ?`) AND write (`UPDATE ... WHERE id = ? AND company_id = ?`). Defense-in-depth — don't rely on upstream auth.
- **Soft-delete:** regulated entities are NEVER hard-deleted. `softDelete()` sets metadata + emits an audit event carrying `target_type`+`target_id`.
- **Append-only audit:** `audit_events` is protected by `BEFORE UPDATE/DELETE` triggers. `DEFINER=CURRENT_USER` (NOT `root@localhost` — breaks shared hosting). **Confirm Hostinger plan grants `TRIGGER`** or the guarantee degrades to PHP-only.
- **FK cascade direction:** parent `RESTRICT`, child `CASCADE`. Correct for multi-tenant — keep it. (A prior session misread "no FKs" from a file grep; the FKs ARE present and enforced — verify against live DB, not the file.)
- **PHPStan L8 must stay clean.** `bind_param` type string must match column count exactly. `mysqli` `fetch_column()` returns `false` (not `null`) on no row.

## 4. NEXT COMPONENT — Phase 3 (from build plan §4, line 94)

**Scope: T7, T8, T9** — Leads & loads & extraction (MOD-06/07, BR-003/004/005).

- **T7. `FR-008/009`** — Typed leads + conservative dedup (no auto-destructive merge).
- **T8. `FR-010/011/013/014`** — Manual + approved-email load intake; `source_records` preservation; schema extraction with per-field confidence + missing-field reporting (no hallucinated completion — BR-005).
- **T9. `FR-012`** — Untrusted-content isolation: email/doc/load text is data, never instruction. **Test (AI-eval dataset §19.2):** prompt injection in a broker note / hidden instruction in uploaded doc does NOT alter permissions, tools, or approval policy.

> Phase 3 does NOT gate on AI provider selection (still OPEN — recommend local Ollama, needs owner sign-off). T9's isolation tests can be built now with injection fixtures; the actual LLM call is deferred until the provider decision lands.

## 5. EXECUTION LOOP (per build plan header)

For each task: spawn a fresh subagent (or implement directly), TDD-first (RED test → GREEN impl → verify). Two-stage review per task: **(1) spec compliance** against the FR/BR ID, **(2) code quality** (PSR-12, strict_types, typed returns, mysqli guards, tenant scoping, PHPStan L8 clean, guard PASS). Commit per checkpoint. **Do NOT push unless explicitly told.**

## 6. SCOPE + STOP POINT

- Build only **Phase 3** (T7–T9). Roughly 9–12 bite-sized tasks.
- **STOP at the Phase 3 exit gate:** all Phase-3 tests green, guard PASS, PHPStan L8 clean, lead/load/extraction verified, no cross-tenant leakage, injection-isolation test proves load content cannot alter policy.
- Do NOT continue into Phase 4 (hard gates / economics / decision alternatives).
- Pause ONLY at the Phase 3 exit gate. Report and wait.

## 7. NON-NEGOTIABLES

- **Secrets:** never hardcoded; use `RL_*` env via `config/secrets.local.php`. `guard.php` must PASS.
- **Tenant scoping:** every new entity carries `company_id` from day one; write paths scoped.
- **Parameterized SQL only** — no model-generated / string-concatenated SQL (SEC-006).
- **Verify against live DB**, not file greps, before claiming schema correctness.
- **Quality gates are real:** run phpunit + phpstan + guard after every task. A green proxy (exit 0 from a silent failure) is NOT a pass — read actual output.
- **Commands use `/c/php83/php.exe`**, never XAMPP's 8.2.

## 8. WHAT NOT TO DO

- Do not start/inspect/remove the **aiwebscapes-platform Docker stack** (off-limits, separate project).
- Do not re-implement the mokimi secrets/email/Turnstile pattern — it is already wired; extend `src/Mail/Mailer.php` / `src/Security/Turnstile.php` if needed.
- Do not hard-delete regulated entities; use `softDelete()`.
- Do not push to `origin` without explicit approval (standing rule).
- Do not create the missing infrastructure (AI provider account, Hostinger DB) — report as open.

## 9. OPEN ITEMS TO REPORT AT EXIT

- **AI provider (#2/#11):** local Ollama recommended; owner sign-off pending on model + data-processing terms. Blocks T9's live LLM call, not its isolation tests.
- **Hostinger `TRIGGER` grant:** confirm the shared plan grants it; if not, append-only audit degrades to PHP-only.
- **FRD PDF missing:** only `.docx` exists (doc-set gap to flag to owner).
- **BRD R4:** cites OWASP GenAI LLM Top 10 "2025" — superseded by **2026**; update before AI-threat work.

## 10. FIRST STEP

1. Read `.hermes/plans/2026-08-21_095000-routlaw-build-plan.md` in full (207 lines; authoritative for all phases, standards baseline, ADRs).
2. Read `migrations/`, `src/`, `tests/` to confirm current shape (don't trust this summary — verify).
3. Start T7 (typed leads) TDD-first.

---
*Generated 2026-08-21 from live `git log` + verified toolchain. Mirrors mokimi §4.4 handoff recipe + `execution-ready-planning` kickoff structure.*
