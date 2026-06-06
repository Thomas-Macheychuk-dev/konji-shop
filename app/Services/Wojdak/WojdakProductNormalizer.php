<?php

declare(strict_types=1);

namespace App\Services\Wojdak;

use Illuminate\Support\Str;

final class WojdakProductNormalizer
{
    public function __construct(
        private readonly WojdakVariantBuilder $variantBuilder,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $name = $this->stringOrNull($payload['name'] ?? null) ?: 'Wojdak product';
        $externalId = $this->stringOrNull($payload['external_id'] ?? null) ?: Str::slug($name);
        $variantResult = $this->variantBuilder->build($payload);

        return [
            'external_id' => $externalId,
            'external_parent_sku' => 'WOJDAK-'.Str::upper(Str::slug($externalId, '-')),
            'name' => $name,
            'slug' => $externalId,
            'short_description_html' => $this->shortDescription($payload),
            'description_html' => $this->description($payload),
            'category_url' => $payload['category_url'] ?? null,
            'category_slug' => $payload['category_slug'] ?? null,
            'canonical_url' => $payload['canonical_url'] ?? null,
            'size_table_pdf_url' => $payload['size_table_pdf_url'] ?? null,
            'size_table_type' => $payload['size_table_type'] ?? null,
            'images' => array_values(array_unique(array_filter($payload['images'] ?? [], 'is_string'))),
            'variants' => $variantResult['variants'],
            'warnings' => $variantResult['warnings'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shortDescription(array $payload): ?string
    {
        $descriptionText = $this->stringOrNull($payload['description_text'] ?? null);

        if ($descriptionText === null) {
            return null;
        }

        return e(Str::limit($descriptionText, 220));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function description(array $payload): ?string
    {
        $blocks = [];
        $descriptionHtml = $this->stringOrNull($payload['description_html'] ?? null);

        if ($descriptionHtml !== null) {
            $blocks[] = $this->cleanHtml($descriptionHtml);
        }

        $pdfUrl = $this->stringOrNull($payload['size_table_pdf_url'] ?? null);

        if ($pdfUrl !== null) {
            $blocks[] = sprintf(
                '<h3>Tabela rozmiarów</h3><p><a href="%s" target="_blank" rel="noopener">Zobacz tabelę rozmiarową Wojdak</a></p>',
                e($pdfUrl)
            );
        }

        $blocks = array_values(array_filter($blocks, fn (?string $block): bool => is_string($block) && trim(strip_tags($block)) !== ''));

        return $blocks === [] ? null : implode('', $blocks);
    }

    private function cleanHtml(string $html): string
    {
        $html = trim($html);
        $html = preg_replace('/\sclass="[^"]*"/i', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?: $html;

        return $html;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
