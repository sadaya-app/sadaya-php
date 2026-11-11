<?php

declare(strict_types=1);

namespace Sadaya;

/**
 * The order failed the verification of its address: do not show that address to the customer.
 *
 * getCheck() says what failed:
 * - route: the order does not carry its complete route, or its factory is not an official one;
 * - payout: the route does not pay your payout wallet (or yours is missing from the configuration);
 * - fee: the fee is not the agreed one or exceeds the maximum (or it is missing from the configuration);
 * - fee_recipient or recovery: it is not the Sadaya wallet you pinned;
 * - review: the review window is longer than the one you accept;
 * - networks: the checkout offers something the route does not accept;
 * - address: the address does not match the route;
 * - uri: a payment link does not pay the order's address;
 * - network: the order has no chosen network yet, so it has no address: verify it once it has one.
 * getType() is always verification_error.
 */
class SadayaVerificationException extends SadayaException
{
    private string $check;

    public function __construct(string $message, string $check)
    {
        parent::__construct($message, 0, 'verification_error');
        $this->check = $check;
    }

    /** What failed: route, payout, fee, fee_recipient, recovery, review, networks, address, uri or network. */
    public function getCheck(): string
    {
        return $this->check;
    }
}
