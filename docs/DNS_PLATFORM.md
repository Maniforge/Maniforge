# Platform core public hostname (Maniforge reference box)

**Chosen FQDN:** `platform.maniforge.ru`

**Pattern:** `platform.<brand>.<tld>` — dedicated API/gateway hostname (not bare IP in buyer docs). TLS terminates on the **shared edge Caddy** already bound to `:80`/`:443`; the platform stack keeps **host Caddy on `:18090`** → loopback Go services (`8093`–`8097`).

## DNS (registrar / Cloudflare)

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | `platform` | `79.174.90.4` | 300 (auto if Cloudflare) |

1. Open DNS for **maniforge.ru** (same panel as `maniforge.ru` → `79.174.90.4`).
2. Add **A** record: host `platform`, value `79.174.90.4`.
3. If using Cloudflare: start with **DNS only** (grey cloud) until HTTPS is verified, or enable proxy after cert works.
4. Verify: `dig +short platform.maniforge.ru` → `79.174.90.4`.

## Edge TLS (ports 80/443 already in use)

On reference host, edge Caddy owns public 80/443. Do **not** bind a second Caddy on 443 for platform.

1. Append `deploy/caddy/edge-platform.caddy` with `{domain}` replaced by `platform.maniforge.ru` to the edge Caddyfile.
2. Reload edge Caddy (docker exec … caddy reload).
3. Acceptance: `curl -sf https://platform.maniforge.ru/rbac/health`

## Production env before DNS propagates

```bash
sudo bash deploy/scripts/install-production.sh \
  --domain platform.maniforge.ru --edge-proxy \
  --skip-apt --non-interactive
```

Staging acceptance until DNS/TLS: `http://79.174.90.4:18090/rbac/health`

## Restore drill

After backup timer is enabled:

```bash
cd /opt/maniforge/platform-core
bash deploy/scripts/backup-postgres.sh
# verify dump exists under /var/backups/maniforge/
```
