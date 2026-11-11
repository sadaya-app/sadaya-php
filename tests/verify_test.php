<?php

// Test of the PHP library's address and payment link verification, without PHPUnit or a connection to the API:
//   php sdk/php/tests/verify_test.php
// It uses tests/vectors.json, which make-vectors.mjs generates with the gateway's own code.
// Exits with code 0 if everything passes and 1 if anything fails.

declare(strict_types=1);

require __DIR__ . '/../src/Sadaya/SadayaException.php';
require __DIR__ . '/../src/Sadaya/SadayaVerificationException.php';
require __DIR__ . '/../src/Sadaya/Address.php';

use Sadaya\Address;
use Sadaya\SadayaVerificationException;

$vectors = json_decode((string) file_get_contents(__DIR__ . '/vectors.json'), true);
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

echo 'PHP ' . PHP_VERSION . ' (' . (PHP_INT_SIZE * 8) . ' bits)' . PHP_EOL;

// 1. Keccak-256, also at the edges of the 136-byte block
foreach ($vectors['keccak'] as $v) {
    $got = '0x' . bin2hex(Address::keccak256(hex2bin(substr($v['input'], 2))));
    check('keccak of ' . ((strlen($v['input']) - 2) / 2) . ' bytes', $got === $v['hash'], $got);
}

// 2. checksum capitals (EIP-55)
foreach ($vectors['checksum'] as $address) {
    check('EIP-55 ' . $address, Address::checksum(strtolower($address)) === $address);
}

// 3. correct orders: the same address as the gateway, and verification accepts them
foreach ($vectors['valid'] as $i => $v) {
    $address = Address::depositAddressOf($v['order']);
    check("address of order $i", $address === $v['order']['deposit_address'], $address);
    try {
        check("order $i verified", Address::verifyOrder($v['order'], $v['expected']) === $v['order']);
    } catch (SadayaVerificationException $e) {
        check("order $i verified", false, $e->getCheck() . ': ' . $e->getMessage());
    }
}

// 4. every tampering is rejected and says what failed
foreach ($vectors['invalid'] as $v) {
    try {
        Address::verifyOrder($v['order'], $v['expected']);
        check($v['why'], false, 'not rejected');
    } catch (SadayaVerificationException $e) {
        check($v['why'], $e->getCheck() === $v['check'], 'failed ' . $e->getCheck() . ', expected ' . $v['check']);
    }
}

// 5. an order with no chosen network has no address or route: that is what it says, not a route error
$first = $vectors['valid'][0];
$pending = array_merge($first['order'], ['deposit_address' => null, 'route' => null, 'network' => null]);
try {
    Address::verifyOrder($pending, $first['expected']);
    check('order with no chosen network', false, 'not rejected');
} catch (SadayaVerificationException $e) {
    check('order with no chosen network', $e->getCheck() === 'network', 'failed ' . $e->getCheck() . ', expected network');
}

// 6. each payment link (uri) pays the order's address, exactly as the gateway builds it, for what is still due; a null
//    uri (no link) passes
$u = $vectors['uri'];
try {
    check('the gateway\'s payment links', Address::verifyOrder($u['order'], $u['expected']) === $u['order']);
} catch (SadayaVerificationException $e) {
    check('the gateway\'s payment links', false, $e->getCheck() . ': ' . $e->getMessage());
}
$token = null;
$coin = null;
foreach ($u['order']['networks'] as $i => $n) {
    if (!empty($n['native'])) {
        $coin = $coin ?? $i;
    } else {
        $token = $token ?? $i;
    }
}
check('the vector has a token and the network coin, on BNB Chain', $token !== null && $coin !== null && $u['order']['network'] === 56);
$deposit = (string) $u['order']['deposit_address'];
$tokenAddress = (string) $u['order']['networks'][$token]['token'];
$fullUnits = (string) $u['order']['networks'][$token]['amount_units'];
$tokenUri = (string) $u['order']['networks'][$token]['uri'];
check('with a partial payment, the link asks for what is still due', $fullUnits !== $u['order']['networks'][$token]['due_units']);
$other = '0x1111111111111111111111111111111111111111';
$tampered = [
    'another address in a token link' => [$token, fn(string $uri): string => str_replace($deposit, $other, $uri)],
    'another address in the network coin link' => [$coin, fn(string $uri): string => str_replace($deposit, $other, $uri)],
    'another token' => [$token, fn(string $uri): string => str_replace($tokenAddress, $other, $uri)],
    'another network in a token link' => [$token, fn(string $uri): string => str_replace('@56/', '@1/', $uri)],
    'another network in the network coin link' => [$coin, fn(string $uri): string => str_replace('@56?', '@1?', $uri)],
    'the whole amount instead of what is still due' => [$token, fn(string $uri): string => (string) preg_replace('/uint256=\d+/', 'uint256=' . $fullUnits, $uri)],
    'another amount of the network coin' => [$coin, fn(string $uri): string => (string) preg_replace('/value=\d+/', 'value=1', $uri)],
    'another token function' => [$token, fn(string $uri): string => str_replace('/transfer?', '/approve?', $uri)],
    'something extra at the end' => [$token, fn(string $uri): string => $uri . '&gas=1'],
    'a line break at the end' => [$token, fn(string $uri): string => $uri . "\n"],
    'a token link in place of the network coin link' => [$coin, fn(string $uri): string => $tokenUri],
];
foreach ($tampered as $why => [$i, $change]) {
    $bad = $u['order'];
    $bad['networks'][$i]['uri'] = $change((string) $bad['networks'][$i]['uri']);
    try {
        Address::verifyOrder($bad, $u['expected']);
        check($why, false, 'not rejected');
    } catch (SadayaVerificationException $e) {
        check($why, $e->getCheck() === 'uri', 'failed ' . $e->getCheck() . ', expected uri');
    }
}
// lowercase hex is the same link; with no links at all (uri null) the order passes too
$lower = $u['order'];
$lower['networks'][$token]['uri'] = strtolower($tokenUri);
$none = $u['order'];
foreach (array_keys($none['networks']) as $i) {
    $none['networks'][$i]['uri'] = null;
}
foreach (['the link with lowercase hex' => $lower, 'no payment links' => $none] as $why => $order) {
    try {
        check($why, Address::verifyOrder($order, $u['expected']) === $order);
    } catch (SadayaVerificationException $e) {
        check($why, false, $e->getCheck() . ': ' . $e->getMessage());
    }
}

echo ($failed === 0 ? 'OK ' : 'FAILED ') . $passed . '/' . ($passed + $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
