# ADR 0001 — Phase 6 FR-to-Task Mapping (T18 memory, leakage gate)

**Status:** Accepted (2026-08-23). **T19 scope updated 2026-08-23 by owner directive**: T19
(FR-038/039/041) is now IN scope this session — owner authorized local LLM (Decision #2/#11
lifted). FR split unchanged: T18 = FR-034/035/036/037 + leakage gate; T19 = FR-038/039/041.

**Context:** The Phase 6 build-plan, kickoff, and handoff disagree on which FR IDs belong to
T18 vs T19, and specifically on ownership of `FR-040` and the cross-tenant retrieval
leakage = 0 gate (§19.3). The FRD source text is not in this repo as plain text, so the
mapping below is inferred from the build-plan's own wording and resolved here to unblock P6-9.

**Decision (recommended mapping):**
- **T18 (shipped this session)** owns:
  - `FR-034` provenance-tagged memory entries
  - `FR-035` tenant/role-scoped retrieval
  - `FR-036` contradiction handling (no silent overwrite)
  - `FR-037` expiry/revalidation (LLM04)
  - **`FR-040` — cross-tenant/role-scoped retrieval returns only caller-scoped entries,
    filtered BEFORE ranking, with the hard gate `cross-tenant leakage = 0` (§19.3).**
    Rationale: the handoff (lines 75–78) and kickoff (line 40) both cite `FR-040` under
    T18's memory retrieval, and the leakage gate is described under T18 in both docs.
- **T19 (IN SCOPE — owner authorized 2026-08-23, local LLM sign-off)**:
  - `FR-038` self-correction verifier
  - `FR-039` post-outcome learning
  - `FR-041` proposals that cannot self-promote
  (Was deferred pending sign-off; owner directive "set t19 up with local llm" lifts the
  Phase 6 STOP condition for T19's LLM wiring. LLM is reasoning-only; all policy/status
  transitions stay server-side PHP.)

**Consequence:** The §19.3 `cross-tenant retrieval leakage = 0` requirement is enforced and
tested in T18 (`MemoryStoreTest::test_cross_tenant_retrieval_leakage_is_zero`). It is a hard
release gate for Phase 6.

**Revisit if:** the owner supplies the FRD PDF and `FR-040` is explicitly mapped elsewhere;
in that case update this ADR and the T18 test references accordingly.
