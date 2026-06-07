<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Services\Shop\ShopSettings;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __construct(
        private readonly ShopSettings $shopSettings,
    ) {}

    public function __invoke(): View
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereNotNull('slug')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        $seoTitle = 'Odzież medyczna, stabilizatory ortopedyczne i produkty do regeneracji';
        $seoDescription = 'Kupuj odzież medyczną, stabilizatory, ortezy i produkty do regeneracji z dostawą na terenie Polski.';
        $canonicalUrl = route('home');

        return view('pages.home', [
            'categories' => $categories,
            'deals' => collect(),
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'canonicalUrl' => $canonicalUrl,
            'openGraphTitle' => $seoTitle,
            'openGraphDescription' => $seoDescription,
            'openGraphType' => 'website',
            'structuredData' => [
                $this->websiteStructuredData($canonicalUrl),
                $this->organizationStructuredData($canonicalUrl),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteStructuredData(string $canonicalUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->shopSettings->shopName(),
            'url' => $canonicalUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationStructuredData(string $canonicalUrl): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->shopSettings->companyName(),
            'url' => $canonicalUrl,
            'email' => $this->shopSettings->email() ?: null,
            'telephone' => $this->shopSettings->phone() ?: null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
