<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function printBusinessJourneysSuiteUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/business_journeys_suite.php [--help] [--skip-http] [--skip-demo-seed]\n");
    fwrite(STDOUT, "Runs business process journey checks (delegation, security, team, platform ops, RBAC admin).\n");
}

if (in_array('--help', $argv, true)) {
    printBusinessJourneysSuiteUsage();
    exit(0);
}

$skipHttp = in_array('--skip-http', $argv, true);
$skipDemoSeed = in_array('--skip-demo-seed', $argv, true);
$projectRoot = dirname(__DIR__, 3);
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$toolsDir = $projectRoot . '/maniforge/rbac/tools';

/**
 * @return int
 */
function runBusinessJourneyScript(string $phpBinary, string $scriptPath, string $title): int
{
    fwrite(STDOUT, "\n=== {$title} ===\n");
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
    passthru($command, $exitCode);
    fwrite(STDOUT, "=== {$title} exit: {$exitCode} ===\n");
    return (int) $exitCode;
}

if (!$skipDemoSeed) {
    $seedExit = runBusinessJourneyScript($phpBinary, $toolsDir . '/demo_seed.php', 'Demo Seed');
    if ($seedExit !== 0) {
        fwrite(STDERR, "business_journeys_suite stopped: demo_seed failed.\n");
        exit($seedExit);
    }
}

$delegationScript = $toolsDir . '/delegation_check.php';

if (!$skipHttp) {
    $httpScripts = [
        'agency_delegation_http_journey.php' => 'Agency Delegation HTTP Journey',
        'security_incident_journey.php' => 'Security Incident Journey',
        'team_project_journey.php' => 'Team Project Journey',
        'platform_ops_journey.php' => 'Platform Ops Journey',
        'rbac_admin_journey.php' => 'RBAC Admin Journey',
    ];

    foreach ($httpScripts as $file => $title) {
        $exit = runBusinessJourneyScript($phpBinary, $toolsDir . '/' . $file, $title);
        if ($exit !== 0) {
            fwrite(STDERR, "business_journeys_suite stopped: {$file} failed.\n");
            exit($exit);
        }
    }
}

$delegationExit = runBusinessJourneyScript($phpBinary, $delegationScript, 'Delegation Check (CLI)');
if ($delegationExit !== 0) {
    fwrite(STDERR, "business_journeys_suite stopped: delegation check failed.\n");
    exit($delegationExit);
}

fwrite(STDOUT, "\nAll business journey checks passed.\n");
exit(0);
