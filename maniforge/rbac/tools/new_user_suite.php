<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function printNewUserSuiteUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/new_user_suite.php [--help] [--skip-http]\n");
    fwrite(STDOUT, "Runs new_user_journey_check, race_condition_check, and optionally new_user_http_journey.\n");
}

if (in_array('--help', $argv, true)) {
    printNewUserSuiteUsage();
    exit(0);
}

$skipHttp = in_array('--skip-http', $argv, true);
$projectRoot = dirname(__DIR__, 3);
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';

/**
 * @return int
 */
function runSuiteScript(string $phpBinary, string $scriptPath, string $title): int
{
    fwrite(STDOUT, "\n=== {$title} ===\n");
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
    passthru($command, $exitCode);
    fwrite(STDOUT, "=== {$title} exit: {$exitCode} ===\n");
    return (int) $exitCode;
}

$journeyPath = $projectRoot . '/maniforge/rbac/tools/new_user_journey_check.php';
$racePath = $projectRoot . '/maniforge/rbac/tools/race_condition_check.php';
$httpPath = $projectRoot . '/maniforge/rbac/tools/new_user_http_journey.php';

$journeyExit = runSuiteScript($phpBinary, $journeyPath, 'New User Journey (CLI)');
if ($journeyExit !== 0) {
    fwrite(STDERR, "new_user_suite stopped: journey check failed.\n");
    exit($journeyExit);
}

$raceExit = runSuiteScript($phpBinary, $racePath, 'Race Condition Check');
if ($raceExit !== 0) {
    fwrite(STDERR, "new_user_suite stopped: race condition check failed.\n");
    exit($raceExit);
}

if (!$skipHttp) {
    $httpExit = runSuiteScript($phpBinary, $httpPath, 'New User HTTP Journey');
    if ($httpExit !== 0) {
        fwrite(STDERR, "new_user_suite stopped: HTTP journey failed.\n");
        exit($httpExit);
    }
}

fwrite(STDOUT, "\nAll new user suite checks passed.\n");
exit(0);
