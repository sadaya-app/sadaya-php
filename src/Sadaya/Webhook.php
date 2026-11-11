<?php

declare(strict_types=1);

namespace Sadaya;

/**
 * Verification of Sadaya webhooks.
 *
 * Every webhook carries the X-Sadaya-Signature header: t=<Unix seconds>,v1=<HMAC-SHA256 in hex>, computed with the
 * webhook secret (whsec_...) over "<t>.<raw body>".
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Sadaya-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies a webhook's signature and returns the event as an associative array.
     *
     * @param string   $payload         The raw body: file_get_contents('php://input'), never json_encode($_POST).
     * @param string   $signatureHeader The X-Sadaya-Signature header ($_SERVER['HTTP_X_SADAYA_SIGNATURE'] ?? '').
     * @param string   $secret          The full webhook secret, with its whsec_ prefix.
     * @param int      $tolerance       Seconds allowed between the signature and your clock (300 by default).
     * @param int|null $now             Current time in Unix seconds (time() by default).
     * @return array<string, mixed> The event: id, type, created_at, livemode and data.
     * @throws SadayaException If the header is missing, the signature does not match or has expired, or the body is not a JSON event.
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        #[\SensitiveParameter]
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): array {
        if ($secret === '') {
            throw self::fail('Missing the webhook secret (whsec_...): copy it from the dashboard or from the POST /v1/webhook response.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw self::fail('The X-Sadaya-Signature header is missing or does not have the format t=<seconds>,v1=<signature>.');
        }

        // HMAC-SHA256 with the secret over "<t>.<raw body>", compared in constant time
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw self::fail('The signature does not match: check the webhook secret and that you pass the raw body, without json_decode or json_encode.');
        }

        $age = abs(($now ?? time()) - (int) $timestamp);
        if ($age > $tolerance) {
            throw self::fail(
                'The signature has expired: it is ' . $age . ' s away from your clock (at most ' . $tolerance . ' s). If this keeps happening, check your server\'s clock.'
            );
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['type']) || !is_string($event['type'])) {
            throw self::fail('The webhook body is not a valid JSON event.');
        }
        return $event;
    }

    private static function fail(string $message): SadayaException
    {
        return new SadayaException($message, 0, 'signature_error');
    }
}
