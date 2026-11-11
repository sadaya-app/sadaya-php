<?php

// Test of the PHP client's errors, without a connection to the API: the Retry-After header becomes getRetryAfter(), in
// seconds, and the API's own error type is kept. PHP's built-in server plays the API on a free local port.
//   php sdk/php/tests/client_test.php        (needs ext-curl, like the client)
// Exits with code 0 if everything passes and 1 if anything fails.

declare(strict_types=1);

require __DIR__ . '/../src/Sadaya/SadayaException.php';
require __DIR__ . '/../src/Sadaya/SadayaVerificationException.php';
require __DIR__ . '/../src/Sadaya/Address.php';
require __DIR__ . '/../src/Sadaya/Client.php';

use Sadaya\Client;
use Sadaya\SadayaException;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo 'FAIL ' . $name . ($detail !== '' ? ' · ' . $detail : '') . PHP_EOL;
}

// 1. the header's two forms, without a server
check('a number of seconds', Client::retryAfterSeconds('7') === 7);
check('seconds with spaces around', Client::retryAfterSeconds(' 120 ') === 120);
check('an HTTP date counts the seconds left', Client::retryAfterSeconds('Wed, 21 Oct 2015 07:28:30 GMT', gmmktime(7, 28, 0, 10, 21, 2015)) === 30);
check('a date already past is 0', Client::retryAfterSeconds('Wed, 21 Oct 2015 07:28:00 GMT', gmmktime(8, 0, 0, 10, 21, 2015)) === 0);
check('missing or unreadable is null', Client::retryAfterSeconds(null) === null && Client::retryAfterSeconds('') === null && Client::retryAfterSeconds('soon') === null);
check('an exception has none unless given', (new SadayaException('no response'))->getRetryAfter() === null);

// 2. against a local server that answers like the API
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sadaya-client-test-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'router.php', <<<'PHP'
<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fail = static function (int $status, $error, ?string $retryAfter = null): void {
    http_response_code($status);
    header('Content-Type: application/json');
    if ($retryAfter !== null) {
        header('Retry-After: ' . $retryAfter);
    }
    echo json_encode(['error' => $error]);
};
// "Check now", canceling, accepting and the payouts answer what they were asked: the method, the path, the query and the body
if (preg_match('~^/v1/(orders/[0-9a-f-]{36}/(check|cancel|accept)|settlements(/stl_[0-9a-f]{24})?)$~', $path) === 1) {
    header('Content-Type: application/json');
    echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'body' => (string) file_get_contents('php://input')]);
    exit;
}
switch ($path) {
    case '/v1/orders/00000000-0000-4000-8000-000000000428':
        $fail(429, ['type' => 'rate_limit_error', 'message' => 'Too many orders in a short time. Try again in a few minutes.'], '7');
        break;
    case '/v1/orders/00000000-0000-4000-8000-000000000503':
        $fail(503, ['type' => 'invalid_request_error', 'message' => 'Sadaya cannot create orders right now. Try again in a few minutes.'], gmdate('D, d M Y H:i:s', time() + 30) . ' GMT');
        break;
    case '/v1/orders/00000000-0000-4000-8000-000000000429':
        $fail(429, 'Too many requests: wait a few seconds.');
        break;
    default:
        $fail(404, ['type' => 'invalid_request_error', 'message' => 'Order not found.']);
}
PHP);

// a free port: the system picks one, and the server takes it right after
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$log = $dir . DIRECTORY_SEPARATOR . 'server.log';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . DIRECTORY_SEPARATOR . 'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
$ready = false;
for ($i = 0; $i < 100 && !$ready; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket !== false) {
        fclose($socket);
        $ready = true;
    } else {
        usleep(50000);
    }
}
check('the local server starts', $ready, 'port ' . $port);

/** The SadayaException a call throws, or null if it does not throw one. */
function failure(callable $call): ?SadayaException
{
    try {
        $call();
    } catch (SadayaException $e) {
        return $e;
    }
    return null;
}

if ($ready) {
    $client = new Client('sk_test_' . str_repeat('a1', 20), 'http://127.0.0.1:' . $port, 5);

    $e = failure(fn () => $client->getOrder('00000000-0000-4000-8000-000000000428'));
    check('a 429 keeps the API type and its Retry-After', $e !== null && $e->getStatus() === 429 && $e->getType() === 'rate_limit_error'
        && $e->getMessage() === 'Too many orders in a short time. Try again in a few minutes.' && $e->getRetryAfter() === 7,
        $e === null ? 'no error' : $e->getStatus() . ' ' . $e->getType() . ' ' . var_export($e->getRetryAfter(), true));

    $e = failure(fn () => $client->getOrder('00000000-0000-4000-8000-000000000503'));
    check('a Retry-After date becomes the seconds left', $e !== null && $e->getStatus() === 503 && $e->getRetryAfter() !== null
        && $e->getRetryAfter() >= 28 && $e->getRetryAfter() <= 30, $e === null ? 'no error' : var_export($e->getRetryAfter(), true));

    $e = failure(fn () => $client->getOrder('00000000-0000-4000-8000-000000000429'));
    check('a 429 without a type or the header', $e !== null && $e->getType() === 'rate_limit_error' && $e->getRetryAfter() === null,
        $e === null ? 'no error' : $e->getType());

    $e = failure(fn () => $client->getOrder('11111111-2222-4333-8444-555555555555'));
    check('a 404 has no Retry-After', $e !== null && $e->getStatus() === 404 && $e->getRetryAfter() === null);

    // the routes of "Check now", canceling and the payouts
    $order = '6f1c2a7e-3b9d-4c51-9e0a-2d7f8b1c4e90';
    $stl = 'stl_3f9a7c2e8b4d1f6a0c5e9b7d';
    $r = $client->checkOrder($order);
    check('checkOrder posts to the order\'s check', $r['method'] === 'POST' && $r['path'] === '/v1/orders/' . $order . '/check' && $r['body'] === '{}', json_encode($r));
    $r = $client->cancelOrder($order);
    check('cancelOrder posts to the order\'s cancel', $r['method'] === 'POST' && $r['path'] === '/v1/orders/' . $order . '/cancel' && $r['body'] === '{}', json_encode($r));
    $r = $client->acceptOrder($order);
    check('acceptOrder posts to the order\'s accept', $r['method'] === 'POST' && $r['path'] === '/v1/orders/' . $order . '/accept' && $r['body'] === '{}', json_encode($r));
    $e = failure(fn () => $client->acceptOrder(''));
    check('a missing order id never reaches the API to accept it', $e !== null && $e->getStatus() === 0 && strpos($e->getMessage(), 'order id') !== false, $e === null ? 'no error' : $e->getMessage());
    $r = $client->listSettlements(['limit' => 2, 'before' => $stl, 'unused' => null]);
    check('listSettlements reads the payouts with limit and before', $r['method'] === 'GET' && $r['path'] === '/v1/settlements' && $r['query'] === ['limit' => '2', 'before' => $stl], json_encode($r));
    $r = $client->getSettlement($stl);
    check('getSettlement reads one payout by its id', $r['method'] === 'GET' && $r['path'] === '/v1/settlements/' . $stl, json_encode($r));
    $e = failure(fn () => $client->getSettlement(' '));
    check('a missing payout id never reaches the API', $e !== null && $e->getStatus() === 0 && strpos($e->getMessage(), 'settlement id') !== false);
}

proc_terminate($server);
proc_close($server);
foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

echo ($failed === 0 ? 'OK ' : 'FAILED ') . $passed . '/' . ($passed + $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
