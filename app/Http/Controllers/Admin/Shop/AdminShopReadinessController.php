<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Services\Shop\ShopReadinessCheck;
use Illuminate\Contracts\View\View;

final class AdminShopReadinessController extends Controller
{
    public function __construct(
        private readonly ShopReadinessCheck $readinessCheck,
    ) {}

    public function __invoke(): View
    {
        $summary = $this->readinessCheck->summary();

        return view('admin.shop.readiness', [
            'ready' => $summary['ready'],
            'items' => $summary['items'],
        ]);
    }
}
