<?php
declare(strict_types=1);

namespace App\Support;

final class MarkdownDocPage
{
    /** @var array<string, string> repo-relative path => public URL */
    private const LINK_MAP = [
        'docs/152FZ_COMPLIANCE.md' => '/docs/152FZ_COMPLIANCE.md',
        'docs/MANIFORGE_PD_PROCESSOR_PLATFORM.md' => '/docs/maniforge-pd-processor-platform.md',
        'docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md' => '/docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md',
        'docs/MANIFORGE_TENANT_DELEGATION.md' => '/docs/maniforge-tenant-delegation.md',
        'docs/MANIFORGE_ENTERPRISE_HARDENING.md' => '/docs/maniforge-enterprise-hardening.md',
        'docs/MANIFORGE_NEW_USER_WORKFLOW.md' => '/docs/maniforge-new-user-workflow.md',
        'docs/MANIFORGE_SECURITY_INCIDENT_WORKFLOW.md' => '/docs/maniforge-security-incident-workflow.md',
        'docs/MANIFORGE_RBAC_CHECKPOINT_SUMMARY.md' => '/docs/maniforge-rbac-checkpoint-summary.md',
        'docs/legal/ROSKOMNADZOR_NOTIFICATION_LETTER.md' => '/docs/legal/roskomnadzor-notification.md',
        'docs/legal/README.md' => '/docs/legal/readme.md',
        'docs/legal/PRIVACY_POLICY_OUTLINE.md' => '/docs/legal/privacy-policy-outline.md',
        'docs/legal/DPO_APPOINTMENT_ORDER.md' => '/docs/legal/dpo-appointment-order.md',
        'docs/legal/PROCESSING_REGISTRY_TEMPLATE.md' => '/docs/legal/processing-registry-template.md',
        'docs/legal/DATA_PROCESSING_AGREEMENT_OUTLINE.md' => '/docs/legal/data-processing-agreement-outline.md',
        'docs/legal/SUBJECT_REQUEST_RESPONSE_TEMPLATE.md' => '/docs/legal/subject-request-response-template.md',
    ];

    public static function serve(string $absolutePath, string $pageTitle, string $requestPath): void
    {
        $root = dirname(__DIR__, 2);

        if (!is_file($absolutePath)) {
            http_response_code(404);
            require $root . '/templates/404.php';
            return;
        }

        if (isset($_GET['format']) && (string) $_GET['format'] === 'raw') {
            header('Content-Type: text/markdown; charset=utf-8');
            readfile($absolutePath);
            return;
        }

        $markdown = (string) file_get_contents($absolutePath);
        $markdown = self::normalizeLinks($markdown);
        $docHeroTitle = $pageTitle;
        if (preg_match('/^#\s+(.+?)\s*$/m', $markdown, $match)) {
            $docHeroTitle = trim((string) $match[1]);
            $markdown = (string) preg_replace('/^#\s+.+?\s*\n+/', '', $markdown, 1);
        }

        $docRawUrl = $requestPath . '?format=raw';
        $docSource = $markdown;

        require $root . '/templates/docs-markdown.php';
    }

    public static function normalizeLinks(string $markdown): string
    {
        foreach (self::LINK_MAP as $from => $to) {
            $markdown = str_replace('](' . $from, '](' . $to, $markdown);
            $markdown = str_replace('`' . $from . '`', '`' . $to . '`', $markdown);
        }

        return (string) preg_replace_callback(
            '#\]\(\./legal/([A-Za-z0-9_]+)\.md([^)]*)\)#',
            static function (array $match): string {
                $slug = self::legalSlug((string) $match[1]);
                $suffix = (string) ($match[2] ?? '');
                return '](/docs/legal/' . $slug . '.md' . $suffix . ')';
            },
            $markdown
        );
    }

    private static function legalSlug(string $basename): string
    {
        return match ($basename) {
            'ROSKOMNADZOR_NOTIFICATION_LETTER' => 'roskomnadzor-notification',
            'README' => 'readme',
            'PRIVACY_POLICY_OUTLINE' => 'privacy-policy-outline',
            'DPO_APPOINTMENT_ORDER' => 'dpo-appointment-order',
            'PROCESSING_REGISTRY_TEMPLATE' => 'processing-registry-template',
            'DATA_PROCESSING_AGREEMENT_OUTLINE' => 'data-processing-agreement-outline',
            'SUBJECT_REQUEST_RESPONSE_TEMPLATE' => 'subject-request-response-template',
            default => strtolower(str_replace('_', '-', $basename)),
        };
    }
}
