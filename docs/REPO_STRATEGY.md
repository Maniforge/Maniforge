# Repository strategy

> **Internal document** — migration and org structure. Not part of the buyer-facing install path. For production deployment, use [PRODUCTION_BOX.md](PRODUCTION_BOX.md) and the root [README.md](../README.md).

## Canonical repos

| Repo | Role | Audience |
|------|------|----------|
| **[github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge)** | **Public platform core** — Production Box, Go services, deploy | Buyers, DevOps, GitHub |
| **github.com/Maniforge/maniforge_low_code_platform** | **Development lab** — PHP journeys, examples, supply-chain cmd | Internal engineering |
| **wms.svitex.online** | WMS / avtosbor cutover | Svit product |

**Server install path:** `/opt/maniforge/platform-core` — clone **Maniforge/Maniforge** `platform-core`, not the lab repo.

---

## Why Maniforge/Maniforge

- Org repo name matches product; no «low_code_platform» in sales narrative.
- Public face = **Go + PostgreSQL + Caddy** production stack.
- Lab repo retains PHP journeys, examples, and extended development tree.

---

## Migration (branch `platform-core`)

**Do not force-push `main`** until COO approves merge or replace.

### Step 1 — Bridge (lab repo)

Commit deploy stack, kb, production docs:

```powershell
cd E:\Artem\maniforge_low_code_platform
git add deploy/ kb/ docs/ Makefile .dockerignore
git status   # .env must NOT be staged
git commit -m "Platform production box: deploy scripts, kb, install/verify"
git push origin main
```

### Step 2 — Push to Maniforge/Maniforge

```powershell
git remote add maniforge-platform https://github.com/Maniforge/Maniforge.git
git fetch maniforge-platform
git push maniforge-platform main:platform-core
```

GitHub: default branch → **`platform-core`**. Tag **`v0.1.0-box`**.

### Step 3 — Tree scope

| Include (minimum sellable box) | Exclude (lab / marketing) |
|--------------------------------|---------------------------|
| `cmd/` core services + migrate/preflight | `site-maniforge-ru/` |
| `internal/`, `migrations/pg/`, `deploy/` | `web/` (WMS draft) |
| `docs/PRODUCTION_BOX.md`, `kb/`, `Makefile` | `examples/`, supply-chain `cmd/` |
| `frontend/apps/admin` (optional) | APK assets |

**Server cutover:**

```bash
cp /opt/maniforge/platform-core/deploy/.env.platform /root/maniforge.env.platform.bak
cd /opt/maniforge
mv platform-core platform-core.bak.$(date +%F)
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git platform-core
cp /root/maniforge.env.platform.bak platform-core/deploy/.env.platform
cd platform-core
sudo bash deploy/scripts/install-production.sh --skip-apt --non-interactive
bash deploy/scripts/verify-production.sh
```

Archive `Maniforge/maniforge_low_code_platform` with README pointing to **Maniforge/Maniforge**.

---

## Cutover checklist

- [x] Commit + push deploy/kb/docs in lab repo
- [x] `docs/ORG_PROFILE.md` + `docs/V0.1_BOX_MANIFEST.md` in tree
- [x] Push `platform-core` → **Maniforge/Maniforge**
- [x] GitHub: default branch = `platform-core`
- [x] GitHub: visibility = **public**
- [x] Tag **`v0.1.0-box`**
- [ ] Archive `maniforge_low_code_platform`
- [x] Server: `git clone --branch platform-core`, verify OK
- [x] `verify-production.sh` → OK after server git cutover
