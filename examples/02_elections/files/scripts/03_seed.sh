#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; require_token
post() { curl -sS -X POST "$MANIFEST_URL/api/data/$1" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$2" | jq -c '{ok,id:(.record.id)}'; }
echo "== election =="
post election_event '{"code":"mun-2026","title":"Муниципальные выборы 2026","level":"municipal","vote_date":"2026-09-13","status":"preparation","region":"Примерский край"}'
echo "== stations =="
post polling_station '{"code":"UIK-0101","election_code":"mun-2026","title":"УИК №101","address":"ул. Центральная, 1","capacity":1200,"status":"active"}'
post polling_station '{"code":"UIK-0102","election_code":"mun-2026","title":"УИК №102","address":"пр. Мира, 15","capacity":900,"status":"active"}'
post polling_station '{"code":"UIK-0103","election_code":"mun-2026","title":"УИК №103","address":"ул. Школьная, 8","capacity":750,"status":"active"}'
echo "== candidacies =="
post candidacy '{"election_code":"mun-2026","full_name":"Алексеев П.С.","party":"Самовыдвижение","district":"округ-1","status":"registered","reg_number":"K-001"}'
post candidacy '{"election_code":"mun-2026","full_name":"Морозова Е.В.","party":"Партия развития","district":"округ-1","status":"registered","reg_number":"K-002"}'
post candidacy '{"election_code":"mun-2026","full_name":"Гусев И.Н.","party":"Городской союз","district":"округ-1","status":"registered","reg_number":"K-003"}'
echo seed done
