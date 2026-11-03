<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Pages;

use App\Enums\EditablePage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Support\Pages\PageContent;
use Illuminate\Http\RedirectResponse;

final class AdminPageUpdateController extends Controller
{
    public function __invoke(UpdatePageRequest $request, EditablePage $editablePage): RedirectResponse
    {
        PageContent::save($editablePage, $request->validated());

        return back()->with('success', 'Strona zaktualizowana.');
    }
}
