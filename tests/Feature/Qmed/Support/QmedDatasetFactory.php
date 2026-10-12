<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed\Support;

final class QmedDatasetFactory
{
    public static function make(int $count = 156): array
    {
        $products = [];
        for ($index = 0; $index < $count; $index++) {
            $url = sprintf('https://qmedinfo.pl/pl/oferta/szyja/test-product-%03d', $index);
            $products[] = [
                'source' => 'qmed',
                'source_url' => $url,
                'external_id' => 'qmed-'.hash('sha256', $url),
                'name' => 'Qmed test product '.$index,
                'description_html' => '<p>Source manufacturer description '.$index.'</p>',
                'status' => 'draft',
                'sku' => null,
                'price_gross_amount' => null,
                'variant_candidates' => [],
                'variants_unresolved' => true,
                'categories' => [['path' => '/pl/oferta/szyja', 'name' => 'Odcinek szyjny']],
                'technical_tables' => [],
                'attachments' => [],
                'images' => [
                    ['url' => sprintf('https://qmedinfo.pl/images/test-%03d.jpg', $index), 'role' => 'main'],
                ],
            ];
        }

        return ['source' => 'qmed', 'failed_urls' => [], 'products' => $products];
    }
}
