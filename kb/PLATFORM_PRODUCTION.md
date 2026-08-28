# Platform production — internal reference install (QA)

> **Internal — not part of Production Box buyer path.**  
> Покупатель разворачивает Production Box на **своём** сервере и **своём** FQDN. Этот документ описывает только reference install Maniforge для QA.

Buyer DNS/TLS runbook: [docs/DNS_PLATFORM.md](../docs/DNS_PLATFORM.md) (generic `platform.example.com`).

## Reference host (Maniforge QA only)

| Item | Value |
|------|--------|
| Server | `79.174.90.4` (Azure Cadmium, SSH `nzgapp`) |
| Install root | `/opt/maniforge/platform-core` |
| Staging gateway | `http://79.174.90.4:18090/rbac/health` |
| Optional internal demo FQDN | `platform.maniforge.ru` → DNS A-record **pending** |
| Target HTTPS (when DNS live) | `https://platform.maniforge.ru/rbac/health` |

**Buyer acceptance URL:** `https://<customer-fqdn>/rbac/health` on the customer's infrastructure — not this host.

## Internal DNS (platform.maniforge.ru)

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | `platform` | `79.174.90.4` | 300 |

1. DNS panel for **maniforge.ru** (same as apex `maniforge.ru` → `79.174.90.4`).
2. Add **A** record: host `platform`, value `79.174.90.4`.
3. Verify: `dig +short platform.maniforge.ru` → `79.174.90.4`.

Edge TLS on reference host (shared `:443`):

```bash
sudo bash deploy/scripts/install-maniforge.sh \
  --domain platform.maniforge.ru --edge-proxy \
  --skip-apt --non-interactive
# Append deploy/caddy/edge-platform.maniforge.ru.caddy to edge Caddyfile; reload edge.
curl -sf https://platform.maniforge.ru/rbac/health
```

Staging until DNS/TLS: `http://79.174.90.4:18090/rbac/health`
