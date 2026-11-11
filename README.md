# sadaya/sadaya-php

Sadaya API client for PHP: create, check, cancel and accept orders, look up payments and payouts, request refunds, configure
your webhook and verify its signatures. No dependencies: only `ext-curl` and `ext-json`.

> **Server-side only.** Your API key (`sk_live_...` or `sk_test_...`) must never reach a browser, a mobile app or a
> repository. Keep it in an environment variable.

Full API reference: https://docs.sadaya.app/docs

## Requirements

- PHP 8.2 or later, 64-bit (the norm on any current server). Older branches no longer get security fixes. Keep PHP on the
  latest patch of its branch: a payment integration should never run on a PHP version with known, fixed vulnerabilities.
- The `curl` and `json` extensions (available on almost every host).

## Installation

With Composer:

```bash
composer require sadaya/sadaya-php
```

Without Composer, copy the `src/Sadaya` folder into your project and load it by hand:

```php
require 'src/Sadaya/SadayaException.php';
require 'src/Sadaya/SadayaVerificationException.php';
require 'src/Sadaya/Address.php';
require 'src/Sadaya/Client.php';
require 'src/Sadaya/Webhook.php';
```

## Quick start

```php
<?php
use Sadaya\Client;
use Sadaya\SadayaException;

$sadaya = new Client((string) getenv('SADAYA_API_KEY'));

try {
    $order = $sadaya->createOrder([
        'amount' => '15.00',                    // always a decimal string, never a float
        'currency' => 'USDT',
        'external_id' => 'order-1024',          // your order number: retrying never creates a second charge
        'description' => 'Starter Pack',
        'customer' => ['email' => 'jane@example.com', 'name' => 'Jane Doe'],
        'redirect_url' => 'https://your-store.com/thanks?order=1024',
    ]);
} catch (SadayaException $e) {
    error_log($e->getStatus() . ' ' . $e->getType() . ': ' . $e->getMessage());
    exit('We could not create the payment.');
}

header('Location: ' . $order['checkout_url'], true, 303); // the customer pays on Sadaya's checkout
exit;
```

At checkout, the customer picks a coin and a network and clicks "Got it, show the address" before the address shows
up (see [Choosing the network](#choosing-the-network)). Deliver the product when the `order.paid` webhook arrives,
never because the customer came back to your `redirect_url`; for a deposit, the event is `order.credited` (see
[Deposits](#deposits)). The `example.php` file has the whole flow (create, redirect, webhook and lookup), ready to run
with `php -S localhost:8000 example.php`.

## The client

```php
new Client(string $apiKey, string $baseUrl = Client::DEFAULT_BASE_URL, int $timeout = 20, ?array $verify = null)
```

- `$apiKey`: `sk_live_...` (real money) or `sk_test_...` (test mode). Validated when the client is created.
- `$baseUrl`: `https://api.sadaya.app` by default (without `/v1`).
- `$timeout`: maximum seconds per request.
- `$verify`: `['payout' => your payout wallet, 'feeBps' => your agreed fee]` (or `maxFeeBps`): every order with an
  address that the API returns is verified (its address and its payment links) before it reaches you. See
  [Verifying each order's address](#verifying-each-orders-address).

`$sadaya->isLivemode()` tells whether the key is an `sk_live_` key. The client sends `User-Agent: sadaya-php/1.0` (its
major and minor version) and never shows the key in `var_dump` or `print_r`.

| Method | Endpoint | Returns (associative array) |
|---|---|---|
| `createOrder(array $params)` | `POST /v1/orders` | The order (201 when new, 200 if the `external_id` already existed) |
| `getOrder(string $id)` | `GET /v1/orders/{id}` | The order with `payments` and `refunds` |
| `chooseNetwork(string $id, $network)` | `POST /v1/orders/{id}/network` | The order with its address. `$network`: `chain_id` (`56`) or key (`'bsc'`). Only once: another network answers 409 |
| `listNetworks()` | `GET /v1/networks` | `['object' => 'list', 'data' => [...], 'has_more' => false]`: your account's networks, the same in test and live mode. Offer only these in your own network picker |
| `listOrders(array $params = [])` | `GET /v1/orders` | `['object' => 'list', 'data' => [...], 'has_more' => bool]` |
| `checkOrder(string $id)` | `POST /v1/orders/{id}/check` | The order with `payments` and `refunds`, after looking at the chain for it right now |
| `cancelOrder(string $id)` | `POST /v1/orders/{id}/cancel` | The order with `payments` and `refunds`, canceled. Only one that never received anything: otherwise 409 |
| `acceptOrder(string $id)` | `POST /v1/orders/{id}/accept` | The order with `payments` and `refunds`, accepted as paid with what it received. Only an underpaid order, while accepting by API is on: otherwise 409 or 403 |
| `listSettlements(array $params = [])` | `GET /v1/settlements` | `['object' => 'list', 'data' => [...], 'has_more' => bool]`: your payouts, newest first, each as `settlement.paid` carried it |
| `getSettlement(string $id)` | `GET /v1/settlements/{id}` | The payout |
| `createRefund(array $params)` | `POST /v1/refunds` | The refund |
| `getRefund(string $id)` | `GET /v1/refunds/{id}` | The refund |
| `listRefunds(array $params = [])` | `GET /v1/refunds` | `['object' => 'list', 'data' => [...]]` |
| `markRefundSent(string $id, string $txHash)` | `POST /v1/refunds/{id}/sent` | The refund |
| `cancelRefund(string $id)` | `POST /v1/refunds/{id}/cancel` | The refund |
| `getWebhook()` | `GET /v1/webhook` | `['object' => 'webhook', 'url' => ..., 'secret_set' => bool]` |
| `setWebhook(string $url, bool $rotateSecret = false)` | `POST /v1/webhook` | The same, plus `secret` when it is created or rotated |

### Orders

```php
$order = $sadaya->getOrder('6f1c2a7e-3b9d-4c51-9e0a-2d7f8b1c4e90');
echo $order['status'];    // pending, confirming, underpaid, paid, expired or canceled
echo $order['received'];  // received and confirmed, in the order's currency

// pagination: newest first; for the next page, pass before = created_at of the last one
$before = null;
do {
    $page = $sadaya->listOrders(['limit' => 100, 'status' => 'paid', 'before' => $before]);
    foreach ($page['data'] as $o) {
        echo $o['id'], ' ', $o['amount'], ' ', $o['currency'], PHP_EOL;
    }
    $last = end($page['data']);
    $before = $last ? $last['created_at'] : null;
} while ($page['has_more'] && $before !== null);
```

`before` also accepts a `DateTimeInterface` (it is converted to ISO 8601 in UTC).

Line items go in `metadata`, which comes back in the order and in every webhook (up to 4,000 characters of JSON):

```php
$order = $sadaya->createOrder([
    'amount' => '25.00', 'currency' => 'USD', 'external_id' => 'order-1030',
    'metadata' => ['items' => [['name' => 'Starter Pack', 'quantity' => 1, 'price' => '15.00'], ['name' => 'Extra seat', 'quantity' => 2, 'price' => '5.00']]],
]);
```

`checkOrder($id)` looks at the chain for that order right now, like **Check now** in the dashboard, and returns it with
its payments. It shares the dashboard's limit for your account (a `429` brings `getRetryAfter()`), it only looks at live
orders (a test order answers `400`: simulate its payment instead) and only while the order is watched (until its review
window ends; after that, `409`).

`cancelOrder($id)` cancels an order that never received anything (waiting or expired): the checkout stops showing its
address and you get `order.canceled`. One that already has a payment answers `409`, and canceling it again returns it as
it is. If money reaches a canceled order anyway, it counts as a late payment: a charge gets `order.late_payment` and a
deposit `order.credited`, with `flags.late`, and the order keeps its `canceled_at`.

`acceptOrder($id)` accepts an underpaid order as paid with what it received (for example, 15 of 35 USD), when you decide
to deliver anyway: it becomes `paid`, `received` stays what really arrived, `flags.accepted`, `accepted_at` and
`accepted_by` say it, and you get `order.paid` once. It is off until your account's owner turns on accepting by API in the
dashboard (**Developers**, **API keys**); meanwhile it answers `403`. Only a charge in `underpaid` with every payment that
counts confirmed (none confirming, held or waiting for its price): otherwise `409`, with the reason. At most 20 per hour
per account and mode, the dashboard included (`429`, with `getRetryAfter()`). Accepting it again returns it as it is, and
every acceptance emails the owner. With it on, set each key's allowed IPs.

```php
$taken = $sadaya->acceptOrder($order['id']); // $taken['status'] === 'paid', $taken['flags']['accepted'] === true
```

### Payouts

`listSettlements` returns your payouts, newest first, each exactly as the `settlement.paid` webhook carried it: its id
(`stl_...`), the network and transaction, the totals per token (gross, fee and net) and one line per order with your
`external_id`. For the next page, pass the `id` of the last one in `before`. A test key gets an empty list: test orders
are never paid out.

```php
$before = null;
do {
    $page = $sadaya->listSettlements(['limit' => 100, 'before' => $before]);
    foreach ($page['data'] as $s) {
        echo $s['id'], ' ', $s['tx_hash'], PHP_EOL;
    }
    $last = end($page['data']);
    $before = $page['has_more'] && $last ? $last['id'] : null;
} while ($before !== null);

$one = $sadaya->getSettlement('stl_3f9a7c2e8b4d1f6a0c5e9b7d');
```

### Choosing the network

Each order is paid on a single network: its address is sealed for that network, and anything that arrives on another
network or in another coin does not count toward the order and may be lost. Recovering a misdirected payment is a
complex and uncertain process that often cannot be completed, so a return is not guaranteed. If your customer files a
claim, it follows the misdirected payments policy agreed with your merchant account.

- With `network` when you create the order (its `chain_id`, such as `56`, or its key, such as `'bsc'`), the order is
  born with its address. `createOrder` throws a `SadayaException` with status `400` if that network is not enabled for
  your account, or `409` if it cannot take this order (the message lists the networks it can be paid on).
- Without `network`, the customer picks it at Sadaya's checkout. Until then `deposit_address`, `route` and `network`
  are `null`, and so is each network's `uri`. If the order can only be paid on one network, it is born with that one.
- In your own checkout, choose it later with `chooseNetwork`, only once: another network answers `409` (to charge on
  another network, create another order), a network the order does not offer answers `400`, and an order with no
  network that has already expired answers `409`.

```php
// with the network: the order is born with its address
$order = $sadaya->createOrder(['amount' => '15.00', 'currency' => 'USDT', 'external_id' => 'order-1024', 'network' => 'bsc']);

// without a network: deposit_address is null until the customer picks it at checkout, or until you pick it, once
$other = $sadaya->createOrder(['amount' => '15.00', 'currency' => 'USDT', 'external_id' => 'order-1025']);
$sealed = $sadaya->chooseNetwork($other['id'], 56);
```

If you show the address yourself, first show the same notice as Sadaya's checkout: "This order is paid only with
{coin} on {network}. Anything you send on another network or in another coin does not count toward this order and
may be lost."

### Orders in dollars

```php
$order = $sadaya->createOrder(['amount' => '49.90', 'currency' => 'USD', 'external_id' => 'order-2048']);
// $order['networks'] lists USDT and USDC (one to one) and ETH or BNB with their price_usd, a Chainlink rate fixed when
// the order is created and valid until $order['expires_at']: for every network while none is chosen, and only for the
// chosen one afterwards
```

A late ETH or BNB payment is valued at the lower of that rate and the rate when Sadaya detects it: review
`order.late_payment` before delivering.

ETH can also be paid on BNB Chain, as its BEP-20 token (Binance-Peg ETH): that entry of `$order['networks']` has `symbol`
`ETH`, `label` `ETH (BEP-20)` and the token's address. It pays an order in `ETH` one to one, and an order in `USD` at
its own `price_usd`, with the same rule for late payments. Wrapped coins such as WETH or WBNB do not count toward any
order.

### Deposits

With `'mode' => 'deposit'` the order does not charge a set amount: it credits whatever arrives. It suits exchanges and
wallets, where the user announces how much they will deposit but may send more or less.

```php
$deposit = $sadaya->createOrder(['amount' => '500', 'currency' => 'USD', 'mode' => 'deposit', 'network' => 'bsc', 'external_id' => 'deposit-881']);
```

- `amount` is what the user announced: the checkout shows it and each network's minimum applies to it, but it does not
  decide how much is credited.
- The order turns `paid` as soon as any amount is confirmed, and you get `order.credited`. If more arrives at the same
  address later, it is added up and you get another `order.credited`.
- `received` is the total credited so far: credit your user the difference from what you already credited.
- A deposit never goes through `underpaid` and never sends `order.paid` or `order.underpaid`. A late payment is
  credited all the same, with `flags.late`.
- In `USD`, a deposit takes dollars one to one (USDT, USDC and the other ones the network accepts) and also ETH and
  BNB (on BNB Chain, ETH as its BEP-20 token), each credited at its dollar price when it arrives, rounded down. In
  `networks` those coins come with `guide: true`: their `price_usd`, `amount` and `uri` are those of the price right
  now, only a guide for the announced `amount`. Each payment of the order carries the price it counted at (`price_usd`)
  and the dollars it credited (`value`). A coin whose price cannot be read yet waits: the deposit stays `confirming` and
  nothing is credited until its price is read. A deposit in `USDT` or `USDC` takes only dollars.

### Refunds

Sadaya never takes custody of your money: you send the refund from your payout wallet and Sadaya verifies it on chain.

```php
$refund = $sadaya->createRefund(['order_id' => $order['id'], 'reason' => 'Order canceled']);
// 1. send $refund['customer_url'] to your customer (if the order has their email, Sadaya sends it too):
//    that is where they leave the wallet that receives the refund
// 2. you get refund.address_received: send amount of symbol on network to address
// 3. record the hash of your transaction (or paste it in the dashboard: Refunds, Mark as sent): Sadaya verifies it
//    on chain and you get refund.completed (with tx_hash)
$done = $sadaya->markRefundSent($refund['id'], '0x...');
```

A refund is at least 1 USD, and an order has one open refund at a time (another one answers `409`). Once the customer
enters their wallet it cannot change: to send the refund elsewhere, cancel it and create a new one. A transaction that
did not come from your payout wallet stays `sent` until Sadaya checks it, and the customer is emailed only once the
refund is `completed`.

### Configuring the webhook through the API

```php
$webhook = $sadaya->setWebhook('https://your-store.com/webhooks/sadaya');
// $webhook['secret'] (whsec_...) is only returned this once: store it in your environment variables

$sadaya->setWebhook('https://your-store.com/webhooks/sadaya', true); // a new secret; the previous one stops validating
```

`setWebhook` needs the live key (`sk_live_`): there is a single webhook for test and live mode, and with a test key
the API answers `403`.

## Verifying each order's address

Each order's address comes from a public formula: the official factory, the order id and its route (`route`). The
library recomputes it without trusting Sadaya and checks that the route pays your wallet and your agreed fee, and that
every payment link pays that address: your store only shows the customer an address it verified itself.

```php
use Sadaya\Client;
use Sadaya\SadayaVerificationException;

// from YOUR configuration, never from the API: your payout wallet and your agreed fee (100 = 1%)
$sadaya = new Client(getenv('SADAYA_API_KEY'), Client::DEFAULT_BASE_URL, 20, [
    'payout' => getenv('MY_PAYOUT_WALLET'),
    'feeBps' => 100,
]);

try {
    // with network, the order is born with its address and comes back already verified
    $order = $sadaya->createOrder(['amount' => '15.00', 'currency' => 'USD', 'external_id' => $cart['id'], 'network' => 'bsc']);
    // you can now show $order['deposit_address'] and each network's uri, or send the customer to $order['checkout_url']
} catch (SadayaVerificationException $e) {
    // $e->getCheck() says what failed: route, payout, fee, fee_recipient, recovery, review, networks, address, uri or network
    // do not show the address; let Sadaya know
}
```

It checks that `route.merchant` is your wallet, that the fee is the agreed one (`feeBps`, or at most `maxFeeBps`),
that everything the checkout offers is in the accepted list (and, in a deposit, that every coin its route takes is
listed in `networks`; otherwise the check `networks`), that the recomputed address is the order's, and that each
network's `uri` pays that address, with that token, on that network and for what is still due (`due_units`), exactly
as Sadaya builds it (otherwise, the check `uri`). You can also pin `feeRecipient` and `recovery`. For a single order:
`Address::verifyOrder($order, ['payout' => ..., 'feeBps' => ...])`.

**With no chosen network there is nothing to verify.** An order created without `network` comes back with
`deposit_address`, `route` and each `uri` set to `null`, and the library returns it as it is (if it carried a `uri`,
it throws with the check `uri`). Verify it once it has an address: when you choose the network with `chooseNetwork`,
or when you read it with `getOrder` after the customer chose it at checkout. `Address::verifyOrder` on an order with
no network throws with the check `network`, so in a webhook only verify when `$event['data']['deposit_address']` is
not `null`: an order that expires before anyone chooses its network arrives without an address.

Since factory v3, the address also seals who splits the order during the review window (`settler`) and until when
(`open_after`, in unix seconds). `verifyOrder` rejects a window longer than `maxReviewDays` (31 by default) with the
check `review`. Older orders (factory v2) verify as before.

This library already knows the next factory version and still verifies v3 and v2 orders. The fee (`feeBps` or
`maxFeeBps`) is required: without it, the fee each order charges you cannot be checked.

If you send the customer to the hosted checkout, verification confirms that the order pays you; what the customer sees
on that page is served by Sadaya. To cover that too, show the verified address yourself: in your own checkout, with a
QR code of the bare address (every wallet and exchange can read it) and a button with each network's `uri` (the
library already checked that it pays that address), or with the embedded checkout and `expectedAddress`.

## Verifying webhooks

```php
Webhook::verify(string $payload, string $signatureHeader, string $secret, int $tolerance = 300, ?int $now = null): array
```

It checks the `X-Sadaya-Signature` signature (HMAC-SHA256 with `hash_hmac` and `hash_equals`) and that it is no older
than 300 seconds. It returns the event as an array or throws a `SadayaException` (type `signature_error`).

It needs the **raw body**: `file_get_contents('php://input')`. If you run it through `json_decode` and then
`json_encode`, the signature no longer matches.

```php
<?php
use Sadaya\SadayaException;
use Sadaya\Webhook;

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_SADAYA_SIGNATURE'] ?? '');

try {
    $event = Webhook::verify($payload, $signature, (string) getenv('SADAYA_WEBHOOK_SECRET'));
} catch (SadayaException $e) {
    http_response_code(400);
    exit;
}

switch ($event['type']) {
    case 'order.paid':
        // deliver once per order: the event may repeat (use $event['id'] to avoid duplicates)
        break;
    case 'order.late_payment':   // paid, but with a payment that arrived after the order expired
        // price and stock may have changed: review it and decide before delivering
        break;
    case 'order.credited':       // deposits: $event['data']['received'] is the total credited so far
        // credit your user the difference from what you already credited
        break;
    case 'order.overpaid':       // more reached a charge that was already paid: $event['data']['received'] is the new total
        // the extra since the last notice is yours: refund it or credit it
        break;
    case 'order.expired':
        // release the stock
        break;
}
http_response_code(200);
```

### Laravel

```php
// routes/web.php (and exclude the route from CSRF in VerifyCsrfToken::$except or in bootstrap/app.php)
Route::post('/webhooks/sadaya', function (Illuminate\Http\Request $request) {
    try {
        $event = Sadaya\Webhook::verify(
            $request->getContent(),
            (string) $request->header('X-Sadaya-Signature', ''),
            (string) config('services.sadaya.webhook_secret')
        );
    } catch (Sadaya\SadayaException $e) {
        return response($e->getMessage(), 400);
    }
    // ... handle $event['type']
    return response('ok');
});
```

## Errors

Everything that fails throws `Sadaya\SadayaException`:

| `getType()` | When |
|---|---|
| `invalid_request_error` | 400, 403, 404, 409 or 503: invalid data, something your account cannot do (a paused merchant, a test key in `setWebhook`), not found, a conflict, or Sadaya cannot create orders at that moment: `getMessage()` says which. Also a usage error (a missing id), with status 0. |
| `authentication_error` | 401: invalid or revoked key. 403: the key has allowed IPs and the call came from another one. |
| `verification_error` | The order failed the verification of its address or of its payment links (`SadayaVerificationException`, with `getCheck()`). |
| `rate_limit_error` | 429: too many requests (for example, too many orders in a short time). Wait at least `getRetryAfter()` seconds before retrying. |
| `api_error` | A 5xx without a type, or a response that is not JSON. |
| `connection_error` | No response: network down or timeout (`getStatus()` is 0). |
| `signature_error` | `Webhook::verify` rejected the webhook. |

`getType()` is the one the API sends; if the response has none, the client sets it from the HTTP status.
`getMessage()` holds the API's own message, `getStatus()` the HTTP status, `getRetryAfter()` the seconds of the
response's `Retry-After` header (or `null` without one) and `getBody()` the full response. The client never retries
on its own: retrying `createOrder` is safe if you send `external_id` (it returns the same order,
as it is: its `network` and `mode` do not change; if you created it without a network, choose it with
`chooseNetwork`).

## Test mode

With an `sk_test_` key, orders come out with `livemode` set to `false` and never touch a blockchain. The checkout shows
buttons to simulate the payment (full or partial), and you get the same webhooks, with `livemode` set to `false`. If
the order has no network, simulating the payment seals it on the first network, as if the customer had chosen it; on
a deposit, both buttons end in `paid` with `order.credited`. Those events arrive at the same URL as the real ones: in
production, deliver nothing for an event with `livemode` set to `false`.
