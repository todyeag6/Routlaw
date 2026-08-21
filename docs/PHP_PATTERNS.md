# ROUTLAW — PHP Patterns (verified-live lessons)

## `mysqli::bind_param` type-strings

Two failure modes, neither caught by PHPStan L8 or `exit=0`:

1. **`ArgumentCountError`** — type-string length ≠ number of bind vars (throws at execute).
2. **SILENT column corruption** — length is right but one char is wrong (e.g. `i` where `s`
   belongs). The insert "succeeds" but a later column (often trailing `status`) receives a
   shifted value → `Data truncated for column 'status'` or, worse, silent wrong-state.

**Fix — build programmatically, assert arity before execute:**

```php
$types  = ['i','i','i','s','s','s','s','s','i','s','s','s','s','s','i','s','i'];
$typeStr = implode('', $types);
// assert strlen($typeStr) === count($bindVars) before bind_param(...)
$stmt->bind_param($typeStr, $companyId, $srcVar, $brokerVar, $originCity, /* … */);
```

Never hand-type the literal — the human count is wrong far more often than not.

## Regex extraction — anchor keywords with `\b`

Parsing untrusted text (email/doc/load body) with a bare alternation like
`(?:deliver|dest|to)` **matches the `to` inside "Hous*to*n"** → false `dest_city`.
Always anchor multi-letter triggers:

```php
// WRONG: 'to' is a substring of 'Houston' → false dest_city
'/(?:deliver|dest|to)[:\s,]*([A-Z][a-zA-Z.\' ]+?)/i'
// RIGHT
'/(?:\bdeliver(?:y)?\b|\bdest\b|\bto\b)[:\s,]*([A-Z][a-zA-Z.\' ]+?)/i'
```

Apply the same `\b…\b` to `from`, `origin`, `rate`, `weight`, etc.

## Other (see docs/ and vault `Routlaw Phase 2 Hardening`)

- Trigger `DEFINER=CURRENT_USER` (root@localhost breaks Hostinger SUPER-less deploy).
- Tenant-scoped writes: `UPDATE … WHERE id = ? AND company_id = ?`.
- Soft-delete real + audited (`softDelete()` sets metadata + audit event).
- `AuditLog::recordSystem()` always pass `targetType`/`targetId` (null = untraceable).
- `fetch_column()` returns `false` (not `null`) on no row — use `!== false && !== null`.
