<?php

declare(strict_types=1);

namespace Tests\Unit\Herdegen;

use App\Services\Herdegen\HerdegenDescriptionFormatter;
use App\Services\Qmed\QmedDescriptionSanitizer;
use PHPUnit\Framework\TestCase;

final class HerdegenDescriptionFormatterTest extends TestCase
{
    private function formatter(): HerdegenDescriptionFormatter
    {
        return new HerdegenDescriptionFormatter(
            new QmedDescriptionSanitizer(),
        );
    }

    public function test_removes_redundant_supplier_breaks(): void
    {
        $html = '<h2>Opis</h2>'
            .'<p><b><br>Tekst produktu<br></b></p>'
            .'<p><strong><br><br>Zastosowanie</strong></p>'
            .'<p><br><br></p>';

        $result = $this->formatter()->format($html);

        self::assertStringContainsString('<h2>Opis</h2>', $result);
        self::assertStringContainsString('<p><b>Tekst produktu</b></p>', $result);
        self::assertStringContainsString(
            '<p><strong>Zastosowanie</strong></p>',
            $result,
        );
        self::assertStringNotContainsString('<br><br>', $result);
        self::assertStringNotContainsString('<p><br>', $result);
    }

    public function test_preserves_useful_description_formatting(): void
    {
        $result = $this->formatter()->format(
            '<p>Opis <strong>produktu</strong>.</p>'
            .'<ul><li>Pierwsza cecha</li><li>Druga cecha</li></ul>'
        );

        self::assertStringContainsString(
            '<strong>produktu</strong>',
            $result,
        );
        self::assertStringContainsString(
            '<li>Pierwsza cecha</li>',
            $result,
        );
    }

    public function test_normalizes_fully_bold_long_paragraphs(): void
    {
        $paragraph = str_repeat(
            'Laska rehabilitacyjna pomaga w codziennym poruszaniu. ',
            5,
        );

        $html = '<h2>Opis</h2>'
            .'<p><strong>Francuska laska aluminiowa</strong></p>'
            .'<p><b>'.$paragraph.'</b></p>'
            .'<p>Normalny tekst z <strong>ważnym fragmentem</strong>.</p>';

        $result = $this->formatter()->format($html);

        self::assertStringContainsString(
            '<p><strong>Francuska laska aluminiowa</strong></p>',
            $result,
        );

        self::assertStringContainsString(
            '<p>'.trim($paragraph).'</p>',
            $result,
        );

        self::assertStringNotContainsString(
            '<p><b>'.$paragraph.'</b></p>',
            $result,
        );

        self::assertStringContainsString(
            '<strong>ważnym fragmentem</strong>',
            $result,
        );
    }

    public function test_removes_unsafe_supplier_markup(): void
    {
        $result = $this->formatter()->format(
            '<p>Bezpieczny opis</p>'
            .'<script>alert(1)</script>'
            .'<img src=x onerror=alert(1)>'
        );

        self::assertStringContainsString('Bezpieczny opis', $result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('<img', $result);
        self::assertStringNotContainsString('onerror', $result);
    }
}
