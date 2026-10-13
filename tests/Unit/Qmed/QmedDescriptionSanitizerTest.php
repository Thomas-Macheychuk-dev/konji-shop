<?php

declare(strict_types=1);

use App\Services\Qmed\QmedDescriptionSanitizer;

it('preserves Qmed measurement tables and Polish Unicode without inline styling', function (): void {
    $html = '<div style="color:red"><h4>Rozmiar i obwód</h4>'
        .'<table style="width:100%"><tbody><tr><td style="text-align:center">S</td>'
        .'<td>Obwód uda* (cm)</td><td>39-47</td></tr></tbody></table>'
        .'<p>Łydka: 53&lt; oraz żółć &amp; komfort</p></div>';

    $result = app(QmedDescriptionSanitizer::class)->sanitize($html);

    expect($result)->toContain('<table>', '<tbody>', '<tr>', '<td>S</td>', 'Obwód uda* (cm)', '39-47', 'Łydka: 53&lt;', 'żółć &amp; komfort')
        ->not->toContain('style=');
});

it('drops executable elements with their content and strips event handlers', function (): void {
    $html = '<p onclick="alert(1)">Bezpieczny tekst</p>'
        .'<script>alert(2)</script><svg onload="alert(3)"><text>bad-svg</text></svg>'
        .'<iframe src="https://evil.test">bad-frame</iframe>'
        .'<style>body{display:none}</style>'
        .'<form><button>bad-form</button></form>';

    $result = app(QmedDescriptionSanitizer::class)->sanitize($html);

    expect($result)->toBe('<p>Bezpieczny tekst</p>')
        ->not->toContain('alert(', 'bad-svg', 'bad-frame', 'bad-form', 'onclick');
});

it('preserves safe links but rejects dangerous URLs and attributes', function (): void {
    $html = '<a href="https://qmedinfo.pl/pl/oferta" target="_blank" onclick="x()">Katalog</a>'
        .'<a href="javascript:alert(1)">Niebezpieczny</a>'
        .'<a href="/produkty" style="color:red">Produkty</a>'
        .'<a href="//evil.test">Zewnętrzny</a>';

    $result = app(QmedDescriptionSanitizer::class)->sanitize($html);

    expect($result)->toContain('href="https://qmedinfo.pl/pl/oferta"', '<a>Niebezpieczny</a>', 'href="/produkty"', '<a>Zewnętrzny</a>')
        ->not->toContain('javascript:', 'onclick=', 'target=', 'style=', 'href="//evil');
});

it('unwraps unknown harmless elements and discards comments and unsafe attributes', function (): void {
    $result = app(QmedDescriptionSanitizer::class)->sanitize(
        '<section data-secret="1">Tekst <custom-tag title="danger">wewnętrzny</custom-tag></section><!-- hidden -->'
    );

    expect($result)->toBe('Tekst wewnętrzny');
});

it('retains two tables and textual footnotes in malformed manufacturer HTML', function (): void {
    $result = app(QmedDescriptionSanitizer::class)->sanitize(
        '<div><table><tr><td>S</td></tr></table><p>Obwód bioder 75 - 90 cm'
        .'<table><tr><td>XL</td></tr></table><p>* Mierzyć nad kolanem'
    );

    expect(substr_count($result, '<table>'))->toBe(2);
    expect($result)->toContain('75 - 90 cm', '* Mierzyć nad kolanem');
});
