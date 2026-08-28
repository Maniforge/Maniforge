# Repository strategy — platform «быстрый свет»

## Canonical repos

| Repo | Role | Audience |
|------|------|----------|
| **[github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge)** | **Public platform core** — Production Box, Go services, deploy | Buyers, DevOps, GitHub |
| **github.com/Maniforge/maniforge_low_code_platform** | **R&D lab** — PHP journeys, examples, supply-chain cmd, marketing site | Internal dev, contract tests |
| **wms.svitex.online** | WMS / avtosbor cutover | Svit product |

**Server install path (unchanged):** `/opt/maniforge/platform-core` — clone **Maniforge/Maniforge**, not lab repo.

---

## Why Maniforge/Maniforge

- Org repo name matches product; no «low_code_platform» in sales narrative.
- Today **`main` = org-profile landing only** (1 commit, 2026-06) — saved in `docs/ORG_PROFILE.md` before cutover.
- Lab repo keeps PHP/APK/examples; public face = **Go + PostgreSQL + Caddy only**.

---

## Migration (recommended: branch `platform-core`)

**Do not force-push `main` on step 1** — unrelated histories; org README stays on old `main` until COO approves merge or replace.

### Step 1 — Bridge (lab repo `maniforge_low_code_platform`)

Commit what already runs on `79.174.90.4`:

1. `deploy/` — install, verify, compose, systemd, Caddy
2. `kb/`, `docs/PRODUCTION_*.md`, `docs/ORG_PROFILE.md`, `docs/REPO_STRATEGY.md`, `.dockerignore`
3. `.gitignore` — `.env`, `deploy/.env.platform`, `**/*.apk`, `node_modules/`
4. Makefile `server-journey` targets

```powershell
cd E:\Artem\maniforge_low_code_platform
git add deploy/ kb/ docs/ Makefile .dockerignore .cursor/
git status   # .env must NOT be staged
git commit -m "Platform production box: deploy scripts, kb, install/verify"
git push origin main
```

### Step 2 — Push to Maniforge/Maniforge (no force-push)

```powershell
git remote add maniforge-platform https://github.com/Maniforge/Maniforge.git
git fetch maniforge-platform
git push maniforge-platform main:platform-core
```

GitHub UI: Settings → Default branch → **`platform-core`**. Tag **`v0.1.0-box`**.

Optional later (COO approve only): force-push `platform-core` → `main`.

### Step 3 — Slim vs full tree

**Fast «быстрый свет»:** push full working tree first (Step 1–2), slim extract later.

| Include (minimum sellable box) | Exclude (lab / marketing) |
|--------------------------------|---------------------------|
| `cmd/` core services + migrate/preflight | `site-maniforge-ru/` |
| `internal/`, `migrations/pg/`, `deploy/` | `web/` (if WMS draft) |
| `docs/PRODUCTION_BOX.md`, `kb/`, `Makefile` | `examples/`, supply-chain `cmd/` |
| `frontend/apps/admin` (optional demo) | APK assets |

**Server cutover** (after `platform-core` exists on GitHub):

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

Archive `Maniforge/maniforge_low_code_platform` with README «Moved to Maniforge/Maniforge».

---

## Cutover checklist

- [x] Commit + push deploy/kb/docs in lab repo (`8ded974`)
- [x] `docs/ORG_PROFILE.md` + `docs/V0.1_BOX_MANIFEST.md` in tree
- [x] Push `platform-core` → **Maniforge/Maniforge**
- [x] GitHub: default branch = `platform-core`
- [x] GitHub: visibility = **public**
- [x] Tag **`v0.1.0-box`**
- [ ] Archive `maniforge_low_code_platform`
- [ ] Server: `git clone --branch platform-core` instead of tarball
- [ ] `verify-production.sh` → OK after server git cutover
