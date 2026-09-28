<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;

final class ProductLegalDisclosures
{
    public const VERSION = '2026-09-24';

    public const MEASUREMENT_NOTICE = 'Dobór rozmiaru: przed zakupem wykonaj pomiary zgodnie z instrukcją producenta i dobierz właściwy rozmiar na podstawie tabeli. Po otrzymaniu przesyłki sprawdź model, rozmiar i klasę kompresji na opakowaniu przed naruszeniem zabezpieczenia higienicznego.';

    public const HYGIENIC_SEAL_NOTICE = 'Ważna informacja dotycząca zwrotu: ten produkt jest wysyłany z zabezpieczeniem higienicznym. Po naruszeniu zabezpieczenia prawo odstąpienia od umowy nie przysługuje, jeżeli po otwarciu Towaru nie można zwrócić ze względu na ochronę zdrowia lub ze względów higienicznych. Podstawa: art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta.';

    public const COMPRESSION_HYGIENIC_SEAL_NOTICE = 'Ważna informacja dotycząca zwrotu: produkt ma bezpośredni kontakt ze skórą i przed wysyłką jest zabezpieczany plombą higieniczną. Po naruszeniu zabezpieczenia prawo odstąpienia od umowy nie przysługuje, jeżeli ze względów ochrony zdrowia lub higieny produkt nie może zostać ponownie wprowadzony do obrotu. Podstawa: art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta.';

    public const CUSTOM_MADE_NOTICE = 'Towar indywidualny / na specyfikację klienta: na podstawie art. 38 ust. 1 pkt 3 ustawy o prawach konsumenta prawo odstąpienia od umowy nie przysługuje w odniesieniu do rzeczy nieprefabrykowanej, wyprodukowanej według specyfikacji klienta lub służącej zaspokojeniu jego zindywidualizowanych potrzeb.';

    /**
     * @return list<array{key: string, title: string, body: string}>
     */
    public static function forProduct(Product $product): array
    {
        $disclosures = [];

        if ($product->show_compression_measurement_notice) {
            $disclosures[] = [
                'key' => 'compression_measurement',
                'title' => 'Dobór rozmiaru',
                'body' => self::MEASUREMENT_NOTICE,
            ];
        }

        if ($product->has_hygienic_seal) {
            $disclosures[] = [
                'key' => 'hygienic_seal',
                'title' => 'Zabezpieczenie higieniczne',
                'body' => $product->show_compression_measurement_notice
                    ? self::COMPRESSION_HYGIENIC_SEAL_NOTICE
                    : self::HYGIENIC_SEAL_NOTICE,
            ];
        }

        if ($product->is_custom_made) {
            $disclosures[] = [
                'key' => 'custom_made',
                'title' => 'Towar indywidualny',
                'body' => self::CUSTOM_MADE_NOTICE,
            ];
        }

        return $disclosures;
    }

    /**
     * @return array{
     *     version: string,
     *     has_hygienic_seal: bool,
     *     is_custom_made: bool,
     *     show_compression_measurement_notice: bool,
     *     disclosures: list<array{key: string, title: string, body: string}>
     * }
     */
    public static function snapshot(Product $product): array
    {
        return [
            'version' => self::VERSION,
            'has_hygienic_seal' => (bool) $product->has_hygienic_seal,
            'is_custom_made' => (bool) $product->is_custom_made,
            'show_compression_measurement_notice' => (bool) $product->show_compression_measurement_notice,
            'disclosures' => self::forProduct($product),
        ];
    }
}
