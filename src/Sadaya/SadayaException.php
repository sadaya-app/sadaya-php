<?php

declare(strict_types=1);

namespace Sadaya;

/**
 * An error from the Sadaya API, from the connection or from a webhook verification.
 *
 * getStatus(): the response's HTTP status code (0 when there was no response or the error is a signature error).
 * getType(): invalid_request_error, authentication_error or rate_limit_error (sent by the API) or, when the response
 * carries no type, one set from the HTTP status (rate_limit_error, api_error, invalid_request_error), connection_error or
 * signature_error.
 * getRetryAfter(): the seconds the response's Retry-After header asks to wait (a 429 carries it), or null.
 */
class SadayaException extends \Exception
{
    private int $status;

    private string $type;

    /** @var array<string, mixed>|null */
    private ?array $body;

    private ?int $retryAfter;

    /**
     * @param array<string, mixed>|null $body       The API's full response, if it was JSON.
     * @param int|null                  $retryAfter Seconds from the response's Retry-After header, if it had one.
     */
    public function __construct(
        string $message,
        int $status = 0,
        string $type = 'api_error',
        ?array $body = null,
        ?\Throwable $previous = null,
        ?int $retryAfter = null
    ) {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->type = $type;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
    }

    /** HTTP status code; 0 when there was no response (network error, timeout) or the error is a signature error. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** The error type, for example invalid_request_error. */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * The API's full response (if it was JSON), for debugging.
     *
     * @return array<string, mixed>|null
     */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /** Seconds to wait before retrying, from the response's Retry-After header (a 429 carries it); null without one. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
