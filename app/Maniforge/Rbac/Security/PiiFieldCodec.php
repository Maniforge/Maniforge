<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

final class PiiFieldCodec
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function isEnabled(): bool
    {
        return filter_var($_ENV['RBAC_PII_ENCRYPTION_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public function isRequiredInProduction(): bool
    {
        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local')));

        return in_array($env, ['prod', 'production'], true);
    }

    public function hasValidKey(): bool
    {
        return $this->decodeKey() !== null;
    }

    /**
     * @return array{email: ?string, phone: string, email_enc: ?string, phone_enc: ?string, pii_enc_version: int}
     */
    public function packForStorage(?string $email, string $phone, string $tenantId, string $subtenantId): array
    {
        if (!$this->isEnabled() || !$this->hasValidKey()) {
            return [
                'email' => $email,
                'phone' => $phone,
                'email_enc' => null,
                'phone_enc' => null,
                'pii_enc_version' => 0,
            ];
        }

        $emailStored = $email === null || $email === ''
            ? null
            : $this->blindIndex('email', $email, $tenantId, $subtenantId);

        return [
            'email' => $emailStored,
            'phone' => $this->blindIndex('phone', $phone, $tenantId, $subtenantId),
            'email_enc' => $email === null || $email === '' ? null : $this->encrypt($email),
            'phone_enc' => $this->encrypt($phone),
            'pii_enc_version' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function hydrateRow(array $row): array
    {
        $version = (int) ($row['pii_enc_version'] ?? 0);
        if ($version < 1) {
            unset($row['email_enc'], $row['phone_enc'], $row['pii_enc_version']);

            return $row;
        }

        if ($this->hasValidKey()) {
            if (!empty($row['email_enc'])) {
                $row['email'] = $this->decrypt((string) $row['email_enc']);
            } else {
                $row['email'] = null;
            }
            if (!empty($row['phone_enc'])) {
                $row['phone'] = $this->decrypt((string) $row['phone_enc']);
            }
        }

        unset($row['email_enc'], $row['phone_enc']);

        return $row;
    }

    public function phoneLookupValue(string $phone, string $tenantId, string $subtenantId): string
    {
        if ($this->isEnabled() && $this->hasValidKey()) {
            return $this->blindIndex('phone', $phone, $tenantId, $subtenantId);
        }

        return $phone;
    }

    public function phoneLookupValueGlobal(string $phone): string
    {
        if ($this->isEnabled() && $this->hasValidKey()) {
            return $this->blindIndex('phone', $phone, '*', '*');
        }

        return $phone;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->decodeKey();
        if ($key === null) {
            throw new \RuntimeException('RBAC_PII_ENCRYPTION_KEY is not configured');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('PII encryption failed');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'c' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $packed): ?string
    {
        $key = $this->decodeKey();
        if ($key === null) {
            return null;
        }

        try {
            $json = json_decode(base64_decode($packed, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($json) || (int) ($json['v'] ?? 0) !== 1) {
            return null;
        }

        $iv = base64_decode((string) ($json['iv'] ?? ''), true);
        $tag = base64_decode((string) ($json['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($json['c'] ?? ''), true);
        if ($iv === false || $tag === false || $ciphertext === false) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            return null;
        }

        return $plain;
    }

    public function blindIndex(string $field, string $value, string $tenantId, string $subtenantId): string
    {
        $key = $this->blindKey();
        $normalized = $field === 'email'
            ? strtolower(trim($value))
            : $this->normalizePhone($value);

        $message = $field . '|' . $tenantId . '|' . $subtenantId . '|' . $normalized;

        return hash_hmac('sha256', $message, $key);
    }

    private function blindKey(): string
    {
        $dedicated = trim((string) ($_ENV['RBAC_PII_BLIND_INDEX_KEY'] ?? ''));
        if ($dedicated !== '') {
            $decoded = base64_decode($dedicated, true);
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }

            return hash('sha256', $dedicated, true);
        }

        $key = $this->decodeKey();
        if ($key === null) {
            throw new \RuntimeException('RBAC_PII_ENCRYPTION_KEY is not configured');
        }

        return hash('sha256', $key . '|blind', true);
    }

    private function decodeKey(): ?string
    {
        $raw = trim((string) ($_ENV['RBAC_PII_ENCRYPTION_KEY'] ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = base64_decode($raw, true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            return null;
        }

        return $decoded;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        return $digits;
    }
}
