<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Products\Support\Ean13;
use App\Maniforge\Wms\Repository\MarkingCodeRepository;
use App\Maniforge\Wms\Repository\PackContentRepository;
use App\Maniforge\Wms\Repository\PackUnitRepository;
use App\Maniforge\Wms\Support\KizParser;
use App\Maniforge\Wms\Support\QrSsccGenerator;

final class WmsScanService
{
    public function __construct(
        private readonly PackUnitRepository $packs = new PackUnitRepository(),
        private readonly MarkingCodeRepository $markings = new MarkingCodeRepository(),
        private readonly PackContentRepository $contents = new PackContentRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /**
     * @return array{ok: bool, status: int, kind?: string, pack?: array, marking?: array, contents?: list<array>, error?: string}
     */
    public function resolve(array $session, string $rawCode): array
    {
        $tenantId = (string) $session['tenant_id'];
        $code = trim($rawCode);
        if ($code === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'code обязателен'];
        }

        $decoded = json_decode($code, true);
        if (is_array($decoded) && ($decoded['kind'] ?? '') === 'maniforge_wms_pack') {
            $packId = (int) ($decoded['pack_id'] ?? 0);
            if ($packId > 0) {
                $pack = $this->packs->findVisibleById($session, $packId);
                if ($pack !== null) {
                    return $this->packResult($pack);
                }
            }
        }

        $lookup = QrSsccGenerator::qrLookup($code);
        $byLookup = $this->packs->findByQrLookup($tenantId, $lookup);
        if ($byLookup !== null) {
            $visible = $this->packs->findVisibleById($session, (int) $byLookup['id']);
            if ($visible !== null) {
                return $this->packResult($visible);
            }
        }

        if (preg_match('/^\d{18}$/', $code) === 1) {
            $bySscc = $this->packs->findBySscc($tenantId, $code);
            if ($bySscc !== null) {
                $visible = $this->packs->findVisibleById($session, (int) $bySscc['id']);
                if ($visible !== null) {
                    return $this->packResult($visible);
                }
            }
        }

        $packByCode = $this->findPackByHumanCode($session, strtolower($code));
        if ($packByCode !== null) {
            return $this->packResult($packByCode);
        }

        if (Ean13::looksLikeBarcode($code)) {
            $ean = Ean13::normalize($code);
            if (($ean['ok'] ?? false) === true) {
                $ean13 = (string) $ean['ean13'];
                $product = $this->products->findVisibleByEan13($session, $ean13);
                if ($product !== null) {
                    return [
                        'ok' => true,
                        'status' => 200,
                        'kind' => 'product',
                        'barcode_type' => 'ean13',
                        'barcode' => $ean13,
                        'product' => $product,
                    ];
                }

                $byGtin = $this->markings->findByGtin($tenantId, $ean13);
                if ($byGtin !== null) {
                    return [
                        'ok' => true,
                        'status' => 200,
                        'kind' => 'marking',
                        'marking' => $byGtin,
                        'matched_by' => 'gtin_ean13',
                    ];
                }
            }
        }

        $marking = $this->markings->findByCode($tenantId, $code);
        if ($marking !== null) {
            return [
                'ok' => true,
                'status' => 200,
                'kind' => 'marking',
                'marking' => $marking,
            ];
        }

        $parsed = KizParser::parse($code);
        if (($parsed['ok'] ?? false) === true) {
            $marking = $this->markings->findByCode($tenantId, $code);
            if ($marking !== null) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'kind' => 'marking',
                    'marking' => $marking,
                    'parsed' => $parsed,
                ];
            }
        }

        return ['ok' => false, 'status' => 404, 'error' => 'Код не найден'];
    }

    /**
     * @param array<string, mixed> $pack
     * @return array{ok: bool, status: int, kind: string, pack: array, contents: list<array>}
     */
    private function packResult(array $pack): array
    {
        $id = (int) ($pack['id'] ?? 0);

        return [
            'ok' => true,
            'status' => 200,
            'kind' => 'pack',
            'pack' => $pack,
            'contents' => $this->contents->listByParent($id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPackByHumanCode(array $session, string $code): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $stmt = \App\Database\Connection::get()->prepare(
            'SELECT id FROM maniforge_wms_pack_units WHERE tenant_id = :tenant_id AND code = :code LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':code' => $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return $this->packs->findVisibleById($session, (int) $id);
    }
}
