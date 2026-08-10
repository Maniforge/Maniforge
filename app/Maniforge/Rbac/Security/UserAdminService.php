<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\UserRepository;

final class UserAdminService
{
    private const ALLOWED_USER_STATUSES = ['active', 'locked', 'disabled'];

    public function __construct(
        private readonly UserRepository $users = new UserRepository()
    ) {
    }

    public function isAllowedStatus(string $status): bool
    {
        return in_array($status, self::ALLOWED_USER_STATUSES, true);
    }

    public function simulateStatusBatchSummary(string $tenantId, string $subtenantId, array $items): array
    {
        $changed = 0;
        $skipped = 0;
        $notFound = 0;
        $byStatus = ['active' => 0, 'locked' => 0, 'disabled' => 0];

        foreach ($items as $item) {
            $userId = (int) ($item['user_id'] ?? 0);
            $status = trim((string) ($item['status'] ?? ''));
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            $currentStatus = $this->users->findStatusInScope($userId, $tenantId, $subtenantId);
            if ($currentStatus === null) {
                $notFound++;
                continue;
            }

            if ($currentStatus === $status) {
                $skipped++;
                continue;
            }

            $changed++;
        }

        return [
            'changed' => $changed,
            'skipped' => $skipped,
            'not_found' => $notFound,
            'total' => count($items),
            'by_status' => $byStatus,
        ];
    }
}
