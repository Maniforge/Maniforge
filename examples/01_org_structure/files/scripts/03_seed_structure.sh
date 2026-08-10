#!/usr/bin/env bash
# Seed a small business org tree + employees (idempotent-ish: always inserts new records).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token

post_unit() {
  local payload="$1"
  curl -sS -X POST "$MANIFEST_URL/api/data/org_unit" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "$payload" | jq -c '{ok,id:(.record.id),code:(.record.data.code)}'
}

post_emp() {
  local payload="$1"
  curl -sS -X POST "$MANIFEST_URL/api/data/org_employee" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "$payload" | jq -c '{ok,id:(.record.id),name:(.record.data.full_name)}'
}

echo "== units =="
post_unit '{"code":"co","name":"ООО «Север Торг»","unit_type":"company","parent_code":"","status":"active","head_title":"Генеральный директор","note":"Холдинг / юрлицо"}'
post_unit '{"code":"commercial","name":"Коммерция","unit_type":"division","parent_code":"co","status":"active","head_title":"Коммерческий директор"}'
post_unit '{"code":"operations","name":"Операции","unit_type":"division","parent_code":"co","status":"active","head_title":"Операционный директор"}'
post_unit '{"code":"finance","name":"Финансы","unit_type":"division","parent_code":"co","status":"active","head_title":"Финдиректор"}'
post_unit '{"code":"ops-wh","name":"Склад","unit_type":"department","parent_code":"operations","status":"active","head_title":"Начальник склада"}'
post_unit '{"code":"ops-log","name":"Логистика","unit_type":"department","parent_code":"operations","status":"active","head_title":"Руководитель логистики"}'
post_unit '{"code":"com-sales","name":"Продажи B2B","unit_type":"department","parent_code":"commercial","status":"active","head_title":"РОП"}'

echo "== employees =="
post_emp '{"full_name":"Смирнова А.В.","unit_code":"co","position":"Генеральный директор","email":"ceo@sever-torg.example","phone":"+79001110001","status":"active","hired_at":"2019-03-01"}'
post_emp '{"full_name":"Козлов Д.И.","unit_code":"commercial","position":"Коммерческий директор","email":"cco@sever-torg.example","phone":"+79001110002","status":"active","hired_at":"2020-06-15"}'
post_emp '{"full_name":"Орлова М.С.","unit_code":"com-sales","position":"Менеджер B2B","email":"sales1@sever-torg.example","phone":"+79001110003","status":"active","hired_at":"2022-01-10"}'
post_emp '{"full_name":"Павлов Е.Н.","unit_code":"operations","position":"Операционный директор","email":"coo@sever-torg.example","phone":"+79001110004","status":"active","hired_at":"2018-11-20"}'
post_emp '{"full_name":"Никитин Р.А.","unit_code":"ops-wh","position":"Кладовщик","email":"wh1@sever-torg.example","phone":"+79001110005","status":"active","hired_at":"2023-04-01"}'
post_emp '{"full_name":"Белова И.К.","unit_code":"finance","position":"Главный бухгалтер","email":"cfo@sever-torg.example","phone":"+79001110006","status":"active","hired_at":"2017-09-01"}'

echo "seed done"
