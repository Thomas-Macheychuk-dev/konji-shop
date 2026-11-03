<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Withdrawals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWithdrawalItemInspectionRequest;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalRequestItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class AdminWithdrawalItemInspectionUpdateController extends Controller
{
    private const PHOTO_DISK = 'local';

    public function __invoke(
        UpdateWithdrawalItemInspectionRequest $request,
        WithdrawalRequest $withdrawalRequest,
        WithdrawalRequestItem $item,
    ): RedirectResponse {
        if (! $withdrawalRequest->canBeInspected()) {
            return back()->with(
                'error',
                'Nie można zmienić oceny po zleceniu lub zakończeniu zwrotu.'
            );
        }

        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $withdrawalRequest, $item, &$storedPaths): void {
                $item->update([
                    ...$request->inspectionAttributes(),
                    'inspected_at' => now(),
                    'inspected_by_user_id' => $request->user()?->id,
                ]);

                /** @var list<UploadedFile> $photos */
                $photos = $request->file('photos', []);

                foreach ($photos as $photo) {
                    $path = $photo->store(
                        "withdrawals/{$withdrawalRequest->id}/items/{$item->id}",
                        self::PHOTO_DISK,
                    );

                    if ($path === false) {
                        throw new RuntimeException('Nie udało się zapisać zdjęcia.');
                    }

                    $storedPaths[] = $path;

                    $item->photos()->create([
                        'uploaded_by_user_id' => $request->user()?->id,
                        'disk' => self::PHOTO_DISK,
                        'path' => $path,
                        'original_name' => $photo->getClientOriginalName(),
                        'mime_type' => $photo->getMimeType(),
                        'size_bytes' => $photo->getSize() ?: null,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(self::PHOTO_DISK)->delete($path);
            }

            throw $exception;
        }

        return back()->with(
            'success',
            "Zapisano ocenę stanu pozycji {$item->product_name_snapshot}."
        );
    }
}
