<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateComplaintRequest;
use App\Models\Complaint;
use Illuminate\Http\RedirectResponse;

final class AdminComplaintUpdateController extends Controller
{
    public function __invoke(UpdateComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $complaint->update($request->complaintAttributes());

        return redirect()
            ->route('admin.complaints.show', $complaint)
            ->with('success', "Zapisano reklamację {$complaint->number}.");
    }
}
