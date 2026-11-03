<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Pages;

use App\Enums\EditablePage;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Pages\PageContent;
use Illuminate\Contracts\View\View;

final class AdminPageEditController extends Controller
{
    public function __invoke(EditablePage $editablePage): View
    {
        return view('admin.pages.edit', [
            'editablePage' => $editablePage,
            'content' => PageContent::for($editablePage),
            'customised' => PageContent::isCustomised(
                Page::query()->where('slug', $editablePage->value)->first()
            ),
        ]);
    }
}
