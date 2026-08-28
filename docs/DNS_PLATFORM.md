# DNS and TLS for Production Box

**Audience:** buyer DevOps — configure **your** FQDN on **your** server.

**Pattern:** `platform.<your-brand>.<tld>` — dedicated API/gateway hostname (not bare IP in production docs). TLS terminates on Caddy (`:443` direct, or on a **shared edge** reverse proxy if `:443` is already in use). The platform stack keeps host Caddy on **`:18090`** → loopback Go services (`8093`–`8097`).

> Internal Maniforge QA reference (not buyer path): [`kb/PLATFORM_PRODUCTION.md`](../kb/PLATFORM_PRODUCTION.md)

---

## DNS (registrar / Cloudflare)

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | `platform` (or your chosen host) | `<your-server-public-IP>` | 300 (auto if Cloudflare) |

1. Open DNS for **your** domain (e.g. `example.com`).
2. Add **A** record: host `platform`, value **your server's public IPv4**.
3. If using Cloudflare: start with **DNS only** (grey cloud) until HTTPS is verified, or enable proxy after cert works.
4. Verify: `dig +short platform.example.com` → your server IP.

---

## Direct HTTPS (Caddy owns :443)

When ports 80/443 are free on the install host:

```bash
sudo bash deploy/scripts/install-maniforge.sh \
  --domain platform.example.com --skip-apt --non-interactive
bash deploy/scripts/verify-maniforge.sh
curl -sf https://platform.example.com/rbac/health
```

`install-maniforge.sh` renders `deploy/Caddyfile.production` → `Caddyfile.active` and sets `MANIFORGE_CADDYFILE` in `.env.platform`.

---

## Edge TLS (ports 80/443 already in use)

When another service (shared edge Caddy, nginx, etc.) already binds public `:80`/`:443`, do **not** start a second listener on `:443` for platform.

1. Install platform with edge-proxy profile (gateway stays on host `:18090`):

```bash
sudo bash deploy/scripts/install-maniforge.sh \
  --domain platform.example.com --edge-proxy \
  --skip-apt --non-interactive
```

2. Append the rendered snippet to your edge config:

```bash
# Generated during install (domain substituted):
cat deploy/caddy/edge-platform.example.com.caddy
# Template source: deploy/caddy/edge-platform.caddy — replace {domain}
```

3. Reload edge Caddy (example: `docker exec <edge-container> caddy reload --config /etc/caddy/Caddyfile`).
4. Acceptance: `curl -sf https://platform.example.com/rbac/health`

**Staging until DNS/TLS:** `http://<your-server-ip>:18090/rbac/health`

---

## Restore drill

After backup timer is enabled:

```bash
cd /opt/maniforge/platform-core
bash deploy/scripts/backup-postgres.sh
# verify dump exists under /var/backups/maniforge/
```
