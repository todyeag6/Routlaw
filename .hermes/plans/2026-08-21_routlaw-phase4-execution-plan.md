# ROUTLAW Phase 4 — Hard Gates, Economics, Decision Alternatives (Execution Plan)

> **For Hermes:** Use `subagent-driven-development` to implement task-by-task (fresh subagent
> per task, two-stage review: spec compliance → code quality). Every task is TDD-first.
> This plan is authoritative and self-contained; do not re-derive or re-audit against the
> build plan. It corrects an overconfident assumption in the Phase 4 handoff doc (see §0).

**Goal:** Implement Phase 4 (T10–T13) of ROUTLAW: deterministic hard compatibility gates,
versioned cost/total-mile economics with honest abstention, structured decision alternatives,
and predicted-vs-actual outcome variance — all tenant-scoped, parameterized, no hallucinated values.

**Architecture:** Pure All-PHP 8.3.33 on XAMPP/MariaDB 10.11.14 (no Python/Docker/Redis).
New services under `src/` following the existing per-domain layout (`Routlaw\Gates`,
`Routlaw\Economics`, `Routlaw\Decisions`). State lives in new tenant-scoped tables
(`decision_cases`, `counterparty_observations`, `facility_observations`, `decision_outcomes`,
`prediction_variances`, `decision_input_snapshots` — per FRD §12.4). Hard gates are a
deterministic rule engine that runs BEFORE any scoring and can only PASS / FAIL / ABSTAIN
(route-to-human); ABSTAIN is the honest answer when mandatory reference data is missing.

**Tech Stack:** PHP 8.3.33 (`/c/php83/php.exe`; NOT XAMPP 8.2), mysqli (parameterized only,
SEC-006), PHPUnit 11.5, PHPStan 1.11 L8, `scripts/guard.php`. MariaDB with `company_id`
on every row; throwaway `routlaw_test_*` DBs per test class.

---

## §0 Ground Truth (verified 2026-08-21 against disk + FRD docx)

Prior claims and reality:

| Prior claim (handoff doc / build plan) | Verified reality | Impact on Phase 4 |
|---|---|---|
| Plan: "MariaDB 10.4" | Live: **10.11.14** | Use 10.11.14 behavior; no 10.4-only features |
| Handoff: "derive ALL hard gates (weight/dimension/equipment/hazmat/CDL/HOS/ELD) from existing `carriers`/`equipment_profiles` tables" | **Partially false.** Real columns exist for weight (`loads.weight_lbs`, `equipment_profiles.payload_capacity_lbs`), deck dims (`deck_length_ft`/`deck_width_ft`), truck/trailer type, and completeness (`is_complete`). But **hazmat, CDL rating, HOS, and ELD reference data DO NOT EXIST** in any table. `carriers.cdl_status` is in FRD §12.4 logical schema but **missing from migration `002_carriers.sql`**. | T10 must split into (a) deterministic gates from real columns, (b) gates needing data that is absent → **ABSTAIN / route-to-human**, never fabricate. Filling the `cdl_status` gap is its own task (T10.0). |
| FRD §19.2 lists "Unknown HOS/ELD applicability", "Incomplete equipment profile", "Broker identity ambiguity" as cases | These are **uncertainty / human-review** cases, not deterministic pass/fail. | Hard-gate engine returns `ABSTAIN` (not `PASS`) for these → decision status `needs_review`, never `recommended`. |
| T11 "total-mile/time economics" | `loads` has origin/dest **city+state but no mileage/distance**. No geo/mileage provider configured (FRD §23 #3 open). | T11 computes cost from versioned `carrier_cost_profiles` + entered `posted_rate` + entered distance/time IF present; otherwise **ABSTAIN** on distance-dependent figures (no fabricated miles). |
| T12/T13 "decision alternatives / counterparty / facility / variance" | **No tables exist yet.** FRD §12.4 names them (`decision_cases`, `counterparty_observations`, `facility_observations`, `decision_outcomes`, `prediction_variances`, `decision_input_snapshots`). | Phase 4 must author these migrations (T12.x / T13.x) before services. |
| "109 tests green, PHPStan L8 clean, guard PASS at HEAD e6fe080" | Confirmed by commit log (HEAD `61378ed` adds handoff doc; `e6fe080` is Phase 3). Test pattern: each class creates `routlaw_test_*` DB, applies migrations 000–006, truncates+reseeds in `setUp`. | Match this pattern exactly for new test classes. |

**Non-negotiables (carried from project conventions + handoff):**
- `/c/php83/php.exe` only; run `-l` + phpstan L8 + phpunit + guard after EVERY task.
- Parameterized SQL only (SEC-006). Verify schema against LIVE MariaDB (`routlaw_test_*`).
- Every entity carries `company_id`; write paths scoped (`WHERE id=? AND company_id=?`); every task includes a cross-tenant isolation assertion.
- Soft-delete regulated entities (`softDelete()` + audit); never hard-delete.
- Secrets: `RL_*` via gitignored `config/secrets.local.php`; `guard.php` must PASS. Never hardcode.
- **No hallucinated values (BR-005):** absent mandatory input → `ABSTAIN`/route-to-human, never guess. This is the same discipline as Phase 3 T8.
- Do NOT touch the aiwebscapes-platform Docker stack.

---

## §1 Phase 4 Exit Gate (STOP here — do NOT proceed to Phase 5)

All of the following must be ACTUAL command output, not assertions:
- `/c/php83/php.exe vendor/bin/phpunit --no-coverage` → all Phase-4 tests green (incl. an AI-eval-style critical suite where **hard-gate violation rate = 0** per FRD §19.3).
- `/c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M` → L8 clean.
- `/c/php83/php.exe scripts/guard.php` → PASS.
- No cross-tenant leakage in any Phase-4 test.
- Hard-gate override in the critical suite = 0 (FRD §19.3 / §19.4).
- Report and WAIT. Do not start Phase 5 (T14+).

---

## §2 Task breakdown (bite-sized, TDD-first)

### T10.0 — Add `cdl_status` to carriers (schema gap per FRD §12.4)
**Objective:** Close the gap between FRD logical schema and migration `002_carriers.sql`.
**Files:** Create `migrations/007_carriers_cdl_status.sql`; Test `tests/CarrierSchemaTest.php`.
**Steps:**
1. Write failing test: `assertNotNull` on `cdl_status` column in a freshly applied `007` DB; assert ENUM values include `unknown`/`non_cdl`/`cdl_a`/`cdl_b`/`cdl_c` and default `unknown`.
2. Run → FAIL (column absent).
3. Write migration: `ALTER TABLE carriers ADD COLUMN cdl_status ENUM('unknown','non_cdl','cdl_a','cdl_b','cdl_c') NOT NULL DEFAULT 'unknown' AFTER mc_number;` (idempotent: `IF NOT EXISTS` guard via a pre-check `SELECT` in PHP apply, or wrap in a stored-proc-free existence check).
4. Run → PASS. Commit `git commit -m "Phase4 T10.0: add carriers.cdl_status (FRD §12.4 gap)"`.

### T10.1 — HardGate engine: weight/dimension/equipment deterministic gates
**Objective:** Deterministic gates from REAL columns: load `weight_lbs` ≤ equipment `payload_capacity_lbs`; load dimension (if provided) fits `deck_length_ft`/`deck_width_ft`; equipment profile `status='approved'` & `is_complete=1` (else ABSTAIN).
**Files:** Create `src/Gates/HardGateEngine.php` + `src/Gates/GateResult.php`; Test `tests/HardGateTest.php`.
**Steps:** TDD RED→GREEN for: (a) overweight → FAIL; (b) within payload → PASS; (c) approved+complete equipment → PASS; (d) incomplete equipment → ABSTAIN; (e) missing `weight_lbs` → ABSTAIN (no fabricated weight). Commit per gate-group.

### T10.2 — HardGate engine: CDL/hazmat/HOS/ELD compliance flag (FR-016)
**Objective:** Implement the FR-016 compliance check as **honest flagging, not fabrication**.
**Files:** Extend `src/Gates/HardGateEngine.php`; Test `tests/HardGateTest.php`.
**Logic (per FRD §19.2 + BR-005):**
- `cdl_status` present and load within non-CDL envelope (e.g. weight ≤ non-CDL threshold *if a documented threshold constant exists*) → PASS; else ABSTAIN with reason `cdl_rating_unknown` / `exceeds_non_cdl_envelope`.
- hazmat / HOS / ELD: if no carrier-entered data exists → **ABSTAIN** with explicit reason (`hazmat_unknown`, `hos_applicability_unknown`, `eld_applicability_unknown`). NEVER invent a hazmat class or HOS rule.
- Any FAIL or ABSTAIN ⇒ decision cannot be `recommended`; ABSTAIN ⇒ `needs_review`.
**Steps:** TDD for each: known-CDL-pass, unknown-CDL-abstain, hazmat-unknown-abstain, no-fabrication assertions. Commit.

### T10.3 — Gate evaluation runs BEFORE scoring; drives decision status
**Objective:** Wire `HardGateEngine` so a gate result is computed first and blocks `recommended`.
**Files:** Create `src/Gates/GateService.php` (orchestrates engine, persists `gate_results`); Test `tests/HardGateTest.php`.
**Steps:** TDD: `evaluate(load, carrier, equipment)` returns `GateResult` aggregate (all PASS → `clear`; any FAIL → `blocked`; any ABSTAIN → `needs_review`). Persist to a `gate_results` table (add to migration). Critical-suite fixture: overweight load → 0 recommended, hard-gate violation = 0. Commit.

### T10.4 — Cross-tenant isolation for gates + critical AI-eval suite
**Objective:** Gates never leak across `company_id`; build the §19.3 critical eval cases (overweight, incomplete profile, unknown HOS/ELD, broker ambiguity, duplicate, adversarial-send).
**Files:** Extend `tests/HardGateTest.php` + new `tests/Phase4CriticalEvalTest.php`.
**Steps:** TDD assertions: company A's gate result never returns company B data; critical suite counts hard-gate violations = 0. Commit.

### T11.1 — Versioned economics: cost from `carrier_cost_profiles`
**Objective:** Total cost from versioned, effective-date cost profile × entered distance; reproducible from stored version snapshot.
**Files:** Create `src/Economics/EconomicsService.php` + `decision_input_snapshots` (see T12.1); Test `tests/EconomicsTest.php`.
**Steps:** TDD: given active `per_mile` profile + entered miles → cost = rate×miles, tagged with profile `version` + `effective_from` (reproducible). Stale/incomplete profile → ABSTAIN. Commit.

### T11.2 — Economics: distance/time honest abstention + total-mile/time rollup
**Objective:** Distance/time-dependent figures ABSTAIN when not entered; otherwise compute total-mile/time economics from versioned inputs.
**Files:** Extend `src/Economics/EconomicsService.php`; Test `tests/EconomicsTest.php`.
**Steps:** TDD: no `posted_rate` or no distance → ABSTAIN (no fabricated miles); both present → compute total-mile cost + net; assert stored input version reproduces the number. Commit.

### T12.1 — Decision case schema + input snapshot (FRD §12.4)
**Objective:** Author the decision tables so alternatives/variance have a home.
**Files:** Create `migrations/008_decision_cases.sql` (`decision_cases`, `decision_input_snapshots`, `decision_outcomes`, `prediction_variances`, `counterparty_observations`, `facility_observations`). Test `tests/DecisionSchemaTest.php` (columns + tenant column present).
**Steps:** TDD: apply migration in throwaway DB; assert each table + `company_id` present. Commit.

### T12.2 — Decision alternatives generator (FR-054/055/056/059)
**Objective:** Produce structured alternatives: accept / reject / negotiate / delay / combine / avoid, each with reasons, risks, assumptions, confidence, next-action; schedule/commitment conflict ⇒ `needs_review`; gate/uncertainty ⇒ ABSTAIN where mandatory evidence missing.
**Files:** Create `src/Decisions/DecisionService.php`; Test `tests/DecisionAlternativeTest.php`.
**Steps:** TDD: (a) clear case → alternatives list with required fields; (b) schedule conflict → `needs_review`; (c) missing mandatory input → ABSTAIN with reason; (d) confidence never sole signal (assert `confidence` + `reasons` both present). Commit.

### T12.3 — Persist decision case + tenant-scoped CRUD
**Objective:** Store decision case (status, selected alternative, gate result ref, input snapshot ref) with scoped write paths.
**Files:** Extend `src/Decisions/DecisionService.php`; Test `tests/DecisionAlternativeTest.php`.
**Steps:** TDD: create → read-back scoped by `company_id`; write-path isolation assertion (update with wrong `company_id` affects 0 rows). Commit.

### T13.1 — Counterparty / facility observations (FR-057/058)
**Objective:** Capture counterparty (broker/shipper) and facility (pickup/delivery) observations, tenant-scoped, soft-deletable.
**Files:** Extend `src/Decisions/DecisionService.php` (or new `src/Decisions/ObservationService.php`); Test `tests/ObservationTest.php`.
**Steps:** TDD: create counterparty obs + facility obs; read-back scoped; soft-delete + audit. Commit.

### T13.2 — Outcome capture + predicted-vs-actual variance (FR-060/061)
**Objective:** Link actual outcome to approved decision; compute variance from original input + version snapshot; classify missing-data / estimation-error / exception.
**Files:** Extend `src/Decisions/DecisionService.php`; Test `tests/OutcomeVarianceTest.php`.
**Steps:** TDD: record outcome → `prediction_variances` row computed vs stored snapshot; classification field set; tenant-scoped. Commit.

### T13.3 — Integration: full Phase 4 loop + final gate
**Objective:** End-to-end: load → gates → economics → alternatives → decision case → outcome → variance; run full suite + phpstan + guard.
**Files:** `tests/Phase4IntegrationTest.php`; finalize.
**Steps:** TDD integration covering one operator workflow; then run the §1 exit-gate commands and capture ACTUAL output. Commit `git commit -m "Phase 4: gates + economics + decisions + variance (T10-T13)"`. **Do NOT push.**

---

## §3 Open items (report at exit, do not block)

1. **AI provider (#2/#11):** local Ollama recommended; needs owner sign-off. Blocks T9 live LLM call only — NOT Phase 4 (Phase 4 is deterministic, no LLM).
2. **Map/mileage provider (#3):** absent → T11 ABSTAINs on distance figures. Decide before any distance-dependent feature.
3. **Hostinger TRIGGER grant:** confirm plan grants it (DEFINER=CURRENT_USER already used per Phase 1 lesson).
4. **FRD PDF twin:** missing (docx only).
5. **BRD R4:** cites GenAI LLM Top 10 "2025" → superseded by 2026; fix before AI-threat work.
6. **Schema correction needed upstream:** `carriers.cdl_status` added in T10.0; consider a follow-up ADR noting FRD §12.4 vs migration drift.

---

## §4 Verification commands (run after every task)

```bash
/c/php83/php.exe -l <changed file>
/c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M
/c/php83/php.exe vendor/bin/phpunit --no-coverage
/c/php83/php.exe scripts/guard.php
```

## §5 Execution notes
- Fresh subagent per task (context isolation). Two-stage review every time: spec compliance (FR/BR ID in heading) → code quality. Do not skip either.
- Costs a lot of subagent calls but catches issues early — non-negotiable for this regulated build.
- Each commit is a checkpoint; **do NOT push** without explicit approval.
- At the Phase 4 exit gate, paste ACTUAL phpunit/phpstan/guard output into the report — not a description.
