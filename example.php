<?php

// A Sadaya integration in plain PHP: create the order, send the customer to the checkout and receive the signed
// webhook. Try it locally with a test key (sk_test_):
//
//   SADAYA_API_KEY=sk_test_... SADAYA_WEBHOOK_SECRET=whsec_... MY_PAYOUT_WALLET=0x... php -S localhost:8000 example.php
//
// then open http://localhost:8000/buy. In test mode the checkout has buttons to simulate the payment. To receive the
// webhook locally, expose the port with an https tunnel and set that URL in the dashboard (Developers, Webhooks)
// or with $sadaya->setWebhook('https://.../webhooks/sadaya').
//
// The order is created with its network (SADAYA_NETWORK, bsc by default; when it is empty, the customer picks the
// network at Sadaya's checkout), so it is born with its address. With MY_PAYOUT_WALLET (and MY_FEE_BPS if your
// fee is not 100), that address and its payment links are verified before the customer is sent to pay: the library
// recomputes them with your wallet, without trusting Sadaya. Without a network there is no address yet, so there is
// nothing to verify until the customer picks one.

declare(strict_types=1);

use Sadaya\Client;
use Sadaya\SadayaException;
use Sadaya\Webhook;

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php'; // installed with Composer
} else {
    require __DIR__ . '/src/Sadaya/SadayaException.php';
    require __DIR__ . '/src/Sadaya/SadayaVerificationException.php';
    require __DIR__ . '/src/Sadaya/Address.php';
    require __DIR__ . '/src/Sadaya/Client.php';
    require __DIR__ . '/src/Sadaya/Webhook.php';
}

$apiKey = (string) getenv('SADAYA_API_KEY');
$payout = (string) getenv('MY_PAYOUT_WALLET');
$verify = $payout !== '' ? ['payout' => $payout, 'feeBps' => (int) (getenv('MY_FEE_BPS') ?: 100)] : null;
// the network the order is paid on: its key ('bsc') or its chain_id ('56'); when empty, the customer picks it at checkout
$network = getenv('SADAYA_NETWORK');
$network = $network === false ? 'bsc' : trim($network);
$webhookSecret = (string) getenv('SADAYA_WEBHOOK_SECRET');
$isProduction = getenv('APP_ENV') === 'production';
$baseUrl = 'http://localhost:8000';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 1. The customer clicks "Pay": your server creates the order and sends them to the checkout.
if ($path === '/buy') {
    // in your store, the order already exists in your database with its number and its total
    $storeOrder = ['number' => '1024', 'total' => '15.00', 'email' => 'jane@example.com', 'name' => 'Jane Doe'];

    try {
        $sadaya = new Client($apiKey, Client::DEFAULT_BASE_URL, 20, $verify);
        $order = $sadaya->createOrder([
            'amount' => $storeOrder['total'],                       // decimal string, never a float
            'currency' => 'USDT',                                    // or 'USD' to charge a price in dollars
            'external_id' => 'order-' . $storeOrder['number'],       // retrying returns the same order
            'description' => 'Order ' . $storeOrder['number'],
            'customer' => ['email' => $storeOrder['email'], 'name' => $storeOrder['name']],
            'redirect_url' => $baseUrl . '/thanks?order=' . $storeOrder['number'],
            'metadata' => ['store_order' => $storeOrder['number']],
        ] + ($network !== '' ? ['network' => $network] : []));     // with the network, it is born with its address, already verified
    } catch (SadayaException $e) {
        // 409: for example, the order cannot be paid on that network (the message says which networks it can be paid on)
        http_response_code($e->getStatus() === 409 ? 409 : 502);
        echo 'We could not create the payment: ' . htmlspecialchars($e->getMessage());
        exit;
    }

    // store $order['id'] with your order: you need it to check the status (getOrder) or to request a refund
    header('Location: ' . $order['checkout_url'], true, 303);
    exit;
}

// 2. Sadaya notifies you: verify the signature, and deliver ONLY here, on order.paid.
if ($path === '/webhooks/sadaya' && $method === 'POST') {
    $payload = (string) file_get_contents('php://input');            // raw body, exactly as it arrived
    $signature = (string) ($_SERVER['HTTP_X_SADAYA_SIGNATURE'] ?? '');

    try {
        $event = Webhook::verify($payload, $signature, $webhookSecret);
    } catch (SadayaException $e) {
        http_response_code(400);                                       // invalid or stale signature: process nothing
        echo $e->getMessage();
        exit;
    }

    // test events (livemode false) arrive at the same URL: in production, deliver nothing for them
    if ($isProduction && empty($event['livemode'])) {
        http_response_code(200);
        echo 'ok';
        exit;
    }

    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    switch ($event['type']) {
        case 'order.paid':
            // deliver ONCE: the same event may arrive more than once (retries, or a resend from the dashboard)
            error_log('Deliver order ' . ($data['external_id'] ?? '') . ' (event ' . ($event['id'] ?? '') . ')');
            break;
        case 'order.late_payment': // paid, but with a payment that arrived after the order expired
            // never deliver it on its own: the price or the stock may have changed since
            error_log('Late payment for order ' . ($data['external_id'] ?? '') . ': review it before delivering');
            break;
        case 'order.underpaid':
            error_log('Underpaid: ' . ($data['seen'] ?? '0') . ' of ' . ($data['amount'] ?? '') . ' ' . ($data['currency'] ?? '') . ' arrived');
            break;
        case 'order.credited': // deposits ('mode' => 'deposit'): received is the total credited so far
            error_log('Deposit ' . ($data['external_id'] ?? '') . ': credit the difference between ' . ($data['received'] ?? '0') . ' ' . ($data['currency'] ?? '') . ' and what you already credited');
            break;
        case 'order.expired':
            error_log('Order expired unpaid: release the stock of order ' . ($data['external_id'] ?? ''));
            break;
        case 'settlement.paid':
            error_log('Settlement ' . ($data['id'] ?? '') . ': the net amount is already in your wallet (tx ' . ($data['tx_hash'] ?? '') . ')');
            break;
        case 'refund.address_received':
            error_log('The customer left their wallet: send ' . ($data['amount'] ?? '') . ' ' . ($data['symbol'] ?? '') . ' on ' . ($data['network'] ?? '') . ' to ' . ($data['address'] ?? ''));
            break;
        default:
            break; // ignore (with a 2xx) the types you do not use
    }

    http_response_code(200); // answer quickly; if the work is long, queue it
    echo 'ok';
    exit;
}

// 3. The customer comes back from the checkout. Getting here does NOT prove the payment: they can come back without paying.
if ($path === '/thanks') {
    $number = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
    echo '<h1>Thank you!</h1><p>We are confirming the payment for order ' . htmlspecialchars($number) . '. We will let you know by email.</p>';
    exit;
}

// 4. Look up an order by its id (for example, if the webhook is late).
if ($path === '/status' && is_string($_GET['id'] ?? null)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $order = (new Client($apiKey))->getOrder($_GET['id']);
        echo json_encode(['status' => $order['status'], 'received' => $order['received'], 'currency' => $order['currency']]);
    } catch (SadayaException $e) {
        http_response_code($e->getStatus() === 404 ? 404 : 502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo '<p><a href="/buy">Buy the Starter Pack (15.00 USDT)</a></p>';
