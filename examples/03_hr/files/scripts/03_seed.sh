#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; require_token
post() { curl -sS -X POST "$MANIFEST_URL/api/data/$1" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$2" | jq -c '{ok,id:(.record.id)}'; }
echo "== vacancies =="
post hr_vacancy '{"code":"VAC-WH-01","title":"Кладовщик","unit_code":"ops-wh","employment_type":"full_time","status":"open","opened_at":"2026-08-01","note":"Сменный график"}'
post hr_vacancy '{"code":"VAC-SALES-01","title":"Менеджер B2B","unit_code":"com-sales","employment_type":"full_time","status":"open","opened_at":"2026-07-15","note":"Опыт с дистрибьюторами"}'
echo "== leave =="
post hr_leave_request '{"employee_name":"Никитин Р.А.","unit_code":"ops-wh","leave_type":"vacation","date_from":"2026-09-01","date_until":"2026-09-14","status":"pending","approver":"Павлов Е.Н.","note":"Ежегодный отпуск"}'
post hr_leave_request '{"employee_name":"Орлова М.С.","unit_code":"com-sales","leave_type":"vacation","date_from":"2026-08-20","date_until":"2026-08-27","status":"approved","approver":"Козлов Д.И.","note":""}'
echo seed done
