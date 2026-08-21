# ROUTLAW — TDD Gate (run after every task)

**Must be green before a task is "done":**

```bash
# 1. PHP syntax (per changed file)
/c/php83/php.exe -l <file>

# 2. PHPStan level 8 (official: scanFiles for stubs, not bootstrapFiles)
/c/php83/php.exe vendor/bin/phpstan analyse src/ --no-progress --memory-limit=512M

# 3. Full test suite
/c/php83/php.exe vendor/bin/phpunit --no-coverage

# 4. Secrets guard (never hardcode; RL_* via gitignored config/secrets.local.php)
/c/php83/php.exe scripts/guard.php
```

- `ROUTLAW_NO_SESSION` handled via `dynamicConstantNames` (official PHPStan pattern).
- Baseline: `phpstan-baseline.neon`.

## Rules

- `declare(strict_types=1)` at top of every PHP file.
- PSR-4: `Routlaw\` → `src/`, `Tests\` → `tests/`.
- PHP binary: `/c/php83/php.exe` (8.3.33, ext-sodium) — NOT XAMPP's 8.2.
- Two classes per file fails PSR-1 + breaks PSR-4 → split files.
- Migrations MUST be idempotent: `CREATE TABLE IF NOT EXISTS`, inline indexes, guarded `INSERT`s.
- Verify schema against the **live** MariaDB, not file greps (throwaway `routlaw_test_*` / `routlaw_verify_*` DBs).
- Run the gate before every checkpoint commit. Do NOT push without explicit approval.
