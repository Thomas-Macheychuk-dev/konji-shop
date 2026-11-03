<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Contracts\View\View;

final class AdminComplaintShowController extends Controller
{
    public function __invoke(Complaint $complaint): View
    {
        $complaint->load(['order', 'createdBy']);

        return view('admin.complaints.show', [
            'complaint' => $complaint,
        ]);
    }
}
