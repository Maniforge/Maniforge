DELETE ur1
FROM maniforge_user_roles ur1
INNER JOIN maniforge_user_roles ur2
    ON ur1.user_id = ur2.user_id
    AND ur1.role_id = ur2.role_id
    AND ur1.tenant_id = ur2.tenant_id
    AND ur1.subtenant_id = ur2.subtenant_id
    AND COALESCE(CAST(ur1.expires_at AS CHAR), 'ACTIVE') = COALESCE(CAST(ur2.expires_at AS CHAR), 'ACTIVE')
    AND ur1.id > ur2.id;

ALTER TABLE maniforge_user_roles
    ADD COLUMN assignment_slot VARCHAR(19)
        GENERATED ALWAYS AS (COALESCE(CAST(expires_at AS CHAR), 'ACTIVE')) STORED,
    ADD UNIQUE KEY uk_user_role_assignment_slot (
        user_id,
        role_id,
        tenant_id,
        subtenant_id,
        assignment_slot
    );

DELETE rp
FROM maniforge_role_permissions rp
INNER JOIN maniforge_roles r ON r.id = rp.role_id
INNER JOIN maniforge_permissions p ON p.id = rp.permission_id
WHERE r.code = 'security_auditor'
  AND p.code IN ('admin.users.status.bulk', 'admin.sessions.bulk');
