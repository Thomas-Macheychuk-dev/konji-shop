<?php

declare(strict_types=1);

namespace App\Services\Security;

final class IpRangeMatcher
{
    public function contains(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefixLengthRaw] = explode('/', $cidr, 2);

        if ($network === '' || $prefixLengthRaw === '' || ! ctype_digit($prefixLengthRaw)) {
            return false;
        }

        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);

        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefixLength = (int) $prefixLengthRaw;
        $totalBits = strlen($ipBinary) * 8;

        if ($prefixLength < 0 || $prefixLength > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
