<?php

/**
 * Exercise Combodo's real CLI against a private HTTPS endpoint in the isolated Docker fixture.
 * See base-integration.md for the read-only inputs and writable memory filesystem.
 */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use RuntimeException;

/** Fail the check with a diagnostic that is safe to show in CI. */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Run a command to completion and capture its combined diagnostics.
 *
 * @param list<string> $command Arguments passed directly to the executable.
 * @return array{int,string} Exit status and console output.
 */
function execute(array $command): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    check(is_resource($process), 'Could not launch fixture process.');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

/**
 * Launch the actual release entry point using a fixture-only CA.
 * @return array{int,string} Exit status and console output.
 */
function runBase(string ...$arguments): array
{
    return execute([PHP_BINARY, '-d', 'curl.cainfo=/work/cert.pem', '/work/exec.php', ...$arguments]);
}

/**
 * Read each published snapshot from the isolated workspace.
 * @return list<array<string,mixed>> Decoded inventory reports.
 */
function reports(): array
{
    $reports = [];
    foreach (glob('/work/.tmp/inventory/*/inventory.json') as $file) {
        $reports[] = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }
    return $reports;
}

/**
 * Write the synthetic HTTP response which OpenSSL serves over loopback TLS.
 * @param array<string,mixed> $payload JSON response object.
 */
function reply(int $status, array $payload): void
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $reason = $status === 200 ? 'OK' : 'Forbidden';
    $response = "HTTP/1.1 $status $reason\r\nContent-Type: application/json\r\nContent-Length: "
        .strlen($body)."\r\nConnection: close\r\n\r\n".$body;
    check(file_put_contents('/work/inventory', $response) === strlen($response), 'Could not write fixture response.');
}

check(getcwd() === '/work' && !file_exists('/work/exec.php') && is_file('/base/exec.php'), 'Use the empty Docker fixture workspace.');
check(getenv('IONOS_TOKEN') === false, 'Run the test without a host token.');
mkdir('/work/conf');
mkdir('/work/collectors');
symlink('/base/core', '/work/core');
copy('/base/exec.php', '/work/exec.php');
copy('/base/conf/params.distrib.xml', '/work/conf/params.distrib.xml');
copy('/module/main.php', '/work/collectors/main.php');
copy('/module/params.distrib.xml', '/work/collectors/params.distrib.xml');
mkdir('/work/collectors/src');
foreach (glob('/module/src/*.php') as $file) {
    copy($file, '/work/collectors/src/'.basename($file));
}
$local = simplexml_load_file('/example.xml', options: LIBXML_NONET);
check($local !== false, 'Invalid operator configuration example.');
$local->ionos_products = 'fixture';
$local->ionos_api_bases = 'https://127.0.0.1:8443';
$local->ionos_token_env = 'IONOS_FIXTURE_TOKEN';
$local->ionos_timeout = '4';
$local->itop_url = 'https://cmdb.example.invalid';
$local->synchro_user = 'fixture-user';
$local->itop_login = 'fixture-user';
$local->itop_password = 'fixture-password';
$local->asXML('/work/conf/params.local.xml');
define('APPROOT', '/work/');
require_once APPROOT.'core/parameters.class.inc.php';
require_once APPROOT.'core/utils.class.inc.php';
check(\Utils::GetCredentials() === ['auth_user'=>'fixture-user', 'auth_pwd'=>'fixture-password']
    && \Utils::GetLoginMode() === 'form', 'Base password authentication settings did not resolve.');
$local->itop_token = 'fixture-itop-token';
$local->asXML('/work/conf/params.local.xml');
\Utils::LoadConfig();
check(\Utils::GetCredentials() === ['auth_token'=>'fixture-itop-token'] && \Utils::GetLoginMode() === 'token'
    && \Utils::GetConfigurationValue('synchro_user') === 'fixture-user'
    && \Utils::GetConfigurationValue('itop_url') === 'https://cmdb.example.invalid',
    'Base token authentication or synchronization owner did not resolve.');
file_put_contents('/work/collectors/resources.json', json_encode([
    'fixture' => ['bases' => ['https://127.0.0.1:8443', 'https://unselected.invalid'],
        'resources' => ['/inventory' => ['offset' => false]]],
], JSON_THROW_ON_ERROR));
$fixtureToken = bin2hex(random_bytes(24));
file_put_contents('/work/.env', "IONOS_FIXTURE_TOKEN=".$fixtureToken."\n");

[$status, $output] = execute(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
    '-keyout', '/work/key.pem', '-out', '/work/cert.pem', '-subj', '/CN=127.0.0.1',
    '-addext', 'subjectAltName=IP:127.0.0.1', '-days', '1']);
check($status === 0, 'Could not generate temporary loopback certificate.');
$server = proc_open(['openssl', 's_server', '-quiet', '-HTTP', '-accept', '127.0.0.1:8443',
    '-cert', '/work/cert.pem', '-key', '/work/key.pem'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
check(is_resource($server), 'Could not start the fixture server.');
try {
    for ($i = 0; $i < 100; ++$i) {
        $ready = @stream_socket_client('tcp://127.0.0.1:8443', $errno, $error, 0.1);
        if ($ready !== false) {
            fclose($ready);
            break;
        }
        usleep(20000);
    }
    check($ready !== false, 'Loopback fixture did not become ready.');
    foreach ([[], ['--configure_only']] as $flags) {
        [$code, $output] = runBase(...$flags);
        check($code !== 0 && str_contains($output, 'ionos_org_id'), 'Synchronization requires an explicit customer and provider.');
    }
    foreach ([['--synchro_only'], ['--collect_only', '--synchro_only'], ['--collect_only', '--configure_only']] as $flags) {
        [$code, $output] = runBase(...$flags);
        check($code !== 0 && str_contains($output, 'fresh VDC sync'), 'Unsafe mode must fail before contacting iTop.');
    }
    check(reports() === [], 'A disabled mode produced an inventory report.');

    reply(200, ['items' => [['id' => 'fixture-vdc', 'properties' => ['name' => 'Fixture datacenter']]]]);
    [$code, $output] = runBase('--collect_only');
    check(!str_contains($output, $fixtureToken), 'Console output exposed the fixture token.');
    check($code === 0, 'Base collection failed: '.$output);
    $complete = reports();
    check(count($complete) === 1 && $complete[0]['complete'] === true
        && $complete[0]['apiBases'] === ['fixture'=>['https://127.0.0.1:8443']]
        && $complete[0]['scopes'][0]['items'][0]['id'] === 'fixture-vdc', 'Unexpected complete inventory.');

    reply(403, ['error' => 'fixture access denied']);
    [$code, $output] = runBase('--collect_only');
    $partial = array_values(array_filter(reports(), static fn (array $report): bool => !$report['complete']));
    check($code !== 0 && count($partial) === 1 && $partial[0]['scopes'][0]['status'] === 'forbidden',
        'Base must propagate an incomplete inventory as a failure.');
    check(!str_contains($output, $fixtureToken), 'Failure output exposed the fixture token.');
    mkdir('/work/scripts');
    copy('/standalone.php', '/work/scripts/inventory.php');
    $cli = [PHP_BINARY, '-d', 'curl.cainfo=/work/cert.pem', '/work/scripts/inventory.php'];
    reply(200, ['items' => []]);
    [$code] = execute($cli);
    check($code === 0 && count(reports()) === 3, 'Standalone inventory did not use the deployment configuration.');
    $local->ionos_api_bases = 'https://outside-plan.invalid';
    $local->asXML('/work/conf/params.local.xml');
    [$code, $output] = execute($cli);
    check($code === 1 && str_contains($output, 'unknown_api_base') && count(reports()) === 3,
        'Out-of-plan endpoints must fail before making a request.');
    echo "Combodo Base verified inventory, partial coverage and synchronization preconditions.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
