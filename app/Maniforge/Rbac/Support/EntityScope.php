<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Support;

/** Уровни видимости сущностей в Maniforge (tenant → subtenant → project). */
final class EntityScope
{
    public const DEFAULT_PROJECT_CODE = 'main';
    public const DEFAULT_PROJECT_NAME = 'Main';

    /** maniforge_projects.subtenant_id = '' */
    public const PROJECT_SCOPE_TENANT = 'tenant';

    /** maniforge_projects.subtenant_id = код subtenant */
    public const PROJECT_SCOPE_SUBTENANT = 'subtenant';

    /** Только текущий project_id сессии. */
    public const VISIBILITY_PROJECT = 'project';

    /** Все проекты subtenant (project_id IS NULL). */
    public const VISIBILITY_SUBTENANT = 'subtenant';

    /** Tenant-wide: subtenant_id пустой; shared_subtenant_ids_json — фильтр или null = все subtenant. */
    public const VISIBILITY_TENANT = 'tenant';

    /** @return list<string> */
    public static function visibilityValues(): array
    {
        return [
            self::VISIBILITY_PROJECT,
            self::VISIBILITY_SUBTENANT,
            self::VISIBILITY_TENANT,
        ];
    }
}
