<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Complaints;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreComplaintRequest;
use App\Models\Complaint;
use Illuminate\Http\RedirectResponse;

final class AdminComplaintStoreController extends Controller
{
    public function __invoke(StoreComplaintRequest $request): RedirectResponse
    {
        $complaint = Complaint::query()->create([
            ...$request->complaintAttributes(),
            'number' => Complaint::generateNumber(),
            'status' => ComplaintStatus::RECEIVED,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.complaints.show', $complaint)
            ->with('success', "Zarejestrowano reklamację {$complaint->number}. Termin odpowiedzi: {$complaint->response_due_on->format('Y-m-d')}.");
    }
}
