<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AccountDetailsShowController
{
    public function __invoke(Request $request): View
    {
        return view('pages.account.details.show', [
            'user' => $request->user(),
        ]);
    }
}
