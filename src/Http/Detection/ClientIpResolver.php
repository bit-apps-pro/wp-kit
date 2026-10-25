<?php

namespace BitApps\WPKit\Http\Detection;

/**
 * Resolves the client IP, honouring X-Forwarded-For only when supplied by configured trusted proxies.
 */
final class ClientIpResolver
{
    private static array $trustedProxies = [];

    /**
     * Set proxy addresses or CIDR ranges that may supply X-Forwarded-For.
     *
     * @param array $proxies
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    /**
     * Check ip address.
     *
     * @return string IP address of current visitor
     */
    public static function checkIP(): string|false
    {
        $remoteAddress = self::normalizeIP($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddress === false || !self::isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $forwardedFor = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])
            : [];

        for ($index = \count($forwardedFor) - 1; $index >= 0; --$index) {
            $forwardedAddress = self::normalizeIP($forwardedFor[$index]);
            if ($forwardedAddress === false) {
                return $remoteAddress;
            }

            if (!self::isTrustedProxy($forwardedAddress)) {
                return $forwardedAddress;
            }
        }

        return $remoteAddress;
    }

    private static function normalizeIP($ip): string|false
    {
        $ip = trim((string) $ip, " \t\n\r\0\x0B\"");

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $matches)) {
            $ip = $matches[1];
        } elseif (preg_match('/^([^:]+):\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP);
    }

    private static function isTrustedProxy(string $ip): bool
    {
        foreach (self::$trustedProxies as $trustedProxy) {
            if (self::isIpInRange($ip, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    private static function isIpInRange(string $ip, $range): bool
    {
        $range = trim((string) $range);
        if (!str_contains($range, '/')) {
            return $ip === self::normalizeIP($range);
        }

        [$subnet, $prefixLength] = explode('/', $range, 2);
        $ipBinary                = inet_pton($ip);
        $subnetBinary            = inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || \strlen($ipBinary) !== \strlen($subnetBinary)) {
            return false;
        }

        $maximumPrefixLength = \strlen($ipBinary) * 8;
        if (!ctype_digit($prefixLength) || (int) $prefixLength > $maximumPrefixLength) {
            return false;
        }

        $prefixLength  = (int) $prefixLength;
        $fullBytes     = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (\ord($ipBinary[$fullBytes]) & $mask) === (\ord($subnetBinary[$fullBytes]) & $mask);
    }
}
