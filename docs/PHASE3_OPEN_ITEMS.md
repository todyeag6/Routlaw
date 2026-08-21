# ROUTLAW — Phase 3 Open Items (reported at exit, non-blocking)

Phase 3 (T7/T8/T9) shipped and pushed (`e6fe080`). These remain open and are
tracked for owner sign-off — none block the Phase 3 exit gate.

| # | Item | Status | Blocker / Action |
|---|------|--------|-----------------|
| 1 | **AI provider (#2/#11)** | OPEN | Local Ollama (keyless/self-hosted) recommended. Blocks T9 *live* LLM call only — isolation tests already pass. Needs owner sign-off on model + data-processing terms. `LoadExtractionService` interface is provider-agnostic. |
| 2 | **Hostinger `TRIGGER` grant** | UNCONFIRMED | Plan must grant `TRIGGER`; if not, append-only audit degrades to PHP-only. Verify against Hostinger plan. |
| 3 | **FRD PDF twin missing** | GAP | Only `.docx` delivered (BRD has dual-format parity). Doc-set gap. |
| 4 | **BRD R4 GenAI LLM Top 10** | STALE | BRD cites "2025"; superseded by **2026** per OWASP. Fix before any AI-threat work. |
| 5 | **Phase 4** | NOT STARTED | Hard gates / economics / decision alternatives. Do not start without approval. |

## Standards baseline (verified 2026-08-21)

- OWASP ASVS 5.0.0 — current
- OWASP GenAI LLM Top 10 — **2026** (BRD R4 stale)
- WCAG 2.2 — current
- NIST AI 600-1 — final
- NIST SP 800-63-4 — final July 2025

## Stack (locked)

All-PHP 8.3.33 / XAMPP / MariaDB 10.11.14 (live-verified; plan cites 10.4). No Python/Docker/Redis.
Binary: `/c/php83/php.exe`. Hostinger shared + Cloudflare + Apple iCloud SMTP.
