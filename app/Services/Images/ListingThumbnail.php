<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Shrinks a product image for listing cards: at most 600px on the longer side (2x a card), JPEG on white.
 *
 * JPEG because the production GD build has no WebP support; product photos sit on white cards anyway.
 */
final class ListingThumbnail
{
    public const MAX_SIDE = 600;

    private const JPEG_QUALITY = 82;

    /**
     * The thumbnail's JPEG bytes, or null when the image is already small enough to use as it is.
     *
     * @throws \RuntimeException when the bytes are not an image GD can read
     */
    public function make(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new \RuntimeException('Not an image GD can read.');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::MAX_SIDE && $height <= self::MAX_SIDE) {
            imagedestroy($source);

            return null;
        }

        $scale = self::MAX_SIDE / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $jpeg;
    }

    public static function pathFor(string $originalPath): string
    {
        return 'thumbnails/'.self::MAX_SIDE.'/'.preg_replace('/\.[A-Za-z0-9]+$/', '', ltrim($originalPath, '/')).'.jpg';
    }
}
