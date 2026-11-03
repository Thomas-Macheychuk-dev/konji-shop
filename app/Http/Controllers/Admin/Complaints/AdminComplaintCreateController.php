<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class AdminComplaintCreateController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.complaints.create');
    }
}
