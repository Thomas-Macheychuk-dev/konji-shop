<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Pages;

use App\Enums\EditablePage;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Pages\PageContent;
use Illuminate\Contracts\View\View;

final class AdminPageIndexController extends Controller
{
    public function __invoke(): View
    {
        $stored = Page::query()->get()->keyBy('slug');

        $pages = collect(EditablePage::cases())->map(fn (EditablePage $page): array => [
            'page' => $page,
            'title' => $stored->get($page->value)?->title ?? $page->defaultTitle(),
            'customised' => PageContent::isCustomised($stored->get($page->value)),
            'updatedAt' => $stored->get($page->value)?->updated_at,
        ]);

        return view('admin.pages.index', [
            'pages' => $pages,
        ]);
    }
}
