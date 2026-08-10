-- Штрихкоды номенклатуры: EAN-13 (пока один тип на товар)

ALTER TABLE maniforge_products
    ADD COLUMN barcode_ean13 CHAR(13) NULL AFTER code,
    ADD UNIQUE KEY uk_products_ean13 (tenant_id, barcode_ean13);
