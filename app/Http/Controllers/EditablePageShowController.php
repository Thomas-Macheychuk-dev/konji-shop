<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EditablePage;
use App\Support\Pages\PageContent;
use Illuminate\Contracts\View\View;

/**
 * Renders an information page with the texts edited in "Edytor stron". The
 * route passes the page through its `editablePage` default.
 */
final class EditablePageShowController extends Controller
{
    public function __invoke(string $editablePage): View
    {
        $page = EditablePage::from($editablePage);
        $content = PageContent::for($page);

        return view($page->view(), [
            'page' => $content,
            'seoTitle' => $content->seoTitle,
            'seoDescription' => $content->seoDescription,
        ]);
    }
}
