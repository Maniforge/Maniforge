# Warehouse UX Suite — technical

10 UI/UX экранов складского учёта + 10 манифестов.

## Манифесты

`files/manifests/manifest.wh_*.json`:

`wh_location` · `wh_sku` · `wh_balance` · `wh_receipt` · `wh_putaway` · `wh_transfer` · `wh_shipment` · `wh_stocktake` · `wh_lot` · `wh_reserve`

## Запуск

```bash
make run-rbac && make run-manifest
./bin/maniforge-agency-demo-seed

cd examples/04_warehouse_ux/files
cp env.example .env
bash scripts/00_prereq.sh
bash scripts/01_login.sh
bash scripts/02_create_manifests.sh
bash scripts/03_seed.sh

cd ui
php -S 127.0.0.1:8765 router.php
# → http://127.0.0.1:8765/
```

Вход: `+79000000003` / `DemoAdmin!12345` / tenant `agency-demo`.

## UI

Одностраничное приложение: боковая навигация по 10 сценариям, proxy `/proxy/rbac` и `/proxy/manifest`.
