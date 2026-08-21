# ROUTLAW Carrier Intelligence & Operations Platform — Build Plan

> **For Hermes:** Use `subagent-driven-development` to implement this plan task-by-task (fresh subagent per task, two-stage review: spec compliance → code quality). Every implementation task is TDD-first. Every cross-cutting gate (security, AI-threat, ADA, privacy) is verified, not asserted.

**Goal:** Build the ROUTLAW MVP — a Level-1 "assist-only" carrier-intelligence platform with secure human control, outcome-linked memory, tenant-scoped data, and production governance — per the BRD v2.0 + FRD v2.0 baseline and current official standards (NIST CSF 2.0 / SSDF 1.1 / SP 800-63-4 / OWASP ASVS 5.0.0 / OWASP API Top 10 2023 / OWASP GenAI LLM Top 10 2026 / WCAG 2.2 AA / DOJ web-ADA guidance).

**Architecture:** **All-PHP 8.3 on the operator's proven XAMPP/MariaDB runtime (LOCKED 2026-08-21, §0b #1 / §2.1)** — PHP serves the public site, the authenticated carrier/dispatch app, the JSON API, the agent orchestrator, **and** a CLI worker (`bin/worker.php`) under Windows Task Scheduler. MariaDB is the canonical store; the `async_jobs` table with a claim-lock is the queue (no Redis). Private object storage sits outside the docroot. Policy/authorization is enforced in application code, never in prompt text (FRD §2.2 "Policy outside the model"). PostgreSQL / Redis / Docker / a separate Python service are explicitly NOT used — a documented ADR deviation from FRD §20.2.

---

## 0. Current State & Gaps (verified from disk)

- Folder contains **only** `IDEA.md` ("driver/hauler based App that automates hotshot hauling") + 4 spec docs (`ROUTLAW_BRD_v2.0-draft.docx/pdf`, `ROUTLAW_FRD_v2.0-draft.docx`). **No code, no repo scaffolding, no DB schema yet.**
- BRD docx↔pdf: **39 IDs, exact parity** — dual-format integrity good.
- FRD docx: 250 IDs (BR-001..030, FR-001..066, SEC-001..026, A11Y-001..015, PRIV-001..008, NFR-001..018, HAR-01..15, AG-01..11, MOD-01..18, UI-001..018, E-* error taxonomy). **FRD PDF is MISSING** — only the `.docx` exists. (Doc-set gap to flag to the owner; PDF twin not delivered.)
- IDEA.md is a one-liner and **does not reflect the approved BRD scope** (BRD is a full carrier-intelligence SaaS, not a simple "automation" app). Treat BRD/FRD as authoritative; IDEA.md is superseded.

## 0b. Decisions Locked (2026-08-21, owner sign-off via clarify)
- **#1 Stack → All-PHP 8.3 on XAMPP/MariaDB.** No Python service, no Docker/WSL2 agent container. PHP runs public site + auth app + JSON API + agent orchestrator + CLI workers. MariaDB-backed `async_jobs` queue (not Redis). Documented ADR deviation from FRD §20.2's PostgreSQL recommendation.
- **#7 Carrier self-service login → IN MVP.** Full tenant user model + Carrier User role ships in Phase 1.
- **#10 ASVS verification level → Level 2** for the authenticated carrier/dispatch app.
- Still open: #2/#11 AI provider + data-processing terms (recommend local Ollama, keyless/self-hosted), #3–#6, #8–#9, #11 (evidence thresholds), #12 (BRD R4 → 2026 + missing FRD PDF).

## 1. Standards Baseline — verified current (2026-08-21)

| Source | BRD/FRD ref | Status | Used for |
|---|---|---|---|
| OWASP ASVS 5.0.0 | [R5], SEC-047 | **Current** (released May 2025) | App/API verification gates (FRD §15, SEC-*) |
| OWASP GenAI LLM Top 10 | [R4], SEC-014/015/016/017, FR-012/032/048 | **DRIFT** — BRD cites "2025"; superseded by **OWASP GenAI LLM Top 10 2026** | AI threat evaluation (FR-048), harness (HAR-*) |
| OWASP API Security Top 10 2023 | [R21], SEC-024 | Current | API authz (FR-003, SEC-010/024) |
| WCAG 2.2 | [R6], A11Y-* | **Current W3C Recommendation** | Accessibility (FR-046, A11Y-001..015) |
| DOJ Web Accessibility & ADA | [R23] | Current guidance | Legal-context input (BRD §15) |
| NIST AI RMF + AI 600-1 (GenAI Profile) | [R1/R2] | Current (AI 600-1 final) | AI governance (BRD §12, FRD §9) |
| NIST CSF 2.0 | [R3] | Current | Security governance (BRD §12) |
| NIST SSDF 1.1 (SP 800-218) | [R13], SEC-019 | Current | Secure SDLC (SEC-019) |
| NIST SP 800-63-4 | [R16], SEC-018 | **Current** (final July 2025) | Digital identity / MFA (FR-002, SEC-018) |
| NIST SP 800-18r2 / 800-61r3 / 800-53r5 / 800-161r1 | [R14/R15/R17/R19/R20/R22] | Current | Plans, IR, supply chain (SEC-021/022/023/026) |
| FMCSA HOS/ELD/Broker/Authority/Cargo | [R7-R10],[R24-R27] | Current | Compliance flags (FR-016) |
| FTC CAN-SPAM / Start with Security | [R28/R29] | Current | Email/comms + security hygiene |

**Action item:** Update BRD §23/R4 to "OWASP GenAI LLM Top 10 **2026**" before any AI-threat work begins. Keep the 2025 list only as a historical note.

## 2. Architecture Decisions (LOCKED 2026-08-21 — owner sign-off via clarify)

FRD §23 decisions #1, #7, #10 are now resolved. Remaining opens: #2/#11 (AI provider + data-processing terms), #3–#6, #8–#9, #11 (evidence thresholds), #12 (doc fixes).

1. **Stack (DECIDED — #1): All-PHP.** PHP 8.3.3 runs the entire system on the operator's proven XAMPP/MariaDB runtime — public site, authenticated app, JSON API, **and the agent orchestrator + CLI workers**. No separate Python service, no Docker/WSL2 agent container.
   - **Canonical DB: MariaDB 10.4** (reuse XAMPP; FRD §20.2 permits MySQL where architecture warrants). FRD's PostgreSQL recommendation is therefore NOT followed — documented as an approved ADR deviation.
   - **Queue: MariaDB-backed** `async_jobs` table with a claim-lock (`status` + `claimed_at` + `correlation_id`), not Redis. Matches the FRD §12 entity list; idempotent claim avoids duplicate side effects (FR-033, NFR-005).
   - **Worker execution:** a PHP CLI worker (`bin/worker.php`) run under Windows Task Scheduler (reuse the SOT Task Scheduler pattern from the local-ai stack — never a manual start that spawns duplicates) or a long-running loop; polls `async_jobs`, enforces budgets (FR-032/HAR-08), writes `agent_runs`/`tool_calls`/`audit_events`.
   - **Private object storage:** a non-web-readable directory outside the docroot, served only through an authenticated, signed-access PHP endpoint (SEC-007, FR-025). No public URL (BRD §12 "No direct public document URLs").
2. **Tenant model:** `company_id` on every operational row from inception (FR-042, BR-020). Single-tenant operation at MVP is acceptable, but the schema is tenant-scoped from day one (no later rewrite).
3. **Carrier self-service login IN MVP (DECIDED — #7):** the full tenant user model + Carrier User role ships in Phase 1 (MOD-03, FR-004 carrier signup, FR-005 lifecycle). Email-verify → active self-registration, server-side RBAC, no admin-approval requirement unless the owner later decides otherwise. Raises authz surface but matches the SaaS shape the owner wants.
4. **Agent boundary:** LLM is NOT the authz system; vector store is NOT canonical DB; classifier is NOT legal verifier (FRD §3.2). All agent side effects go through the PHP tool gateway (HAR-01..15).
5. **Async:** slow AI/doc/integration work is queued in `async_jobs`; HTTP returns a job state immediately (NFR-001, FR-044).
6. **Secrets:** gitignored `config/secrets.local.php` (mokimi pattern) + env; no hardcoded secrets (SEC-008); OAuth tokens encrypted at rest (FR-011, FR-013 §14.3).
7. **ASVS verification level (DECIDED — #10): Level 2** for the authenticated carrier/dispatch app (covers auth, access control, business logic — appropriate for broker/carrier PII). Level 2 controls gate the release (SEC-047, FR-066).
8. **AI provider (OPEN — #2/#11):** recommend **local Ollama** (keyless, self-hosted — matches the operator's homelab preference; the GTX 1660 Ti runs a quantized model via CPU-offload) behind an OpenAI-compatible adapter in the tool gateway, with a cloud API fallback. The actual model + its data-processing terms still require owner sign-off (PRIV-007, SEC-022). Local-first minimizes data egress (PRIV-002/007).

## 3. Repo Scaffolding (Task batch 1 — TDD the skeleton)

Proposed layout (All-PHP, XAMPP/MariaDB runtime):
```
Routlaw/
  public/            # PHP: public site, auth app, JSON API, CSP/.htaccess
    index.php, app/, src/ (autoload helpers), config/, migrations/, tests/ (phpunit), bin/worker.php
  storage/           # private, outside docroot: documents/ (signed-access only), async artifacts
  infra/             # Apache vhost (or nginx), CI workflow, Task Scheduler task def for bin/worker.php
  docs/plans/        # this plan + ADRs + traceability
```
Bite-sized tasks (each: write failing test → implement → verify → commit):
- T1.1 Init git repo, `.gitignore` (secrets, vendor, `.env`, `storage/`), commit.
- T1.2 PHP: `composer.json` (phpunit 11.x, phpstan at Level 2 ASVS-aligned rules), `phpunit.xml` (failOnWarning/risky), `scripts/guard.php` quality gate (mirrors mokimi pattern). Test: guard exits non-zero on a seeded failure. Also add `bin/worker.php` stub that polls `async_jobs`.
- T1.3 DB: root migration `000_init_companies_users_roles.sql` (companies, users, roles, permissions, user_role_assignments, async_jobs) with `company_id`, common columns (FRD §12.2), soft-delete, optimistic concurrency. Test: migration applies on a fresh MariaDB; tenant column present. `mochiefin_schema`-style Hostinger/MariaDB-safe DDL (no `CREATE DATABASE`/`USE`, explicit utf8mb4, `INSERT IGNORE` seeds, `;` outside string literals).
- T1.4 Infra: Apache vhost + `.htaccess` hardening (HSTS, sensitive-dir/file blocks, HTTPS-skip-localhost) + enforced CSP emitter + security headers (port mokimi's validated pattern). Test: live probe shows exactly one `Content-Security-Policy`, zero `-Report-Only`, sensitive files 403.
- T1.5 Config: `config/autoload.php` with `mochiefin_h()`-equivalent HTML escape, CSRF token helpers, session bootstrap (Secure/HttpOnly/SameSite), secrets loader (gitignored `secrets.local.php`). Lint every PHP file with `C:/php83/php.exe -l`.

*Reuse proven mokimi helpers where applicable:* HTML escape, CSRF, enforced CSP, security-header emitter, HMAC verification-cookie pattern — port to ROUTLAW's `src/autoload.php` equivalents (SEC-002/003/005/013).

## 4. Phased Implementation (maps to BRD §20 roadmap + FRD modules)

Each phase = a vertical slice, TDD, with its own verification gate. Phases build on each other.

### Phase 1 — Foundation: identity, roles, tenant model, audit (MOD-02/03/15, BR-001/017/020)
- T1. Auth: `FR-001` login (email+password), `FR-002` Argon2id/bcrypt hash, session (Secure/HttpOnly/SameSite, rotation, timeout — SEC-002). Test: login sets scoped session; bad password rate-limited; auth events in `audit_events`.
- T2. RBAC: `FR-003` server-side per-request tenant+role check; `MOD-03` user/role admin. Test (negative): cross-role and cross-tenant request returns 403 + no data leakage.
- T3. Audit: `FR-029/030` append-only `audit_events` + `agent_runs`/`tool_calls` write path; normal roles cannot mutate (BR-017). Test: direct UPDATE/DELETE by app role is rejected at the data layer or via guard.

### Phase 2 — Carrier & equipment & cost (MOD-04/05, BR-001/002/021)
- T4. `FR-004/005` carrier signup + lifecycle states (new→needs_documents→under_review→active…). Server-side validation, duplicates, accessible errors.
- T5. `FR-006/007` equipment profiles (multiple per carrier), numeric-range validation, hard-match constraints; incomplete → never `approved`.
- T6. `FR-051` versioned carrier cost profiles (effective dates, units, required-input status). Stale/incomplete excluded from quantitative recs.

### Phase 3 — Leads & loads & extraction (MOD-06/07, BR-003/004/005)
- T7. `FR-008/009` typed leads + conservative dedup (no auto-destructive merge).
- T8. `FR-010/011/013/014` manual + approved-email load intake; source preservation (`source_records`); schema extraction with per-field confidence + missing-field reporting (no hallucinated completion — BR-005).
- T9. `FR-012` untrusted-content isolation: email/doc/load text is data, never instruction. **Test (AI-eval dataset §19.2):** prompt injection in a broker note / hidden instruction in uploaded doc does NOT alter permissions, tools, or approval policy.

### Phase 4 — Hard gates, economics, decision alternatives (MOD-08, BR-006/007/008/022/023/024)
- T10. `FR-015/016` deterministic hard compatibility gates (weight/dimension/equipment/hazmat/CDL/HOS/ELD) BEFORE scoring; hard fail prevents `recommended` status; uncertain → human review. **Test:** hard-gate violation rate = 0 in critical suite (§19.3).
- T11. `FR-052/053` total-mile/time economics from versioned inputs; missing mandatory input → abstention, never fabricated value. Reproducible from stored versions.
- T12. `FR-054/055/056/059` decision alternatives (accept/reject/negotiate/delay/combine/avoid) with reasons, risks, assumptions, confidence, next action; schedule/commitment conflicts → `needs_review`.
- T13. `FR-057/058/060/061` counterparty/facility observations, reload/downstream positioning (uncertainty explicit), outcome capture, predicted-vs-actual variance.

### Phase 5 — Drafting, approval queue, documents (MOD-10/11/12, BR-012/015/016)
- T14. `FR-021` message drafting (editable, source-linked, **cannot self-send**).
- T15. `FR-022/023/024` approval queue: request carries actor/action/resource/payload-hash/expiry; **payload-bound, replay-safe** (modified payload → new approval). Test: approval replay / tampered-payload rejection.
- T16. `FR-025/026/027/028` private document upload (MIME/sig check, randomized key, no exec, no public path), classification (≠ legal validity), human verification, approval-gated sharing (no direct model share).

### Phase 6 — Agent harness, memory, learning (MOD-13/14, BR-009/010/011/018/019)
- T17. `AG-01..11` + `HAR-01..15` secure harness: tool allowlist, per-call authz, schema validation, egress control, secrets isolation (opaque handles), resource budgets, kill switch, version pinning, output sanitization. Test: model cannot grant itself tools; bounded steps prevent infinite loops (FR-032).
- T18. `FR-034..037` memory entries (provenance, tenant/role-filtered retrieval BEFORE semantic search), contradiction handling, expiry/revalidation. Test: cross-tenant retrieval returns zero unauthorized content.
- T19. `FR-038/039/040/041` self-correction verifier + post-outcome learning + proposals that **cannot self-promote** (versioned, evaluated, approved, staged, rollback).

### Phase 7 — Integrations, governance, evidence (MOD-16/17/18, BR-013/029/030)
- T20. `FR-011/063` Gmail OAuth (narrow scopes, encrypted tokens, revocable) + authorized-source registry (owner, rights, terms, monitoring, disablement; fails closed).
- T21. `FR-062/065/066` decision-quality metrics + privacy/retention enforcement + release-evidence collection (governance console).
- T22. `SEC-019/021/023/026` SSDLC (threat model, SAST/SCA, secrets scan, dep review), vuln management, IR plan, security plan + evidence.

## 5. Cross-Cutting Best-Practice Layers (map to requirement IDs)

### 5.1 Security (NIST CSF 2.0 / SSDF 1.1 / SP 800-63-4 / OWASP ASVS 5.0.0 / API Top 10 2023)
- Transport: HTTPS-only prod, HSTS post-validation (SEC-001). Headers/CSP from a centralized emitter (SEC-013) — port mokimi's enforced-CSP pattern (no `unsafe-inline` in `script-src`/`style-src`; JSON-LD CSP-exempt via `JSON_HEX_TAG`).
- Auth/sessions: Argon2id (FR-002), Secure/HttpOnly/SameSite, rotation, MFA for privileged (SEC-018, SP 800-63-4 §5/§6).
- CSRF on all state-changing browser requests (SEC-003); API uses scoped tokens/nonces.
- Input validation allowlist/typed at trust boundaries (SEC-004); **parameterized SQL only, no model-generated SQL** (SEC-006); output encoding contextual, model text untrusted (SEC-005).
- File uploads: sig/MIME, size cap, private store, randomized key, no exec, quarantine/scan (SEC-007, FR-025).
- Secrets: dedicated store, rotation, no URL/log leakage (SEC-008/009); logging redacts sensitive fields + correlation IDs (NFR-006/013).
- Rate limits on login/forms/AI/exports (SEC-011); dependency lockfile + SCA (SEC-012, SSDF).
- API authz: function/object/property/tenant/action level (SEC-024, API Top 10).

### 5.2 AI Security Threats (OWASP GenAI LLM Top 10 **2026** + NIST AI RMF/600-1)
Treat the 2026 list as the live threat model (supersedes BRD's "2025"):
- **LLM01 Prompt Injection** → `FR-012`, `SEC-014`, `HAR-04`: untrusted email/doc/load text isolated; tools/policy not content-controllable. Eval dataset §19.2 cases must pass.
- **LLM02 Sensitive Info Disclosure** → `SEC-016`, `PRIV-004`: minimize prompt data, redact, role-scope retrieval, private docs. Cross-tenant retrieval leakage = 0 (§19.3).
- **LLM03 Supply-Chain** → `SEC-022`: model/dependency/service inventory + provenance + exit plans.
- **LLM04 Data & Model Poisoning** → `FR-034/036` provenance + contradiction handling; no silent overwrite.
- **LLM05 Improper Output Handling** → `SEC-005/014`, `HAR-14`: encode model output before HTML; validate links/attachments.
- **LLM06 Excessive Agency** → `SEC-015`, `HAR-01/02/09`: no unrestricted tools; approval + least privilege; payload-bound approvals.
- **LLM07 System Prompt Leakage / LLM08 Vector/DB** → secrets isolation (HAR-06), tenant-scoped retrieval.
- **LLM09 Over-Reliance** → UI rules (FRD §6.1): AI values visually distinct from verified; confidence not sole signal; explicit missing data; hard-fail reasons visible.
- **LLM10 Unbounded Consumption** → `SEC-017`, `FR-032`, `HAR-08`: step/token/cost/time/concurrency budgets + kill switch + bounded retries.
- Governance: every recommendation links to an agent run + version snapshot (FR-030/064); policy-changing learning cannot self-promote (FR-041).

### 5.3 ADA / Accessibility (WCAG 2.2 AA + DOJ guidance [R23])
- Mobile-first, semantic HTML, logical headings (A11Y-001, frontend-mobile-first skill: viewport meta first, `min-width` enhancements, `100svh`).
- Keyboard operable, no traps; visible focus; focus-not-obscured (A11Y-002/003/011); dialog focus mgmt.
- Programmatic labels/instructions/autocomplete; accessible validation + error summary (no color-only) (A11Y-004/005).
- Contrast ≥4.5:1 (A11Y-006); async-status announced (A11Y-007); responsive tables (A11Y-008); reduced-motion (A11Y-009).
- Target size 24×24px (A11Y-012); consistent help (A11Y-013); accessible auth (no cognitive-function test w/o alternative) (A11Y-014).
- **Gate:** automated scan (axe-core) **+ manual keyboard + screen-reader pass** (A11Y-010/015) before release. WCAG 2.2 AA is a release gate, not a task.

### 5.4 SEO / AEO (seo-aeo-audit skill)
Public marketing/intake site only — must NOT weaken the compliance/access posture:
- **Crawlability first:** verify bots get 200 real content (probe with Googlebot UA), not a 302 to an interstitial. No `noindex` on real pages; `robots.txt` allows public, disallows `/admin /api /storage /config`.
- **Structured data:** `Organization` + `FAQPage` JSON-LD on the homepage (CSP-exempt, `JSON_HEX_TAG`); `areaServed` not a fake `LocalBusiness` address (ROUTLAW is a software provider, not a carrier — compliance guard).
- **Open Graph / Twitter cards** in shared head (CSP-exempt meta).
- **Dynamic sitemap** including DB-driven content (blog/help if added); `robots.txt` `Sitemap:` line.
- **No `X-Robots-Tag: noai` own-goal** unless the owner explicitly chooses AEO-opt-out (policy decision).
- Verify with the live probes from the skill (bot UA 200, human UA 302, JSON-LD decodes, sitemap includes dynamic content).

### 5.5 Privacy & Data Governance (NIST Privacy Framework / SP 800-18r2)
- Data inventory + classification (public/internal/confidential/highly-sensitive) (PRIV-001); minimize collection + AI context (PRIV-002); no undisclosed provider training (PRIV-002/007).
- Tenant/role-scoped access, field/document restrictions (PRIV-004); notice/access/correction/export/deletion hooks for future SaaS (PRIV-005).
- Retention by class + legal hold + secure disposal across all stores (PRIV-006); DPIA for material new data/model/integration (PRIV-003); processor/subprocessor assessment before enablement (PRIV-007).
- Test privacy workflows + cross-tenant separation + redaction (PRIV-008).

### 5.6 Supply Chain / SSDLC (NIST SSDF 1.1 / SP 800-161r1)
- Threat modeling per module; code review; automated tests; secrets scanning; SAST/SCA; dependency review (SEC-019).
- Lockfiles + inventory + vuln intake/triage/remediation + re-verification (SEC-012/021).
- Model/service provenance + supplier due diligence + monitoring + contingency/exit (SEC-022).
- Versioned, reviewed, rollback-capable changes for config/secrets/prompts/policies/schemas/models/infra (NFR-016).

## 6. Testing & Release Gates (FRD §19)
- **Unit:** validators, scoring, hard gates, authz helpers, state transitions, approval binding.
- **Integration:** intake→queue, email→load, load→match, draft→approval, document→review.
- **E2E:** representative operator workflows.
- **Security:** CSRF, XSS, SQLi, object-level authz, cross-tenant isolation, upload attacks, approval replay/bypass.
- **Accessibility:** axe-core + manual keyboard/SR smoke.
- **AI eval dataset (§19.2):** clean/messy/missing/conflicting/prompt-injection/overweight/incomplete-profile/unknown-HOS/ambiguous-broker/duplicate/adversarial-send cases. Required metrics (§19.3): hard-gate violation = 0; policy-bypass = 0; cross-tenant retrieval leakage = 0; missing-field honesty; unsupported-claim rate; source-rights coverage = 100%; evidence reproducibility.
- **Release gate (§19.4 / FR-066):** NO promotion with unresolved critical security findings, any reproducible Level-1 approval bypass, any critical cross-tenant exposure, or any critical hard-gate override. Missing evidence → named owner accepts a documented, expiring exception.

## 7. Open Decisions Requiring Operator Sign-Off (FRD §23)
1. **Architecture / language / framework / hosting / data regions** (ADR) — **RESOLVED (2026-08-21):** All-PHP 8.3 on XAMPP/MariaDB, no Python/Docker/Redis; MariaDB `async_jobs` queue. See §0b #1 / §2.1.
2. Production hosting provider + AI model; **AI provider data-processing terms** (PRIV-007, SEC-022).
3. Initial operating region + map/mileage provider.
4. Minimum-rate / max-deadhead defaults; carrier-eligibility rules.
5. First commercial load-source integration (post contract/API/ToS review).
6. Google Workspace vs other business email.
7. Carrier self-service login in MVP vs limiting to staff intake.
8. CRM/TMS/accounting integration sequence.
9. Document retention schedule by type.
10. Exact **ASVS verification level/profile** for release gates — **RESOLVED (2026-08-21):** ASVS Level 2 for the authenticated carrier/dispatch app. See §0b #10 / §2.7.
11. Objective evidence thresholds before any Level-2 autonomy.
12. **BRD doc correction:** update R4 to OWASP GenAI LLM Top 10 **2026**; deliver the missing FRD PDF twin.

## 8. Risks / Assumptions
- **No code exists yet** — this plan is greenfield; scaffolding (§3) is the first concrete step.
- Hosting is TBD (FRD §20.1); the MVP runs on the operator's local XAMPP/MariaDB stack — **no Docker/WSL2** (LOCKED, §0b #1 / §2.1). A future production host remains an open FRD §23 decision.
- Non-CDL ≠ unregulated; FMCSA applicability stays profile-specific (BR-007, FR-016) — compliance flags route to human review, never legal conclusions.
- The system must never present itself as legal advice or as a brokerage/disguised broker (BR-004.2, FR-016) — role separation is enforced in code.
- Multi-tenant SaaS is future; MVP operates single-tenant but the schema is tenant-scoped from day one (BR-020, FR-042).

## 9. Execution Approach
Per `subagent-driven-development`: parse this plan once, build the todo list, dispatch a fresh implementer subagent per task (TDD context included), then two-stage review (spec compliance → code quality) before marking complete. Do not proceed past a phase's verification gate with open critical/important findings. Parallelize only independent read-only analyses; never dispatch parallel implementers for tasks touching the same files.

**Next step:** confirm the remaining open FRD §23 decisions (especially #2/#11 AI provider + data-processing terms, and #12 BRD R4→2026 + missing FRD PDF), then execute §3 scaffolding (All-PHP, XAMPP/MariaDB, ASVS Level 2, carrier self-service login).
