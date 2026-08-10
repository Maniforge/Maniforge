<?php
declare(strict_types=1);

/** @var string $phoneFieldIdPrefix unique prefix for element ids (e.g. reg, profile) */
/** @var string $phoneLabel label text */
/** @var bool $phoneRequired */
/** @var string $phoneDefaultPrefix default country code including + */

$phoneFieldIdPrefix = $phoneFieldIdPrefix ?? 'phone';
$phoneLabel = $phoneLabel ?? 'Телефон';
$phoneRequired = $phoneRequired ?? true;
$phoneDefaultPrefix = $phoneDefaultPrefix ?? '+7';

$phonePrefixes = [
    '+7' => '+7 (RU/KZ)',
    '+1' => '+1 (US/CA)',
    '+375' => '+375 (BY)',
    '+380' => '+380 (UA)',
    '+44' => '+44 (UK)',
    '+49' => '+49 (DE)',
    '+33' => '+33 (FR)',
    '+86' => '+86 (CN)',
    '+91' => '+91 (IN)',
    '+90' => '+90 (TR)',
    '+971' => '+971 (AE)',
];

$prefixId = $phoneFieldIdPrefix . 'PhonePrefix';
$numberId = $phoneFieldIdPrefix . 'PhoneNumber';
?>
<div class="mb-3 app-phone-field">
    <label class="form-label" for="<?= htmlspecialchars($numberId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($phoneLabel, ENT_QUOTES, 'UTF-8') ?></label>
    <div class="app-phone-input input-group">
        <select
            class="form-select app-field app-phone-prefix"
            id="<?= htmlspecialchars($prefixId, ENT_QUOTES, 'UTF-8') ?>"
            aria-label="Код страны"
            <?= $phoneRequired ? ' required' : '' ?>
        >
            <?php foreach ($phonePrefixes as $code => $label): ?>
            <option
                value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                <?= $code === $phoneDefaultPrefix ? ' selected' : '' ?>
            ><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <input
            class="form-control app-field app-phone-number"
            id="<?= htmlspecialchars($numberId, ENT_QUOTES, 'UTF-8') ?>"
            type="tel"
            inputmode="numeric"
            autocomplete="tel-national"
            placeholder="9001234567"
            <?= $phoneRequired ? 'required' : '' ?>
        >
    </div>
    <div class="form-text app-muted small">Укажите номер без кода страны — в системе сохранится полный международный номер.</div>
</div>
<script>
window.ManiforgePhoneField = window.ManiforgePhoneField || {};
(function () {
    const PREFIXES = <?= json_encode(array_keys($phonePrefixes), JSON_UNESCAPED_UNICODE) ?>;

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function buildFullPhone(prefix, localNumber) {
        const prefixDigits = digitsOnly(prefix);
        let localDigits = digitsOnly(localNumber);
        if (localDigits.startsWith('0')) {
            localDigits = localDigits.replace(/^0+/, '');
        }
        if (prefixDigits === '' || localDigits === '') {
            return '';
        }
        return '+' + prefixDigits + localDigits;
    }

    function splitStoredPhone(stored) {
        const raw = String(stored || '').trim();
        if (raw === '') {
            return { prefix: <?= json_encode($phoneDefaultPrefix, JSON_UNESCAPED_UNICODE) ?>, local: '' };
        }
        const normalized = raw.startsWith('+') ? '+' + digitsOnly(raw) : digitsOnly(raw);
        const digits = normalized.startsWith('+') ? normalized.slice(1) : normalized;
        const sorted = PREFIXES.slice().sort((a, b) => digitsOnly(b).length - digitsOnly(a).length);
        for (const prefix of sorted) {
            const code = digitsOnly(prefix);
            if (digits.startsWith(code)) {
                return { prefix, local: digits.slice(code.length) };
            }
        }
        return { prefix: <?= json_encode($phoneDefaultPrefix, JSON_UNESCAPED_UNICODE) ?>, local: digits };
    }

    function bind(prefixId, numberId) {
        return {
            getFullPhone() {
                return buildFullPhone(
                    document.getElementById(prefixId)?.value || '',
                    document.getElementById(numberId)?.value || ''
                );
            },
            setFromStored(stored) {
                const parts = splitStoredPhone(stored);
                const prefixEl = document.getElementById(prefixId);
                const numberEl = document.getElementById(numberId);
                if (prefixEl) prefixEl.value = parts.prefix;
                if (numberEl) numberEl.value = parts.local;
            },
        };
    }

    window.ManiforgePhoneField.buildFullPhone = buildFullPhone;
    window.ManiforgePhoneField.splitStoredPhone = splitStoredPhone;
    window.ManiforgePhoneField.bind = bind;
})();
</script>
