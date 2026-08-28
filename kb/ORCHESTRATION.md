# Orchestration — Maniforge platform

Canonical routing and artifacts for `/maniforge`. Commands and agents reference this file.

## Routing (`ROUTE:`)

At the start of `/maniforge`, state:

```text
ROUTE: tiny | single-area | eng-full | product | product→eng
```

| ROUTE | When | Behavior |
|-------|------|----------|
| `tiny` | One file / obvious fix | Skip manager+tech-lead. One specialist. Respect ownership. |
| `single-area` | Only Go **or** only UI **or** only deploy | Optional short Brief; light plan or direct specialist. |
| `eng-full` | Cross-area (e.g. manifest API + admin UI + compose) | manager → tech-lead → parallel specialists → qa |
| `product` | Positioning, UX, copy | product-manager → designer / marketer. No `cmd/` code. |
| `product→eng` | Product then build | Brief → eng route |

**Platform default:** prefer `single-area` per Go service; use `eng-full` when touching `deploy/` + `cmd/` + `frontend/` together.

## Collision rule

Two agents never edit the same path in one iteration. WorkPlan must list:

```text
Ownership locks:
  <path-or-glob> → <role>
```

Specialists refuse out-of-scope edits → `status: blocked`.

## Paths

Read ownership from `kb/projects.md`. Never assume foreign layouts (`apps/web`, wms paths).

## Production phases (do not skip)

| Phase | Goal | Done when |
|-------|------|-----------|
| **A** | One-command platform core | nzgapp Caddy `:18090` + Go loopback `:8093–8097`, journeys green |
| **A→sell** | **v0.1.0-box** — reproducible artifact | `deploy/` in git → `Maniforge/Maniforge` branch `platform-core` → tag → server `git clone` + install + verify (see `docs/V0.1_BOX_MANIFEST.md`) |
| **B** | Supply chain Go | warehouses → products → inventory → wms |
| **C** | Hardening | TLS/domain, RBAC 50/50, preflight, backup drill, CI, scheduler |
| **D** | Module packages | `.mfpack`, app store runtime |

**Staging (2026-08-28):** Phase **A operational** on `79.174.90.4:18090`. **A→sell GitHub artifact:** **done** — [`v0.1.0-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box), public. **Next gate:** server `git clone` cutover + Phase **C** hardening.

**Demo today (not blocked):** IP `:18090`, TLS, git on server, RBAC 48/50 internal steps, scheduler, public repo.

**Paused until A–C:** avtosbor, devent cutover, `wms.svitex.online/platform/` draft.

See `docs/PRODUCTION_PLAN.md`.

## Crew logging

Optional, default **off**. `CREW_LOGGING=1` or crew API URL → `scripts/crew-logging.md`. Never block delivery.

## Artifact schemas

### Brief (manager)

```text
Goal:
Non-goals:
Priority: P0|P1|P2
Constraints:
Definition of Done:
Open questions:
```

### WorkPlan (tech-lead)

```text
Assumptions:
Architecture notes:
Tasks:
  - id:
    role:
    summary:
    owns:
    blocked_by:
    acceptance:
Ownership locks:
Parallel groups:
Verification:
```

### TaskResult (specialists)

```text
status: done|blocked|partial
files:
how to verify:
blockers:
notes:
```

### StatusReport (tech-lead)

```text
Conflicts:
What shipped:
How to verify:
Residual risks:
DoD met: yes|no
```
