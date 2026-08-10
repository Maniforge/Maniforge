# Maniforge Products

Номенклатура (SKU) в scope **tenant + project** + delegation share.

- HTTP: `http://127.0.0.1:8092/products/health`
- API: `GET/POST /products/api/v1/products`, …
- Код: `app/Maniforge/Products/`
- Миграции: `maniforge/rbac/migrations/037_products_core.sql`, `038_products_permissions.sql`
- Документация: [docs/MANIFORGE_PRODUCTS.md](../../docs/MANIFORGE_PRODUCTS.md)

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/products_journey_check.php
```
