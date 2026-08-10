-- Не более одной active-лицензии на tenant (страховка от гонки assign).
-- Сначала оставляем одну active на tenant (последняя по id).
UPDATE maniforge_tl_tenant_licenses l
SET status = 'revoked', updated_at = NOW()
WHERE l.status = 'active'
  AND l.id NOT IN (
      SELECT MAX(id)
      FROM maniforge_tl_tenant_licenses
      WHERE status = 'active'
      GROUP BY tenant_code
  );

CREATE UNIQUE INDEX IF NOT EXISTS uk_tl_one_active_license_per_tenant
    ON maniforge_tl_tenant_licenses (tenant_code)
    WHERE status = 'active';
