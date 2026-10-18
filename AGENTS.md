# ROUTLAW — AGENTS.md

All-PHP 8.3 / XAMPP / MariaDB freight & carrier platform. This file is the
authoritative project context for any agent session rooted here — it loads
automatically. Trust it over chat summary; if it disagrees with the code,
the code is ground truth and this file should be updated.

## Stack (do not assume the bundled defaults)
- **PHP:** host-wide 8.3 at `C:/php83/php.exe` (8.3.33, has `sodium`).
  **Never** XAMPP's bundled 8.2.12 for composer / phpstan / phpunit.
- **DB:** MariaDB via XAMPP; reach it through the app's configured DSN.
- **Web server:** XAMPP Apache; docroot is this directory.

## Local AI wiring (keyless, no API keys)
- **Ollama** at `127.0.0.1:11434`. Model `qwen3.5:4b` (4.7B Q4_K_M).
- The client base URL MUST be `http://127.0.0.1:11434/v1` — the `/v1`
  prefix is required. Omitting it yields HTTP 404 on `/chat/completions`.
- `RL_AI_PROVIDER=Ollama` → keyless. No `OPENAI_API_KEY` / `ANTHROPIC_API_KEY`
  needed for the agent's own reasoning; the chat orchestration model
  (poolside/laguna-s-2.1:free via nous) is separate and only for planning.

## Conventions
- TDD gate: tests before code. `phpunit` runs against the 8.3 binary.
- `phpstan` level 8, PHP 8.3 typed arrays. Config lives in `phpstan.neon`.
- Tenant scoping is load-bearing — never widen a query's tenant filter.
- Migration + extraction patterns: see the repo's existing `migrations/` and
  the Phase 3 (leads/loads/injection-isolation) notes before touching schema.

## Test harness bootstrap

`tests/bootstrap.php` requires BOTH `vendor/autoload.php` AND `config/autoload.php`. Skipping the config autoload causes `$_ENV['RL_DB_*']` to stay unset, making `Connection::connect()` fall back to empty-password defaults — which fails against XAMPP MariaDB (root requires password `root`). If you see "Access denied for user 'root'@'localhost' (using password: NO)" in tests, check that the bootstrap chain is intact.

## Off-limits
- Do not edit XAMPP's `php.ini` / `my.ini` to "fix" a project need — change
  the project, not the shared runtime.
- Do not introduce a cloud LLM API key where the local Ollama path works.
