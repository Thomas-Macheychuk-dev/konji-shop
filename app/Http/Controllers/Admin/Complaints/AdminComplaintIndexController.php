<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AdminComplaintIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filter = $request->query('filter') === 'all' ? 'all' : 'open';

        $complaints = Complaint::query()
            ->with('order')
            ->when(
                $filter === 'open',
                fn ($query) => $query->awaitingResponse()->orderBy('response_due_on')->orderBy('received_at'),
                fn ($query) => $query->latest('received_at'),
            )
            ->paginate(20)
            ->withQueryString();

        return view('admin.complaints.index', [
            'complaints' => $complaints,
            'filter' => $filter,
            'awaitingCount' => Complaint::query()->awaitingResponse()->count(),
            'needsAttentionCount' => Complaint::query()->needsAttention()->count(),
        ]);
    }
}
