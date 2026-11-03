<?php

declare(strict_types=1);

namespace App\Services\Rental;

use App\Models\RentalItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RentalItemImageStorage
{
    /**
     * Names the file after the item for image search; the random suffix keeps a
     * replaced photo from being served from a stale cache.
     */
    public function store(UploadedFile $image, string $slug): string
    {
        $extension = $image->extension() ?: $image->guessExtension() ?: 'jpg';

        return $image->storeAs(
            RentalItem::UPLOADED_IMAGE_DIRECTORY,
            $slug.'-'.Str::lower(Str::random(6)).'.'.$extension,
            'public',
        );
    }

    /**
     * Removes an uploaded image. Seeded images belong to the repository and stay.
     */
    public function delete(RentalItem $item): void
    {
        if (blank($item->image_path) || $item->hasBundledImage()) {
            return;
        }

        Storage::disk('public')->delete($item->image_path);
    }
}
