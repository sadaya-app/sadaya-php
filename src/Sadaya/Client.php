<?php

declare(strict_types=1);

namespace Sadaya;

/**
 * Client for the Sadaya API v1 (PHP 8.2 or later, with ext-curl and ext-json).
 *
 * Use it only on your server: the API key (sk_live_ or sk_test_) never goes to the browser.
 * Every method returns the JSON response as an associative array and throws SadayaException if something fails.
 */
class Client
{
    public const DEFAULT_BASE_URL = 'https://api.sadaya.app';
    public const VERSION = '1.0.3';

    // sk_live_ or sk_test_ followed by 40 hex characters (the same 24 to 64 range the API accepts is allowed)
    private const KEY_PATTERN = '/^sk_(live|test)_[a-z0-9]{24,64}$/';

    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    /** @var array<string, mixed>|null */
    private ?array $verify;

    /**
     * @param string $apiKey  sk_live_... (real money) or sk_test_... (test mode).
     * @param string $baseUrl The Sadaya API URL, without /v1.
     * @param int    $timeout Maximum time per request, in seconds.
     * @param array<string, mixed>|null $verify With ['payout' => your payout wallet, 'feeBps' => your agreed fee]
     *                                          (or maxFeeBps), every order with an address that the API returns is
     *                                          verified before it reaches you (its address and its payment links); if
     *                                          they do not match, it throws SadayaVerificationException. An order with
     *                                          no chosen network comes back as it is: it has no address yet.
     * @throws SadayaException If the key or the URL does not have the right format.
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 20,
        ?array $verify = null
    ) {
        $apiKey = trim($apiKey);
        if (preg_match(self::KEY_PATTERN, $apiKey) !== 1) {
            throw new SadayaException(
                'Invalid API key: it must start with sk_live_ or sk_test_. Create one in the dashboard (Developers, API keys) and keep it in an environment variable.',
                0,
                'invalid_request_error'
            );
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (preg_match('~^https?://[^\s/?#]+~i', $baseUrl) !== 1) {
            throw new SadayaException('Invalid baseUrl: use a URL such as ' . self::DEFAULT_BASE_URL, 0, 'invalid_request_error');
        }
        if ($timeout < 1) {
            throw new SadayaException('The timeout must be at least 1 second.', 0, 'invalid_request_error');
        }
        if (!function_exists('curl_init')) {
            throw new SadayaException('The PHP curl extension (ext-curl) is missing.', 0, 'invalid_request_error');
        }
        if ($verify !== null && (!is_string($verify['payout'] ?? null) || preg_match('/^0x[0-9a-fA-F]{40}$/', $verify['payout']) !== 1)) {
            throw new SadayaException("verify['payout'] must be your payout wallet (0x and 40 hex characters), taken from your own configuration, never from the API.", 0, 'invalid_request_error');
        }
        if ($verify !== null && !isset($verify['feeBps']) && !isset($verify['maxFeeBps'])) {
            throw new SadayaException("verify['feeBps'] (your agreed fee) or verify['maxFeeBps'] (the highest fee you accept) is required: without it, the fee each order charges you is not checked.", 0, 'invalid_request_error');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->verify = $verify;
    }

    /**
     * With the verify option, no order with an address leaves this client unverified.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     * @throws SadayaVerificationException
     */
    private function checked(array $order): array
    {
        if ($this->verify === null) {
            return $order;
        }
        if (($order['deposit_address'] ?? null) !== null) {
            return Address::verifyOrder($order, $this->verify);
        }
        // an order whose network nobody chose yet has no address: there is nothing to show, nor to check, and the gateway
        // gives it no payment link either, so a link there is refused like a wrong one
        foreach ($order['networks'] ?? [] as $network) {
            if (is_array($network) && ($network['uri'] ?? null) !== null) {
                throw new SadayaVerificationException('The order has no address but carries a payment link (uri): do not show it, and let Sadaya know.', 'uri');
            }
        }
        return $order;
    }

    /** sadaya-php/<major>.<minor>, taken from VERSION. */
    private static function userAgent(): string
    {
        return 'sadaya-php/' . implode('.', array_slice(explode('.', self::VERSION), 0, 2));
    }

    /** true with an sk_live_ key (real money), false with an sk_test_ key (test mode). */
    public function isLivemode(): bool
    {
        return strpos($this->apiKey, 'sk_live_') === 0;
    }

    /**
     * Creates an order. With an external_id that already exists, it returns that same order (HTTP 200 instead of 201).
     *
     * @param array<string, mixed> $params amount (decimal string, "15.00"), currency and, optionally, external_id,
     *                                     description, customer, redirect_url, expires_in_min, locale, metadata (line
     *                                     items go there: ['items' => [['name' => ..., 'quantity' => ..., 'price' => ...]]]),
     *                                     network (chain_id such as 56, or key such as "bsc") and mode (charge or deposit).
     * @return array<string, mixed> The order.
     * @throws SadayaException
     */
    public function createOrder(array $params): array
    {
        return $this->checked($this->request('POST', '/v1/orders', $params));
    }

    /**
     * Seals the order for a network (chain_id such as 56, or key such as "bsc") and returns it with its address. Only
     * once: asking for another network answers 409.
     *
     * @param int|string $network
     * @return array<string, mixed> The order.
     * @throws SadayaException
     */
    public function chooseNetwork(string $id, $network): array
    {
        if ($network === null || $network === '') {
            throw new SadayaException('Missing the network: its chain_id (56) or its key ("bsc").', 0, 'invalid_request_error');
        }
        return $this->checked($this->request('POST', '/v1/orders/' . $this->pathId($id, 'order') . '/network', ['network' => $network]));
    }

    /**
     * An order with its payments.
     *
     * @return array<string, mixed>
     * @throws SadayaException
     */
    public function getOrder(string $id): array
    {
        return $this->checked($this->request('GET', '/v1/orders/' . $this->pathId($id, 'order')));
    }

    /**
     * Orders in the key's mode, newest first.
     *
     * @param array<string, mixed> $params limit (1 to 100), status and before (ISO date or DateTimeInterface).
     * @return array<string, mixed> ['object' => 'list', 'data' => [...], 'has_more' => bool]
     * @throws SadayaException
     */
    public function listOrders(array $params = []): array
    {
        $page = $this->request('GET', '/v1/orders', null, $params);
        if ($this->verify !== null && is_array($page['data'] ?? null)) {
            foreach ($page['data'] as $order) {
                $this->checked($order);
            }
        }
        return $page;
    }

    /**
     * "Check now": looks at the chain for this order right now, like the dashboard's button and under the same limit per
     * account (429 with getRetryAfter()), and returns it with its payments. Live orders only: a test order answers 400.
     *
     * @return array<string, mixed> The order, with payments and refunds.
     * @throws SadayaException
     */
    public function checkOrder(string $id): array
    {
        return $this->checked($this->request('POST', '/v1/orders/' . $this->pathId($id, 'order') . '/check', []));
    }

    /**
     * Cancels an order that never received anything and returns it. Canceling it again returns it as it is; an order that
     * already has a payment answers 409. Money that arrives anyway counts as a late payment.
     *
     * @return array<string, mixed> The order, with payments and refunds.
     * @throws SadayaException
     */
    public function cancelOrder(string $id): array
    {
        return $this->checked($this->request('POST', '/v1/orders/' . $this->pathId($id, 'order') . '/cancel', []));
    }

    /**
     * Accepts an underpaid order as paid with what it received, and returns it: it becomes paid, received stays what
     * arrived, flags.accepted is set and you get order.paid once. Only while accepting by API is on for your account (the
     * owner turns it on in Developers; otherwise 403). Accepting it again returns it as it is; an order that is not
     * underpaid, or with a payment still confirming or held, answers 409; past the hourly limit, 429 with getRetryAfter().
     *
     * @return array<string, mixed> The order, with payments and refunds.
     * @throws SadayaException
     */
    public function acceptOrder(string $id): array
    {
        return $this->checked($this->request('POST', '/v1/orders/' . $this->pathId($id, 'order') . '/accept', []));
    }

    /**
     * Your payouts, newest first, each exactly as settlement.paid carried it. A test key gets an empty list.
     *
     * @param array<string, mixed> $params limit (1 to 100) and before (the id, stl_..., of the last one of the previous page).
     * @return array<string, mixed> ['object' => 'list', 'data' => [...], 'has_more' => bool]
     * @throws SadayaException
     */
    public function listSettlements(array $params = []): array
    {
        return $this->request('GET', '/v1/settlements', null, $params);
    }

    /**
     * One payout by its id (stl_...): the same data as its settlement.paid.
     *
     * @return array<string, mixed> The settlement.
     * @throws SadayaException
     */
    public function getSettlement(string $id): array
    {
        return $this->request('GET', '/v1/settlements/' . $this->pathId($id, 'settlement'));
    }

    /**
     * Your account's networks, the same in test and live mode: offer only these in your own network picker.
     *
     * @return array<string, mixed> ['object' => 'list', 'data' => [['id' => 56, 'name' => 'BNB Chain', 'native' => 'BNB', 'tokens' => [...], 'min_usd' => 0], ...], 'has_more' => false]
     * @throws SadayaException
     */
    public function listNetworks(): array
    {
        return $this->request('GET', '/v1/networks');
    }

    /**
     * Requests a refund. The response includes customer_url: the link where the customer leaves the wallet that
     * receives it.
     *
     * @param array<string, mixed> $params order_id and, optionally, amount (by default, everything still refundable in the
     *                                     coin the order was paid with: what arrived minus what was already refunded)
     *                                     and reason.
     * @return array<string, mixed> The refund.
     * @throws SadayaException
     */
    public function createRefund(array $params): array
    {
        return $this->request('POST', '/v1/refunds', $params);
    }

    /**
     * @return array<string, mixed> The refund.
     * @throws SadayaException
     */
    public function getRefund(string $id): array
    {
        return $this->request('GET', '/v1/refunds/' . $this->pathId($id, 'refund'));
    }

    /**
     * Refunds in the key's mode (up to 100); with ['order_id' => ...], only that order's.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> ['object' => 'list', 'data' => [...]]
     * @throws SadayaException
     */
    public function listRefunds(array $params = []): array
    {
        return $this->request('GET', '/v1/refunds', null, $params);
    }

    /**
     * Records your refund transaction: Sadaya verifies it on chain and returns the refund.
     *
     * @return array<string, mixed> The refund.
     * @throws SadayaException
     */
    public function markRefundSent(string $id, string $txHash): array
    {
        $txHash = trim($txHash);
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash)) {
            throw new SadayaException('Missing the hash of your transaction (0x and 64 hex characters).', 0, 'invalid_request_error');
        }
        return $this->request('POST', '/v1/refunds/' . $this->pathId($id, 'refund') . '/sent', ['tx_hash' => $txHash]);
    }

    /**
     * Cancels a refund that has not been sent yet.
     *
     * @return array<string, mixed> The refund.
     * @throws SadayaException
     */
    public function cancelRefund(string $id): array
    {
        return $this->request('POST', '/v1/refunds/' . $this->pathId($id, 'refund') . '/cancel', []);
    }

    /**
     * @return array<string, mixed> ['object' => 'webhook', 'url' => ..., 'secret_set' => bool]
     * @throws SadayaException
     */
    public function getWebhook(): array
    {
        return $this->request('GET', '/v1/webhook');
    }

    /**
     * Sets the webhook URL (https). The secret (whsec_...) only comes in the response when the webhook is created or
     * rotated: store it, it is not shown again.
     *
     * @return array<string, mixed> ['object' => 'webhook', 'url' => ..., 'secret_set' => true, 'secret' => ...]
     * @throws SadayaException
     */
    public function setWebhook(string $url, bool $rotateSecret = false): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new SadayaException('Missing the webhook url (https://...).', 0, 'invalid_request_error');
        }
        $body = ['url' => $url];
        if ($rotateSecret) {
            $body['rotate_secret'] = true;
        }
        return $this->request('POST', '/v1/webhook', $body);
    }

    /**
     * Keeps var_dump() and print_r() of the client from showing the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => substr($this->apiKey, 0, 8) . '...', 'baseUrl' => $this->baseUrl, 'timeout' => $this->timeout];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @return array<string, mixed>
     * @throws SadayaException
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        $query = $this->cleanQuery($query);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . self::userAgent(),
            'Expect:',
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $this->encode($body);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        // the only response header the client reads: how long a 429 asks to wait
        $retryAfter = null;
        $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$retryAfter): int {
            if (stripos($line, 'retry-after:') === 0) {
                $retryAfter = trim(substr($line, strlen('retry-after:')));
            }
            return strlen($line);
        };

        $ch = curl_init($url);
        if ($ch === false) {
            throw new SadayaException('Could not initialize cURL.', 0, 'connection_error');
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // since PHP 8 the handle is closed automatically
        }

        if (!is_string($raw) || $curlErrno !== 0) {
            $message = $curlErrno === 28 // CURLE_OPERATION_TIMEDOUT
                ? 'The Sadaya API did not respond within ' . $this->timeout . ' s.'
                : 'Could not connect to the Sadaya API: ' . $curlError;
            throw new SadayaException($message, 0, 'connection_error');
        }

        $data = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($status < 200 || $status >= 300) {
            $apiError = $data['error'] ?? null;
            $message = '';
            $type = '';
            if (is_array($apiError)) {
                $message = isset($apiError['message']) && is_string($apiError['message']) ? $apiError['message'] : '';
                $type = isset($apiError['type']) && is_string($apiError['type']) ? $apiError['type'] : '';
            } elseif (is_string($apiError)) {
                $message = $apiError;
            }
            if ($message === '') {
                $message = 'HTTP ' . $status . ' error from the Sadaya API.';
            }
            if ($type === '') {
                $type = self::typeForStatus($status);
            }
            throw new SadayaException($message, $status, $type, $data, null, self::retryAfterSeconds($retryAfter));
        }

        if ($data === null) {
            throw new SadayaException('The Sadaya API returned a response that is not JSON.', $status, 'api_error');
        }
        return $data;
    }

    /**
     * The seconds a Retry-After header asks to wait: a whole number of seconds, or an HTTP date (counted from $now, in
     * unix seconds). null when the header is missing or cannot be read.
     */
    public static function retryAfterSeconds(?string $value, ?int $now = null): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        // the HTTP date: "Wed, 21 Oct 2015 07:28:00 GMT"
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }
        return max(0, $at->getTimestamp() - ($now ?? time()));
    }

    private static function typeForStatus(int $status): string
    {
        if ($status === 401) {
            return 'authentication_error';
        }
        if ($status === 429) {
            return 'rate_limit_error';
        }
        if ($status >= 500) {
            return 'api_error';
        }
        return 'invalid_request_error';
    }

    private function pathId(string $id, string $what): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new SadayaException('Missing the ' . $what . ' id.', 0, 'invalid_request_error');
        }
        return rawurlencode($id);
    }

    /**
     * @param array<string, mixed> $body
     * @throws SadayaException
     */
    private function encode(array $body): string
    {
        if ($body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SadayaException('Could not encode the data as JSON: ' . $e->getMessage(), 0, 'invalid_request_error', null, $e);
        }
    }

    /**
     * Drops empty values and turns dates into ISO 8601 in UTC.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function cleanQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = (new \DateTimeImmutable($value->format('Y-m-d\TH:i:s.uP')))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z');
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (!is_scalar($value)) {
                continue;
            }
            $out[(string) $key] = (string) $value;
        }
        return $out;
    }
}
