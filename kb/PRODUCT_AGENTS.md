# Product agents (Maniforge)

Eng pipeline is unchanged — see `kb/ORCHESTRATION.md` and README.

## Layout

| Where | What |
|-------|------|
| `templates/.cursor/agents/*.md` | Role templates |
| Target `.cursor/agents/` | After SETUP copy |

Shipped product stubs: `product-manager.md`, `designer.md`, `marketer.md`.  
Hire others from `_HIRE_TEMPLATE.md` (e.g. researcher).

## Hire process

1. Copy `_HIRE_TEMPLATE.md` → `.cursor/agents/<role>.md`
2. Fill frontmatter + Job/Refuse
3. Add a row to the matrix below
4. Re-open Cursor / refresh agents if needed

**Hire ≠ runtime HR.** No marketplace UI.

## Eng vs product matrix

| Request | Path | Lead |
|---------|------|------|
| Code / API / UI / infra | Eng (`ROUTE: tiny\|single-area\|eng-full`) | manager / tech-lead |
| Design, marketing, research, positioning | Product (`ROUTE: product`) | product-manager |
| Product then code | `ROUTE: product→eng` | PM then eng handoff |

## PM routing

1. Read request → product vs eng handoff.
2. Spawn only needed designer/marketer/hired roles.
3. Deliverables are briefs/notes — not application code.
4. Handoff eng Brief when implementation starts.
