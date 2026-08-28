# Platform production — internal reference install (QA)

> **Internal — not part of Production Box buyer path.**  
> Покупатель разворачивает Production Box на **своём** сервере и **своём** FQDN. Этот документ описывает только reference install Maniforge для QA.

См. также [docs/DNS_PLATFORM.md](../docs/DNS_PLATFORM.md).

## Reference host (Maniforge QA only)

| Item | Value |
|------|--------|
| Server | `79.174.90.4` (Azure Cadmium, SSH `nzgapp`) |
| Install root | `/opt/maniforge/platform-core` |
| Staging gateway | `http://79.174.90.4:18090/rbac/health` |
| Optional internal demo FQDN | `platform.maniforge.ru` → DNS A-record **pending** |
| Target HTTPS (when DNS live) | `https://platform.maniforge.ru/rbac/health` |

**Buyer acceptance URL:** `https://<customer-fqdn>/rbac/health` on the customer's infrastructure — not this host.
