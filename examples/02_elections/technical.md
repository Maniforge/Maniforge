# Elections Desk — technical

Self-hosted Maniforge: RBAC + Manifest Engine.

## Сущности

- [`files/manifest.election_event.json`](files/manifest.election_event.json)
- [`files/manifest.polling_station.json`](files/manifest.polling_station.json)
- [`files/manifest.candidacy.json`](files/manifest.candidacy.json)

## Запуск

```bash
make run-rbac && make run-manifest
./bin/maniforge-agency-demo-seed

cd examples/02_elections/files
cp env.example .env
bash scripts/00_prereq.sh
bash scripts/01_login.sh
bash scripts/02_create_manifests.sh
bash scripts/03_seed.sh
bash scripts/04_list.sh
```

Демо-учётка: phone `+79000000003`, password `DemoAdmin!12345`, tenant `agency-demo`.

## Seed (кратко)

- Кампания: «Муниципальные выборы 2026»
- 3 участка УИК-0101…0103
- 3 кандидатуры в округе №1
