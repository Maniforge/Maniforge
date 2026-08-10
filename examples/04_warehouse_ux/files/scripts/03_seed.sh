#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token
post() {
  curl -sS -X POST "$MANIFEST_URL/api/data/$1" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "$2" | jq -c '{ok,id:(.record.id)}'
}

echo "== locations =="
post wh_location '{"code":"A-01-01","name":"Стеллаж A ряд 1","zone":"A","aisle":"01","rack":"01","bin":"01","status":"active"}'
post wh_location '{"code":"A-01-02","name":"Стеллаж A ряд 2","zone":"A","aisle":"01","rack":"02","bin":"01","status":"active"}'
post wh_location '{"code":"RCV-01","name":"Зона приёмки","zone":"RCV","aisle":"00","rack":"00","bin":"01","status":"active"}'
post wh_location '{"code":"SHIP-01","name":"Зона отгрузки","zone":"SHIP","aisle":"00","rack":"00","bin":"01","status":"active"}'

echo "== sku =="
post wh_sku '{"sku":"SKU-MILK-1L","name":"Молоко 1л","uom":"шт","barcode":"4601234567890","track_lot":true,"status":"active"}'
post wh_sku '{"sku":"SKU-BOX-M","name":"Короб M","uom":"шт","barcode":"4601234567891","track_lot":false,"status":"active"}'
post wh_sku '{"sku":"SKU-TAPE","name":"Скотч 48мм","uom":"шт","barcode":"4601234567892","track_lot":false,"status":"active"}'

echo "== balances =="
post wh_balance '{"sku":"SKU-MILK-1L","location_code":"A-01-01","qty":120,"lot_code":"LOT-2408","expires_at":"2026-09-20","status":"available"}'
post wh_balance '{"sku":"SKU-BOX-M","location_code":"A-01-02","qty":80,"lot_code":"","expires_at":"","status":"available"}'
post wh_balance '{"sku":"SKU-TAPE","location_code":"A-01-02","qty":40,"lot_code":"","expires_at":"","status":"available"}'

echo "== docs =="
post wh_receipt '{"doc_no":"RCV-1001","supplier":"ООО МолПром","sku":"SKU-MILK-1L","qty":50,"location_code":"RCV-01","lot_code":"LOT-2410","status":"draft","received_at":"2026-08-11"}'
post wh_putaway '{"task_no":"PUT-2001","sku":"SKU-MILK-1L","qty":50,"from_location":"RCV-01","to_location":"A-01-01","status":"open"}'
post wh_transfer '{"doc_no":"TR-3001","sku":"SKU-BOX-M","qty":10,"from_location":"A-01-02","to_location":"SHIP-01","status":"draft"}'
post wh_shipment '{"doc_no":"SHP-4001","order_ref":"SO-7781","sku":"SKU-MILK-1L","qty":24,"location_code":"A-01-01","status":"picking","customer":"Магазин Север"}'
post wh_stocktake '{"doc_no":"INV-5001","sku":"SKU-TAPE","location_code":"A-01-02","book_qty":40,"count_qty":38,"status":"counted"}'
post wh_lot '{"lot_code":"LOT-2408","sku":"SKU-MILK-1L","qty":120,"produced_at":"2026-08-01","expires_at":"2026-09-20","status":"active"}'
post wh_lot '{"lot_code":"LOT-2410","sku":"SKU-MILK-1L","qty":50,"produced_at":"2026-08-10","expires_at":"2026-09-30","status":"quarantine"}'
post wh_reserve '{"reserve_no":"RSV-6001","order_ref":"SO-7781","sku":"SKU-MILK-1L","qty":24,"location_code":"A-01-01","status":"held","expires_at":"2026-08-12"}'

echo seed done
