# ROUTLAW — Phase 5 Execution Plan (T14–T16)

> **Controller:** This is the operational plan for the Phase 5 session. It was derived
> from `.hermes/plans/2026-08-21_095000-routlaw-build-plan.md` (authoritative, §4 "Phase 5"
> lines 105–108) and the banked handoff `.hermes/plans/2026-08-21_routlaw-phase5-handoff.md`.
> It adds live-verified repo facts and concrete task boundaries. **Do NOT execute until the
> user gives an explicit GO.**

## 0. Live-verified baseline (probed 2026-08-22, not assumed)

Run from `C:/xampp/htdocs/Routlaw` with `/c/php83/php.exe`:

- `phpunit --no-coverage` → exit **0**, `Tests: 168, Assertions: 499, Skipped: 1`
- `phpstan analyse src/ --no-progress --memory-limit=512M` → exit **0** (`[OK] No errors`)
- `scripts/guard.php` → exit **0** (`GUARD PASS`)
- Migrations present: `000`–`009` (foundation, audit, carriers, equipment, cost, leads,
  loads, cdl_status, gate_results, decision_cases). **No** `approval_queue`, `messages`,
  `message_drafts`, or `documents` table exists (grep hits were incidental: `needs_documents`
  enum, `documents.share` / `approvals.outbound` permission *codes*).
- `src/Mail/Mailer.php` exists (iCloud SMTP via PHPMailer; throws when unconfigured).
- Phase-5 infrastructure already in place: `storage/documents/` is OUTSIDE `public/` docroot
  and gitignored (`storage/documents/*`); RBAC is seeded with `approvals.outbound` and
  `documents.share`; `Authorization::hasPermission(userId, companyId, code)` and
  `Authorization::scopeQuery(...)` are available for reuse; `AuditLog::recordSystem` for
  append-only events.

**Consequence:** Phase 5 is greenfield *within* the existing stack. Reuse `src/Db/Connection`,
`src/Security/{Auth,Authorization,AuditLog,Mailer}`, `src/Decisions` neighbors. Do NOT
re-scaffold.

## 1. Scope (this session ONLY)

T14 (FR-021 message drafting) · T15 (FR-022/023/024 approval queue) · T16 (FR-025/026/027/028
private documents). **STOP at the Phase 5 exit gate. Do NOT proceed to Phase 6.**

Out of scope (do not touch): `aiwebscapes-platform` Docker stack; any live-LLM/Ollama call
(Phase 5 needs none — drafting/queue/document services are pure PHP + storage).

## 2. Non-negotiables (from handoff, enforced)

1. **TDD per task:** failing test → implement → verify. Vertical tracer bullets, not horizontal
   slices. Every task: spec-compliance assertion against its FR/BR ID + cross-tenant isolation
   assertion.
2. **Tenant scoping:** every new entity carries `company_id`; reads filter AND writes scope
   (`WHERE id=? AND company_id=?`). Cross-tenant leakage = release blocker.
3. **Parameterized SQL only** (SEC-006). Build `bind_param` type strings from a params array and
   run an arity+type guard (the Phase-4 `assertBindArity` shape) — a count-only check misses a
   shifted type char that silently truncates a trailing column.
4. **Verify schema against the LIVE DB** in a throwaway `routlaw_test_*` database; never trust
   file greps for absence.
5. **PHP binary:** `/c/php83/php.exe` (8.3.33, ext-sodium). NOT XAMPP 8.2.
6. **Capture real exit codes.** Never pipe phpstan/phpunit through `tail`/`head` (masks failures).
   Capture to a file, echo the code, then read.
7. **Soft-delete regulated entities** (`softDelete()` + audit event with `target_type`/`target_id`).
   Never hard-delete.
8. **Secrets:** `RL_*` env via gitignored `config/secrets.local.php`; `guard.php` must PASS.
   Never hardcode.
9. **Commit per checkpoint. Do NOT push without explicit approval.**
10. **No `CREATE DATABASE`/`USE`** in migrations; `DEFINER=CURRENT_USER` on any trigger; idempotent
    `IF NOT EXISTS`.

## 3. Phase 5 contracts (verbatim FR IDs from build plan §4)

- **T14 FR-021:** message drafting MUST be editable, source-linked (FK to the decision/load/lead
  it serves), and MUST NOT be able to self-send. The drafting service and the send path are
  SEPARATE; the draft store cannot trigger delivery on its own.
- **T15 FR-022/023/024:** approval request carries actor + action + resource + payload-hash +
  expiry. PAYLOAD-BOUND and REPLAY-SAFE: any modification of the payload invalidates the
  approval (new approval required). Test MUST prove approval replay + tampered-payload rejection.
- **T16 FR-025/026/027/028:** private document upload with MIME + signature check, randomized
  storage key, no executable content, no public web path (non-web dir with signed-access PHP
  endpoint). Classification is a LABEL, not legal validity. Human verification + approval-gated
  sharing; the model/agent CANNOT directly share a document.

## 4. Architecture decisions (baked, not open)

- **T14 separation (FR-021 "cannot self-send"):** `MessageDraftService` does CRUD only and never
  imports/uses `Mailer`. A distinct `OutboundService::approveAndSend()` performs delivery and
  REQUIRES (a) the `approvals.outbound` permission and (b) a *separate* human actor from the
  drafter. Draft rows hold zero mail logic → cannot self-send by construction.
- **T15 binding (FR-022/023/024):** canonicalize payload (`action|resource|json_encode(payload,
  canonical)`), hash with `hash('sha256', ...)` (sodium present but sha256 is sufficient and
  portable). An `ApprovalService` returns **valid only if** a stored, non-expired approval's hash
  equals the *current* canonical hash of the live payload. Tamper → hash mismatch → reject. Replay
  → same payload hash is fine, but expiry + single-use (consume on apply) prevents replay.
  `payload-hash + expiry` persisted; `actor/action/resource` explicit.
- **T16 storage (FR-025/026/027/028):** randomized key =
  `sodium_bin2hex(random_bytes(32))`; file lives at
  `storage/documents/<company_id>/<key>.<ext-from-allowlist>` (outside docroot). MIME sniff via
  `finfo` + extension allowlist (no `exe/php/phtml/sh/bat` etc.); size cap; store file SHA-256 as
  `signature` (FR-025 "sig check" = integrity signature, not a CA signature — keep scope tight);
  `classification` column is a free-text label only. Signed-access endpoint `public/doc.php?k=<key>&sig=<hmac>`
  resolves the row, checks `documents.share`/ownership, streams with `Content-Disposition:
  attachment` + `X-Content-Type-Options: nosniff`. Model/agent cannot call the send/share path.

## 5. Task breakdown (≈10 tasks; each TDD, own commit)

### T14 — Message drafting (FR-021)
- **T14.1 Migration `010_message_drafts.sql`:** table `message_drafts(company_id, id,
  source_type ENUM('decision','load','lead'), source_id, subject, body, status
  ENUM('draft','pending_approval','sent','rejected'), created_by, soft-delete cols +
  audit). Tenant-scoped unique/idx. No DB-level trigger needed (soft-delete in PHP).
  *Test: applied to throwaway DB; `company_id` present; status enum values correct.*
- **T14.2 `MessageDraftService` (CRUD, no mail):** create/update/list/softDelete, all scoped by
  `company_id`; source-linked (validates `source_type`/`source_id` references exist in-tenant).
  *Tests: create+edit+list scoped; cross-tenant create/list returns 0 rows; soft-delete emits
  audit with target_type/target_id.*
- **T14.3 "Cannot self-send" guard (FR-021):** `MessageDraftService` has NO method that touches
  `Mailer`; a static check / test asserts the class does not `use` `Routlaw\Mail\Mailer` and no
  send path exists in the draft store. *Test: reflection/import assertion + a unit test that
  proving an approval-and-send is impossible from the draft service alone.*
- **T14.4 `OutboundService::approveAndSend()`:** requires `approvals.outbound` permission AND
  actor ≠ drafter; emits `message.send` approval audit; flips draft status to `sent`. *Tests:
  drafter-sends-self → denied + audited; distinct privileged actor → sent; cross-tenant send →
  denied/0 rows.*

### T15 — Approval queue (FR-022/023/024)
- **T15.1 Migration `011_approval_queue.sql`:** `approval_requests(company_id, id, actor_id,
  action, resource_type, resource_id, payload_json, payload_hash, status
  ENUM('pending','approved','rejected','expired','consumed'), expires_at, created_at,
  decided_by, decided_at)`. `audit_events` already append-only.
- **T15.2 `ApprovalService::create()`:** builds canonical payload, stores hash+expiry, status
  `pending`. *Test: stored hash == recomputed canonical hash.*
- **T15.3 `ApprovalService::isApproved(resource, action, payload)`:** valid iff a non-expired,
  un-consumed `approved` row exists whose `payload_hash` == current canonical hash.
  *Tests (REQUIRED by contract):* (a) **tampered payload** → hash mismatch → rejected; (b)
  **replay** of an already-consumed/expired approval → rejected; (c) unmodified + valid + unexpired
  → approved; (d) expiry pass → rejected.
- **T15.4 Tie T14.4 to the queue:** `OutboundService::approveAndSend()` requires a *valid*
  `approvals.outbound` approval record (payload-bound to that exact send request). *Test: send
  without a matching valid approval → denied; with valid approval → sent; after approval consumed,
  second send → denied (replay).*

### T16 — Private documents (FR-025/026/027/028)
- **T16.1 Migration `012_documents.sql`:** `documents(company_id, id, storage_key, original_name,
  mime_type, size_bytes, content_sha256, classification VARCHAR label, verified_by,
  verified_at, share_status ENUM('private','shared','revoked'), soft-delete + audit)`.
- **T16.2 `DocumentService::upload()`:** randomized key (`sodium_bin2hex(random_bytes(32))`),
  `finfo` MIME + extension allowlist, size cap, no-exec check, store under
  `storage/documents/<company_id>/`, persist metadata incl. `content_sha256`. *Tests: valid upload
  stores file + row; executable/blocked-MIME rejected; cross-tenant upload path isolated by
  company dir.*
- **T16.3 Classification ≠ legal validity (FR-026/027):** `classification` is a free-text label,
  stored as-is, never used for any authorization decision; a `verified_by/verified_at` (human
  verification, FR-027) is separate and optional. *Test: classification value does not change
  access; document remains private until explicitly shared by a human with `documents.share`.*
- **T16.4 Signed-access endpoint `public/doc.php`:** resolves `storage_key` → row (tenant-scoped),
  checks `share_status='shared'` + permission/ownership, streams file with safe headers,
  `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment`. No direct public URL.
  *Tests: missing/invalid sig → 403; private (unshared) → 403; shared + valid sig → 200 + correct
  bytes; cross-tenant key → 0 bytes / 403.*
- **T16.5 Approval-gated sharing, no model share (FR-028):** `DocumentService::share()` requires
  `documents.share` + human actor, sets `share_status='shared'` + audit. **No service method
  callable by an agent/model performs sharing** — assert `DocumentService` shares only via the
  human-gated method (mirror T14.3's import/reflection guard). *Tests: agent-context share blocked;
  human with permission → shared; revoke → 403 again.*

## 6. Per-task execution loop (controller drives, subagents optional)

For each task:
1. Write the failing test(s) first (incl. cross-tenant isolation assertion).
2. Run the single test → confirm RED (fails for the right reason).
3. Implement minimal code → confirm GREEN.
4. Run full `phpunit` + `phpstan src/` + `guard.php` → capture REAL exit codes.
5. Two-stage review: (a) spec compliance vs FR/BR ID, (b) code quality.
6. Commit per checkpoint (`scripts/guard.php` + tests green). **No push without GO.**

## 7. Phase 5 exit gate (all must hold — ACTUAL output, not assertion)

- All Phase-5 tests green (`phpunit` exit 0); full suite regression-clean.
- `phpstan analyse src/ --no-progress --memory-limit=512M` exit 0 (L8).
- `scripts/guard.php` exit 0.
- Cross-tenant isolation proven for messages, documents, approvals (explicit assertions, 0 leaked rows).
- T15 tamper + replay rejection proven by tests.
- T14 "cannot self-send" + T16 "no model share" proven by tests.
- Migrations `010`–`012` apply clean on a throwaway `routlaw_test_*` DB; `company_id` on every new table.

Report results as real command output. **Stop and wait — do not enter Phase 6.**

## 8. OPEN (report, don't block)

- AI provider (#2/#11) — affects only live-LLM calls; none needed in Phase 5.
- Hostinger TRIGGER grant unconfirmed (migrations here add no triggers; if a future trigger is
  needed, use `DEFINER=CURRENT_USER`).
- FRD PDF twin missing (docx only). BRD R4 cites GenAI LLM Top 10 "2025" → superseded by 2026.
