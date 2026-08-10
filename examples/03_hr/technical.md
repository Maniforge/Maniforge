# HR Desk — technical

Self-hosted Maniforge: RBAC + Manifest Engine.

## Сущности

- [`files/manifest.hr_vacancy.json`](files/manifest.hr_vacancy.json)
- [`files/manifest.hr_leave_request.json`](files/manifest.hr_leave_request.json)

## Запуск

```bash
make run-rbac && make run-manifest
./bin/maniforge-agency-demo-seed

cd examples/03_hr/files
cp env.example .env
bash scripts/00_prereq.sh
bash scripts/01_login.sh
bash scripts/02_create_manifests.sh
bash scripts/03_seed.sh
bash scripts/04_list.sh
```

Демо-учётка: phone `+79000000003`, password `DemoAdmin!12345`, tenant `agency-demo`.

## UI

```bash
cd examples/03_hr/files/ui
php -S 127.0.0.1:8764 router.php
# → http://127.0.0.1:8764/
```

Proxy `/proxy/rbac` и `/proxy/manifest`. Вход: `+79000000003` / `DemoAdmin!12345` / `agency-demo`.

## Seed

- 2 вакансии (кладовщик, менеджер B2B)
- 2 заявки на отпуск (pending / approved)
