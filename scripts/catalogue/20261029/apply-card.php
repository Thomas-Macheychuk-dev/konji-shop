<?php

declare(strict_types=1);

try {
    $target = getcwd().'/resources/views/components/storefront/product-card.blade.php';
    $source = __DIR__.'/product-card.blade.php';
    $oldHash = '01d3e354cd9cc6d503a87fb5a0dc1381b68189beccbebd96939135f2dfdd408f';
    $newHash = 'a292640a3f8068bd915c6450f3b6a2d858c0d0d899ba6058e0b539c3e904dec0';
    if (! is_file($source) || hash_file('sha256', $source) !== $newHash) {
        throw new RuntimeException('Unexpected product-card replacement file');
    }
    $currentHash = is_file($target) ? hash_file('sha256', $target) : null;
    if ($currentHash === $newHash) {
        echo "PRODUCT_CARD_ALREADY_CORRECT=1\nPRODUCT_CARD_PATCH=PASS\n";
        exit(0);
    }
    if ($currentHash !== $oldHash) {
        throw new RuntimeException('Deployed product-card differs from reviewed source; no file changed');
    }
    $backupDirectory = getcwd().'/storage/app/private/catalogue-repairs';
    umask(0077);
    if (! is_dir($backupDirectory) && ! mkdir($backupDirectory, 0700, true)) {
        throw new RuntimeException('Cannot create template backup directory');
    }
    $backup = $backupDirectory.'/product-card-before-primary-category.blade.php';
    if (is_file($backup)) {
        if (hash_file('sha256', $backup) !== $oldHash) {
            throw new RuntimeException('Existing template backup differs');
        }
    } else {
        $stream = fopen($backup, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create exclusive template backup');
        }
        try {
            $original = (string) file_get_contents($target);
            if (fwrite($stream, $original) !== strlen($original) || ! fflush($stream)) {
                throw new RuntimeException('Incomplete template backup');
            }
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new RuntimeException('Cannot flush template backup');
            }
        } finally {
            fclose($stream);
        }
    }
    if (hash_file('sha256', $backup) !== $oldHash) {
        throw new RuntimeException('Template backup checksum mismatch');
    }
    $temporary = tempnam(dirname($target), '.category-card-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary template');
    }
    try {
        $replacement = (string) file_get_contents($source);
        if (file_put_contents($temporary, $replacement) !== strlen($replacement)
            || ! chmod($temporary, 0644)
            || hash_file('sha256', $temporary) !== $newHash
            || ! rename($temporary, $target)) {
            throw new RuntimeException('Cannot atomically replace product-card template');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    echo "PRODUCT_CARD_PATCH=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'PRODUCT_CARD_PATCH=FAIL '.$exception->getMessage()."\n");
    exit(1);
}
