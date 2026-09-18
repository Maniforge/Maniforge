<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

final class PlatformProcessorConfig
{
    /**
     * @return array<string, mixed>|null null when platform processor is not configured
     */
    public function toNoticePayload(): ?array
    {
        $name = trim((string) ($_ENV['RBAC_PLATFORM_PROCESSOR_NAME'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'inn' => $this->optional('RBAC_PLATFORM_PROCESSOR_INN'),
            'address' => $this->optional('RBAC_PLATFORM_PROCESSOR_ADDRESS'),
            'dpo_email' => $this->optional('RBAC_PLATFORM_PROCESSOR_DPO_EMAIL'),
            'dpa_url' => $this->optional('RBAC_PLATFORM_DPA_URL'),
            'role' => 'processor',
            'legal_basis' => 'processing_agreement',
        ];
    }

    private function optional(string $key): ?string
    {
        $value = trim((string) ($_ENV[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
