<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Pages;

use App\Enums\EditablePage;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;

final class AdminPageResetController extends Controller
{
    public function __invoke(EditablePage $editablePage): RedirectResponse
    {
        Page::query()->where('slug', $editablePage->value)->delete();

        return redirect()
            ->route('admin.pages.edit', $editablePage)
            ->with('success', 'Przywrócono domyślną treść strony.');
    }
}
