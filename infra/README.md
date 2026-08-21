# ROUTLAW Infra (build-plan §3)

Deployment source-of-truth for the All-PHP / XAMPP / MariaDB runtime.

## Apache vhost (`:8080`, docroot = `public/`)

- `apache/httpd-vhost-routlaw.conf` — committed `<VirtualHost *:8080>` block.
  Copy into `C:/xampp/apache/conf/extra/httpd-vhosts.conf` (present since
  2026-08-21 setup). This keeps `config/ src/ migrations/ storage/ vendor/
  scripts/` outside the web tree (SEC-007 / ASVS L2).
- `httpd.conf` also needs `Listen 8080` (added 2026-08-21).
- Restart Apache via the XAMPP Control Panel after editing (the panel
  auto-respawns the shared instance; do not `taskkill` it — mokimi shares it).

## Verify

```
curl -sI http://127.0.0.1:8080/                     # 200 + CSP (1, no -Report-Only)
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/src/...   # 404 (outside docroot)
```

## Worker (planned — Phase 1+)

`bin/worker.php` polls `async_jobs` (MariaDB claim-lock). Run under Windows
Task Scheduler — never a manual start (see local-ai stack SOT pattern).
