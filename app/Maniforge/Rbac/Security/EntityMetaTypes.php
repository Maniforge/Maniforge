<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

/** Коды i_index / o_index и type для maniforge_entity_meta. */
final class EntityMetaTypes
{
    public const I_USER = 1;
    public const I_TENANT = 2;
    public const I_PROJECT = 3;
    public const I_STOCK = 4;
    public const I_PRODUCT = 5;

    public const O_PHONE = 1;
    public const O_WILDBERRIES = 2;

    public const TYPE_PHONE = 'phone';
    public const TYPE_WILDBERRIES = 'wildberries';

    /** Глобальный scope (вне tenant/subtenant). */
    public const SCOPE_GLOBAL_TENANT = '';
    public const SCOPE_GLOBAL_SUBTENANT = '';
}
