<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Withdrawals;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequestItemPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminWithdrawalItemPhotoShowController extends Controller
{
    public function __invoke(WithdrawalRequestItemPhoto $photo): StreamedResponse
    {
        $disk = Storage::disk($photo->disk);

        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, $photo->original_name ?: basename($photo->path));
    }
}
