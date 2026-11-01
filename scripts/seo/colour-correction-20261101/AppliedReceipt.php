<?php

declare(strict_types=1);

final class ProductSeoAppliedReceipt
{
    public static function verify(string $directory, string $expectedSha): void
    {
        $real = realpath($directory);
        $private = realpath(storage_path('app/private'));
        if ($real === false || $private === false || ! str_starts_with($real.'/', $private.'/')) {
            throw new RuntimeException('Previous backup must exist inside private storage.');
        }
        $path = $real.'/plan-and-backup.json';
        $hashPath = $real.'/plan.sha256';
        $receiptPath = $real.'/receipt.json';
        if (! is_file($path) || ! is_file($hashPath) || ! is_file($receiptPath)
            || ! hash_equals($expectedSha, hash_file('sha256', $path))
            || ! hash_equals($expectedSha, trim(file_get_contents($hashPath)))) {
            throw new RuntimeException('Previous editorial backup checksum mismatch.');
        }
        $receipt = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
        if (($receipt['state'] ?? null) !== 'applied' || ($receipt['plan_sha256'] ?? null) !== $expectedSha) {
            throw new RuntimeException('Previous editorial repair is not applied.');
        }
        // Current target values are checked by the new repair manifest. Earlier
        // receipt integrity remains verifiable after this correction supersedes
        // two old values; running the old full-state verifier would then fail.
    }
}
