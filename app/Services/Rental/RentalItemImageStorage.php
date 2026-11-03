<?php

declare(strict_types=1);

namespace App\Services\Rental;

use App\Models\RentalItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RentalItemImageStorage
{
    public function store(UploadedFile $image): string
    {
        $extension = $image->extension() ?: $image->guessExtension() ?: 'jpg';

        return $image->storeAs(
            RentalItem::UPLOADED_IMAGE_DIRECTORY,
            (string) Str::uuid().'.'.$extension,
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
