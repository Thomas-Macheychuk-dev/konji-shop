<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedSemanticEvidenceService;
use PHPUnit\Framework\TestCase;

final class QmedSemanticEvidenceServiceTest extends TestCase
{
    public function test_html_boundaries_and_side_semantics(): void
    {
        $service = new QmedSemanticEvidenceService();
        $cases = [
            ['<strong>ROZMIAR</strong><span>Uniwersalny</span>', 'EXPLICIT_UNIVERSAL_SIZE', 'NO_SIDE_CONFIGURATION_EVIDENCE'],
            ['<p>Uniwersalne zaopatrzenie dla prawej i lewej kończyny.</p>', 'BILATERAL_COMPATIBILITY', 'BILATERAL_COMPATIBILITY'],
            ['<p>Rozmiar uniwersalny, strona prawa i lewa.</p>', 'EXPLICIT_SIDE_VERSIONS', 'EXPLICIT_SIDE_VERSIONS'],
            ['<p>Uniwersalne zaopatrzenie prawej i lewej kończyny.</p>', 'BILATERAL_COMPATIBILITY', 'BILATERAL_COMPATIBILITY'],
            ['<p>WYRÓB WYKONANY NA ZAMÓWIENIE.</p>', 'EXPLICIT_CUSTOM_ORDER', 'NO_SIDE_CONFIGURATION_EVIDENCE'],
            ['<p>W zestawie pięć różnych rozmiarów, dostępna wersja prawa i lewa.</p>', 'SET_CONTAINS_MULTIPLE_SIZES', 'EXPLICIT_SIDE_VERSIONS'],
        ];
        foreach ($cases as [$html, $category, $sideStatus]) {
            $result = $service->classify($html);
            self::assertContains($category, $result['evidence_categories']);
            self::assertSame($sideStatus, $result['side_evidence_status']);
            self::assertFalse($result['commercially_verified']);
            self::assertSame([], $result['proposed_variants']);
        }
    }
}
