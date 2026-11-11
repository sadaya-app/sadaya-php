<?php

declare(strict_types=1);

namespace Sadaya;

/**
 * Verifies an order's address without trusting Sadaya.
 *
 * Every order is paid to an address that is a contract: CREATE2 from the official factory, with the route sealed
 * inside (your payout wallet, the fee, the `recovery` address, what the order accepts and, since factory v3, who splits
 * the order during the review window and until when). This class recomputes that address with the same formula as the
 * contract, and checks that the route pays you and that every payment link (uri) pays that address. An address, a
 * route or a link that does not match fails verification, and your store never shows it to the customer.
 *
 * No dependencies. It needs 64-bit PHP (PHP_INT_SIZE = 8), which is the norm on any current server.
 */
final class Address
{
    /**
     * Official factories (lowercase) => the logic contract of their clones and their version: v3 and v4 seal a review
     * window, v2 sealed the earlier orders. Each remains valid for its own orders.
     */
    public const FACTORIES = [
        '0xee202bdc2108e2622a22c2ae61764239dc6c3ff4' => ['implementation' => '0x9dc74a33708e80e7d9c0eb91baf53f88ac755b8d', 'version' => 4],
        '0xcde84310ea54757b3e965897c49358d598e31404' => ['implementation' => '0x8fff0825226a4024fe23ae6155db250d25f13cee', 'version' => 3],
        '0xa6edd5ef5356cdb84b42f25c324773e27a745b80' => ['implementation' => '0xcc1ee2ca2873ed730087b65f30b34335302bc1a4', 'version' => 2],
    ];

    /**
     * The tokens that are not dollars, by network (lowercase): a coin as a token of another network (ETH on BNB Chain) and
     * the wrapped coins. A deposit's route may take one of them, or the network's own coin, only if the order lists it in
     * `networks`; the dollar stablecoins it takes one to one need not be listed. A token missing here is taken for a dollar.
     */
    private const COIN_TOKENS = [
        1 => ['0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2', '0x2260fac5e5542a773aa44fbcfedf7c193bc2c599'],
        56 => ['0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c', '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c', '0x2170ed0880ac9a755fd29b2688956bd959f933f8'],
    ];

    /** The longest review window verifyOrder accepts unless you pass another one, in days. */
    public const MAX_REVIEW_DAYS = 31;

    /** The network's native coin (ETH, BNB) in the accepted list. */
    public const NATIVE = '0x0000000000000000000000000000000000000000';

    // rotation of each Keccak lane, in x + 5y order
    private const ROT = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];

    // round constants as [high 32 bits, low 32 bits]: a 64-bit literal with the high bit set would be a float in PHP
    private const RC = [
        [0x00000000, 0x00000001], [0x00000000, 0x00008082], [0x80000000, 0x0000808a], [0x80000000, 0x80008000],
        [0x00000000, 0x0000808b], [0x00000000, 0x80000001], [0x80000000, 0x80008081], [0x80000000, 0x00008009],
        [0x00000000, 0x0000008a], [0x00000000, 0x00000088], [0x00000000, 0x80008009], [0x00000000, 0x8000000a],
        [0x00000000, 0x8000808b], [0x80000000, 0x0000008b], [0x80000000, 0x00008089], [0x80000000, 0x00008003],
        [0x80000000, 0x00008002], [0x80000000, 0x00000080], [0x00000000, 0x0000800a], [0x80000000, 0x8000000a],
        [0x80000000, 0x80008081], [0x80000000, 0x00008080], [0x00000000, 0x80000001], [0x80000000, 0x80008008],
    ];

    /* ───────── Keccak-256: Ethereum's hash (not the sha3-256 of hash()) ───────── */

    /** Keccak-256 of a byte string; returns 32 raw bytes (use bin2hex to see them in hex). */
    public static function keccak256(string $data): string
    {
        if (PHP_INT_SIZE !== 8) {
            throw new SadayaVerificationException('Address verification needs 64-bit PHP.', 'route');
        }
        $rate = 136;
        $length = strlen($data);
        $padded = $data . str_repeat("\0", $rate - ($length % $rate));
        $padded[$length] = chr(ord($padded[$length]) ^ 0x01);
        $last = strlen($padded) - 1;
        $padded[$last] = chr(ord($padded[$last]) ^ 0x80);

        $state = array_fill(0, 25, 0);
        for ($offset = 0, $total = strlen($padded); $offset < $total; $offset += $rate) {
            for ($i = 0; $i < 17; $i++) {
                // 'P' = 64-bit little-endian; with the high bit set PHP returns it as a negative number, with the same bits
                $state[$i] ^= unpack('P', substr($padded, $offset + $i * 8, 8))[1];
            }
            self::keccakF($state);
        }
        return pack('P4', $state[0], $state[1], $state[2], $state[3]);
    }

    private static function rotl(int $value, int $bits): int
    {
        if ($bits === 0) {
            return $value;
        }
        // >> in PHP copies the sign bit: the mask keeps only the bits that come in from the right
        return ($value << $bits) | (($value >> (64 - $bits)) & ~(-1 << $bits));
    }

    /** @param int[] $a */
    private static function keccakF(array &$a): void
    {
        $c = [0, 0, 0, 0, 0];
        $b = array_fill(0, 25, 0);
        for ($round = 0; $round < 24; $round++) {
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $a[$x] ^ $a[$x + 5] ^ $a[$x + 10] ^ $a[$x + 15] ^ $a[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $c[($x + 4) % 5] ^ self::rotl($c[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$x + $y] ^= $d;
                }
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = self::rotl($a[$x + 5 * $y], self::ROT[$x + 5 * $y]);
                }
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $a[$x + 5 * $y] = $b[$x + 5 * $y] ^ ((~$b[(($x + 1) % 5) + 5 * $y]) & $b[(($x + 2) % 5) + 5 * $y]);
                }
            }
            $a[0] ^= (self::RC[$round][0] << 32) | self::RC[$round][1];
        }
    }

    /* ───────── the contract's formula ───────── */

    /** The address with its checksum capitals (EIP-55), as wallets show it. */
    public static function checksum(string $address): string
    {
        $lower = strtolower(substr($address, 2));
        $hash = bin2hex(self::keccak256($lower));
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $out .= hexdec($hash[$i]) >= 8 ? strtoupper($lower[$i]) : $lower[$i];
        }
        return $out;
    }

    /** Recomputes the order's address: CREATE2(factory, order id, clone with the route as its arguments). */
    public static function depositAddressOf(array $order): string
    {
        $route = $order['route'] ?? null;
        $factory = is_array($route) ? strtolower((string) ($route['factory'] ?? '')) : '';
        if (!isset(self::FACTORIES[$factory])) {
            throw new SadayaVerificationException('The order does not carry a route with an official factory: update the library or let Sadaya know.', 'route');
        }
        // a malformed route is refused here too, before any of its values is used (never a PHP warning on the page)
        self::checkRoute($route);
        $version = self::FACTORIES[$factory]['version'];
        if (($version >= 3) !== isset($route['open_after'])) {
            throw new SadayaVerificationException('The order\'s route does not match its factory: do not show its address, and let Sadaya know.', 'route');
        }
        $args = self::routeArgs($route, $version);
        // OpenZeppelin Clones._cloneCodeWithImmutableArgs, byte for byte
        $initCode = "\x61" . pack('n', strlen($args) + 0x2d) . hex2bin('3d81600a3d39f3363d3d373d3d3d363d73')
            . self::bytes(self::FACTORIES[$factory]['implementation']) . hex2bin('5af43d82803e903d91602b57fd5bf3') . $args;
        $hash = self::keccak256("\xff" . self::bytes($factory) . self::orderId((string) ($order['id'] ?? '')) . self::keccak256($initCode));
        return self::checksum('0x' . bin2hex(substr($hash, 12)));
    }

    /**
     * Checks an order before its address is shown to the customer. Returns the order or throws
     * SadayaVerificationException. Besides the route and the address, it checks that each network's uri (when there is
     * one) pays that address, with that token, on that network and for what is still due (due_units), exactly as Sadaya
     * builds it. An order with no chosen network has no address: it throws with the check network. A deposit whose route
     * takes a coin its `networks` does not list throws with the check networks.
     *
     * @param array<string, mixed> $order    The order as the API returned it.
     * @param array<string, mixed> $expected What your store knows on its own, never what the API says:
     *                                       payout (your payout wallet, required), feeBps (your agreed fee, exact)
     *                                       or maxFeeBps (the highest fee you accept; always one of the two) and, if
     *                                       you want to pin them, feeRecipient and recovery (Sadaya's addresses in the route).
     *                                       maxReviewDays: the longest review window you accept (MAX_REVIEW_DAYS, 31,
     *                                       by default); now: the moment of the check, in unix seconds (now, by
     *                                       default).
     * @return array<string, mixed>
     */
    public static function verifyOrder(array $order, array $expected): array
    {
        if (!self::isAddress($expected['payout'] ?? null)) {
            throw new SadayaVerificationException('Missing your payout wallet (payout) to verify the order: set it in your configuration, never take it from the API.', 'payout');
        }
        // with no chosen network, the order has no address or route yet: there is nothing to show, nor to verify
        if (($order['deposit_address'] ?? null) === null && ($order['route'] ?? null) === null) {
            throw new SadayaVerificationException('The order has no chosen network yet, so it has no address: verify it once it has one.', 'network');
        }
        $route = $order['route'] ?? null;
        self::checkRoute($route);
        if (!self::same($route['merchant'], $expected['payout'])) {
            throw new SadayaVerificationException('The order does not pay your payout wallet (it pays ' . $route['merchant'] . '): do not show its address, and let Sadaya know.', 'payout');
        }
        if (!isset($expected['feeBps']) && !isset($expected['maxFeeBps'])) {
            throw new SadayaVerificationException('Missing your agreed fee (feeBps) or the highest fee you accept (maxFeeBps): without it, the fee the order charges you cannot be checked.', 'fee');
        }
        if (isset($expected['feeBps']) && $route['fee_bps'] !== (int) $expected['feeBps']) {
            throw new SadayaVerificationException('The order\'s fee (' . $route['fee_bps'] . ' basis points) is not the agreed one (' . $expected['feeBps'] . ').', 'fee');
        }
        if (isset($expected['maxFeeBps']) && $route['fee_bps'] > (int) $expected['maxFeeBps']) {
            throw new SadayaVerificationException('The order\'s fee (' . $route['fee_bps'] . ' basis points) exceeds the maximum you accept (' . $expected['maxFeeBps'] . ').', 'fee');
        }
        if (!empty($expected['feeRecipient']) && !self::same($route['fee_recipient'], $expected['feeRecipient'])) {
            throw new SadayaVerificationException('The order\'s fee wallet is not the one you pinned.', 'fee_recipient');
        }
        if (!empty($expected['recovery']) && !self::same($route['recovery'], $expected['recovery'])) {
            throw new SadayaVerificationException('The order\'s recovery address is not the one you pinned.', 'recovery');
        }
        // factories v3 and v4: the window in which only Sadaya can split the order cannot be longer than the one you accept
        if (isset($route['open_after'])) {
            $days = (int) ($expected['maxReviewDays'] ?? self::MAX_REVIEW_DAYS);
            $now = (int) ($expected['now'] ?? time());
            // in unix seconds: a value in milliseconds would let any window pass
            if ($now > 100000000000) {
                throw new SadayaVerificationException('now must be in unix seconds, not milliseconds.', 'review');
            }
            if ($route['open_after'] > $now + $days * 86400) {
                throw new SadayaVerificationException('The order\'s review window (until ' . gmdate('Y-m-d', $route['open_after']) . ') is longer than the one you accept (' . $days . ' days).', 'review');
            }
        }
        // whatever the checkout asks the customer to pay must be in the accepted list: only that is split, and only to you
        foreach ($order['networks'] ?? [] as $network) {
            $token = !empty($network['token']) ? (string) $network['token'] : self::NATIVE;
            $accepted = false;
            foreach ($route['accepted'] as $item) {
                if ((int) $item['chain_id'] === (int) $network['chain_id'] && self::same($item['token'], $token)) {
                    $accepted = true;
                    break;
                }
            }
            if (!$accepted) {
                throw new SadayaVerificationException('The order offers payment in ' . ($network['symbol'] ?? $token) . ' on network ' . $network['chain_id'] . ', which its route does not accept.', 'networks');
            }
        }
        // a deposit credits whatever arrives: its route may take a coin (the network's own, or a token that is not a dollar)
        // only if the order lists it, so your store always knows what it can be credited in at a price
        if (($order['mode'] ?? null) === 'deposit') {
            foreach ($route['accepted'] as $item) {
                $token = strtolower((string) $item['token']);
                if ($token !== self::NATIVE && !in_array($token, self::COIN_TOKENS[$item['chain_id']] ?? [], true)) {
                    continue;
                }
                $listed = false;
                foreach ($order['networks'] ?? [] as $network) {
                    $paid = !empty($network['token']) ? (string) $network['token'] : self::NATIVE;
                    if ((int) $network['chain_id'] === $item['chain_id'] && self::same($paid, $token)) {
                        $listed = true;
                        break;
                    }
                }
                if (!$listed) {
                    throw new SadayaVerificationException('The deposit\'s route takes a coin on network ' . $item['chain_id'] . ' (' . $item['token'] . ') that the order does not list: do not show its address, and let Sadaya know.', 'networks');
                }
            }
        }
        if (!self::same(self::depositAddressOf($order), (string) ($order['deposit_address'] ?? ''))) {
            throw new SadayaVerificationException('The order\'s address does not match its route: do not show it to the customer, and let Sadaya know.', 'address');
        }
        // each payment link must pay the address just verified, so a QR made from it is safe to show; a null uri is no link
        foreach ($order['networks'] ?? [] as $network) {
            if (($network['uri'] ?? null) !== null && !self::uriPays($network, (string) $order['deposit_address'])) {
                throw new SadayaVerificationException('The payment link for ' . ($network['symbol'] ?? 'the order') . ' on network ' . $network['chain_id'] . ' does not pay the order\'s address: do not show it, and let Sadaya know.', 'uri');
            }
        }
        return $order;
    }

    /**
     * Whether a network's payment link (EIP-681) is exactly the one the gateway builds: the token's transfer to the
     * address, or the network's coin sent to it, on that chain and for what is still due (due_units). Hex is compared
     * without case.
     *
     * @param array<string, mixed> $network
     */
    private static function uriPays(array $network, string $address): bool
    {
        $uri = $network['uri'] ?? null;
        if (!is_string($uri)) {
            return false;
        }
        $chain = (string) ($network['chain_id'] ?? '');
        $units = (string) ($network['due_units'] ?? '');
        $token = is_string($network['token'] ?? null) ? $network['token'] : '';
        if ($token === '' || self::same($token, self::NATIVE)) {
            return preg_match('~^ethereum:(0x[0-9a-fA-F]{40})@(\d+)\?value=(\d+)\z~', $uri, $m) === 1
                && self::same($m[1], $address) && $m[2] === $chain && $m[3] === $units;
        }
        return preg_match('~^ethereum:(0x[0-9a-fA-F]{40})@(\d+)/transfer\?address=(0x[0-9a-fA-F]{40})&uint256=(\d+)\z~', $uri, $m) === 1
            && self::same($m[1], $token) && $m[2] === $chain && self::same($m[3], $address) && $m[4] === $units;
    }

    /** @param mixed $route */
    private static function checkRoute($route): void
    {
        $ok = is_array($route)
            && self::isWallet($route['merchant'] ?? null) && self::isWallet($route['fee_recipient'] ?? null)
            && self::isWallet($route['recovery'] ?? null) && self::isAddress($route['factory'] ?? null)
            && is_int($route['fee_bps'] ?? null) && $route['fee_bps'] >= 0 && $route['fee_bps'] <= 1000
            && is_array($route['accepted'] ?? null) && count($route['accepted']) > 0 && count($route['accepted']) <= 64
            // factories v3 and v4: who splits during the window (never the zero address) and when it ends (unix seconds, uint40)
            && (!isset($route['open_after']) || (self::isWallet($route['settler'] ?? null)
                && is_int($route['open_after']) && $route['open_after'] >= 0 && $route['open_after'] < 1099511627776));
        if ($ok) {
            foreach ($route['accepted'] as $item) {
                // a network id the gateway's JSON carries exactly (up to 2^53 - 1, like the Node library): a bigger one is rounded
                // on the way and would give another address
                if (!is_array($item) || !is_int($item['chain_id'] ?? null) || $item['chain_id'] <= 0 || $item['chain_id'] > 9007199254740991 || !self::isAddress($item['token'] ?? null)) {
                    $ok = false;
                    break;
                }
            }
        }
        if (!$ok) {
            throw new SadayaVerificationException('The order does not carry its complete route (route): do not show its address, and let Sadaya know.', 'route');
        }
    }

    /**
     * What the factory seals into each clone: merchant, fee wallet, fee, `recovery` address and the accepted list (94
     * bytes, factory v2) and, in v3 and v4, who splits the order during the review window and when that window ends
     * (119 bytes).
     */
    private static function routeArgs(array $route, int $version): string
    {
        $accepted = self::word(32) . self::word(count($route['accepted']));
        foreach ($route['accepted'] as $item) {
            $accepted .= self::word((int) $item['chain_id']) . str_repeat("\0", 12) . self::bytes($item['token']);
        }
        $args = self::bytes($route['merchant']) . self::bytes($route['fee_recipient']) . pack('n', (int) $route['fee_bps'])
            . self::bytes($route['recovery']) . self::keccak256($accepted);
        if ($version < 3) {
            return $args;
        }
        // uint40 big-endian: the 5 low bytes of a 64-bit integer
        return $args . self::bytes($route['settler']) . substr(pack('J', (int) $route['open_after']), 3);
    }

    /** The order's on-chain id: its UUID as bytes32, left-padded. */
    private static function orderId(string $uuid): string
    {
        $hex = strtolower(str_replace('-', '', $uuid));
        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1) {
            throw new SadayaVerificationException('Invalid order id: ' . $uuid, 'route');
        }
        return str_repeat("\0", 16) . hex2bin($hex);
    }

    /** An unsigned number as a 32-byte word (big-endian), like abi.encode. */
    private static function word(int $value): string
    {
        return hex2bin(str_pad(dechex($value), 64, '0', STR_PAD_LEFT));
    }

    private static function bytes(string $address): string
    {
        return hex2bin(substr($address, 2));
    }

    /** @param mixed $value */
    private static function isAddress($value): bool
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/', $value) === 1;
    }

    /**
     * A wallet the contract accepts: any address except the zero address (with that one, nothing ever leaves).
     *
     * @param mixed $value
     */
    private static function isWallet($value): bool
    {
        return self::isAddress($value) && strtolower((string) $value) !== self::NATIVE;
    }

    private static function same(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }
}
