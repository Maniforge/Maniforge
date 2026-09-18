# Manifest Engine — план реализации

**Текущий статус:** фазы 0–6 завершены (~90% полного видения).  
**Цель:** production-ready движок для интеграторов (API + права + audit + licensing).

Связанные документы: [MANIFORGE_MANIFEST_ENGINE.md](MANIFORGE_MANIFEST_ENGINE.md), [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md).

---

## Фазы

| Фаза | Содержание | Критерий готовности | Статус |
|------|------------|---------------------|--------|
| **0** | MVP: manifests + data CRUD + field-path + OpenAPI JSON | `make build` | ✅ |
| **1** | Licensing gate, audit log, DELETE manifest, HTTP journey | `make manifest-journey` exit 0 | ✅ |
| **2** | Field-level RBAC (`read_roles`/`write_roles`), manifest admin roles | journey + role deny cases | ✅ базово |
| **3** | Versioning записей (hook → `maniforge_ver_changes`) | journey: >=3 ver_changes | ✅ |
| **4** | JSONB filter/search, pagination meta, OpenAPI YAML export | query `?filter=` | ✅ |
| **5** | Refine UI scaffold из OpenAPI | `templates/refine-manifest/` | ✅ |
| **6** | Пресеты supply chain (product, stock) | seed manifests | ✅ |

---

## Фаза 1 — детали

### 1.1 Licensing gate
- Каждая операция data + manifest → `licensingclient.AssertAccess(tenant, project, workspace)`.
- Project code из `maniforge_projects` по `session.project_id`.

### 1.2 Audit
- Таблица `maniforge_manifest_audit_log`.
- События: `manifest.created`, `manifest.updated`, `manifest.archived`, `record.created`, `record.updated`, `record.deleted`, `record.field_updated`.

### 1.3 DELETE manifest
- `DELETE /api/v1/manifests/{code}` → `status = archived` (мягкое удаление).

### 1.4 Journey
- `cmd/manifest-journey` — register → login → create manifest → CRUD record → field PUT.
- `make manifest-journey` в Makefile.

---

## Фаза 2 — field-level RBAC

- `tenant_admin`, `subtenant_admin`, `super_admin` — полный доступ к manifests и полям без ограничений.
- Иначе: `read_roles` / `write_roles` на `FieldDef`.
- Пустой список ролей на поле = доступ всем аутентифицированным в scope.
- GET record — фильтрация полей без read-доступа (redact).
- PATCH/PUT — 403 если нет write на затронутое поле.

---

## Фаза 3–6 (кратко)

- **Versioning:** after write → snapshot в versioning service (когда портирован).
- **Filter:** `?filter={"title":"%foo%"}` на GIN jsonb_path или key equality MVP.
- **OpenAPI YAML:** `GET /manifests/{code}/openapi.yaml` `Content-Type: application/yaml`.
- **Refine:** статический генератор из OpenAPI JSON.
- **Presets:** SQL seed + docs для product/stock manifests.

---

## Порядок работы (текущий спринт)

1. ✅ Записать план (этот файл)
2. ✅ Миграция audit + licensing + audit writes + DELETE manifest
3. ✅ Field RBAC (role repo + permissions)
4. ✅ `cmd/manifest-journey` + Makefile (`make manifest-journey` exit 0)
5. ✅ Обновить MANIFORGE_MANIFEST_ENGINE.md и GO_MIGRATION.md
6. ✅ Фаза 3: versioning hook (`internal/versioning`, migration 012)
7. ✅ Фаза 4: JSONB filter/search + pagination meta + OpenAPI YAML
8. ✅ Фаза 5: Refine UI scaffold (`make manifest-refine-gen`, `/refine-manifest`)
9. ✅ Фаза 6: presets supply chain (product, stock)

---

## Не входит в ближайший спринт

- Биллинг по API-вызовам
- Rate limit
- entity_meta
- Delegation / switch-context
- PHP-паритет (нет PHP manifest engine)
