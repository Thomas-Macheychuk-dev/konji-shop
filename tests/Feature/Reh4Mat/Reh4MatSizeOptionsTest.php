<?php

use App\Services\Reh4Mat\Reh4MatSizeOptions;

function reh4matSizeTable(array $labels, string $header = 'Rozmiar'): string
{
    $rows = implode('', array_map(static fn (string $label): string => '<tr><td>'.$label.'</td><td>30-34 cm</td></tr>', $labels));

    return '<table><tbody><tr><td>'.$header.'</td><td>Obwód kolana</td></tr>'.$rows.'</tbody></table>';
}

it('reads sizes and the left/right note from an ATTACK PRO style description', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<p>Rozmiary</p>'.reh4matSizeTable(['S', 'M', 'L', 'XL', 'XXL'])
        .'<p><strong>Występuje jako prawy / lewy .</strong></p>'
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe(['S', 'M', 'L', 'XL', '2XL'])
        ->and($options->sided)->toBeTrue()
        ->and($options->flags)->toBe([]);
});

it('splits by side alone when the product has one universal size', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(reh4matSizeTable(['Uniwersalny']).'<p>Wyrób występuje jako prawy/lewy.</p>');

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe([])
        ->and($options->sided)->toBeTrue();
});

it('keeps a single variant for universal and X-Size products', function (string $label): void {
    $options = Reh4MatSizeOptions::fromDescription(reh4matSizeTable([$label]).'<p>Rozmiar uniwersalny na prawą i lewą nogę</p>');

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SINGLE)
        ->and($options->needsVariants())->toBeFalse()
        ->and($options->offersChoice())->toBeFalse();
})->with(['Uniwersalny', 'X-Size', 'Uniw.', 'Universalny', 'Rozmiar uniwersalny na prawą i lewą nogę']);

it('normalises asterisks and merges repeated tables', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        reh4matSizeTable(['S*', 'M', 'XXXL*'], 'Rozmiar*').reh4matSizeTable(['S', 'M', 'XXXL'], 'Rozmiar ortezy')
    );

    expect($options->sizes)->toBe(['S', 'M', '3XL']);
});

it('treats XXL and 2XL as the same size', function (): void {
    expect(Reh4MatSizeOptions::fromDescription(reh4matSizeTable(['M', 'L', 'XXL']).reh4matSizeTable(['M', 'L', '2XL']))->sizes)
        ->toBe(['M', 'L', '2XL']);
});

it('skips the BodyMap row that names the measurement columns', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<table><tr><td>ROZMIAR</td><td>Pomiar [cm]</td></tr>'
        .'<tr><td>A</td><td>B</td><td>C</td><td>T</td><td>Pokrowiec 3D Mesh™</td></tr>'
        .'<tr><td>1</td><td>27</td><td>30</td></tr><tr><td>2</td><td>30</td><td>31</td></tr></table>'
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe(['1', '2']);
});

it('skips the front/back sub-header row under the size header', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(reh4matSizeTable(['przód', 'S', 'M', '2XL']).reh4matSizeTable(['tył', 'S', 'M', '2XL']));

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe(['S', 'M', '2XL']);
});

it('accepts combined and width labels', function (): void {
    expect(Reh4MatSizeOptions::fromDescription(reh4matSizeTable(['S/SLIM', 'M/SLIM', 'S/WIDE']))->sizes)->toBe(['S/SLIM', 'M/SLIM', 'S/WIDE'])
        ->and(Reh4MatSizeOptions::fromDescription(reh4matSizeTable(['S/M', 'L/XL']))->sizes)->toBe(['S/M', 'L/XL']);
});

it('reads sizes running across the first row', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<table><tr><th>Rozmiar</th><th>S</th><th>M</th><th>L</th></tr><tr><td>Obwód</td><td>30</td><td>34</td><td>38</td></tr></table>'
    );

    expect($options->sizes)->toBe(['S', 'M', 'L']);
});

it('sends unreadable or mixed tables to review', function (array $labels, string $flag): void {
    $options = Reh4MatSizeOptions::fromDescription(reh4matSizeTable($labels));

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_REVIEW)
        ->and($options->flags)->toContain($flag)
        ->and($options->needsVariants())->toBeFalse()
        ->and($options->offersChoice())->toBeTrue();
})->with([
    'several sizes in one cell' => [['S M L XL'], 'UNREAD_SIZE_LABEL'],
    'measurement row' => [['obwód', 'S', 'M'], 'UNREAD_SIZE_LABEL'],
    'universal next to sizes' => [['Uniwersalny', 'S', 'M'], 'UNIVERSAL_AND_SIZES'],
]);

it('reads sizes written after a product code', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<table><tr><td>rozmiar</td><td>Dostępne kolory</td><td>A</td></tr>'
        .'<tr><td>FP-05-XS</td><td></td><td>31 cm</td></tr><tr><td>FP-05-S</td><td></td><td>39,5 cm</td></tr>'
        .'<tr><td>FP-05-M</td><td></td><td>46,5 cm</td></tr></table>'
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe(['XS', 'S', 'M']);
});

it('treats a coded universal size as a single size', function (): void {
    $options = Reh4MatSizeOptions::fromDescription('<table><tr><td>rozmiar</td><td>A</td></tr><tr><td>FP-04-U</td><td>5 cm</td></tr></table>');

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SINGLE);
});

it('sends two product codes in one product to review', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<table><tr><td>rozmiar</td><td>A</td></tr><tr><td>FP-09-S</td><td>15 cm</td></tr><tr><td>FP-09-M</td><td>19 cm</td></tr></table>'
        .'<table><tr><td>rozmiar</td><td>A</td></tr><tr><td>FP-09/W-S</td><td>17 cm</td></tr><tr><td>FP-09/W-M</td><td>21 cm</td></tr></table>'
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_REVIEW)
        ->and($options->flags)->toContain('SEVERAL_PRODUCT_CODES');
});

it('skips a letter sub-header row over the measurement columns', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        '<table><tr><td>ROZMIAR</td><td>Pomiar [cm]</td></tr><tr><td>A</td><td>B</td><td>C</td></tr>'
        .'<tr><td>1</td><td>27</td><td>30</td></tr><tr><td>2</td><td>30</td><td>31</td></tr><tr><td>3</td><td>33</td><td>35</td></tr></table>'
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_SPLIT)
        ->and($options->sizes)->toBe(['1', '2', '3']);
});

it('sends letter and number sizes together to review', function (): void {
    $options = Reh4MatSizeOptions::fromDescription(
        reh4matSizeTable(['S', 'M', 'L', 'XL']).reh4matSizeTable(['36', '37', '38'], 'Rozmiar obuwia')
    );

    expect($options->status)->toBe(Reh4MatSizeOptions::STATUS_REVIEW)
        ->and($options->flags)->toContain('MIXED_SIZE_SYSTEMS');
});

it('reports products without a size table', function (): void {
    expect(Reh4MatSizeOptions::fromDescription('<p>Pelota silikonowa</p>')->status)->toBe(Reh4MatSizeOptions::STATUS_NO_TABLE)
        ->and(Reh4MatSizeOptions::fromDescription(null)->status)->toBe(Reh4MatSizeOptions::STATUS_NO_TABLE);
});

it('orders sizes from small to large', function (): void {
    $sizes = ['XL', '2XL', 'S', 'M/WIDE', 'M/SLIM', 'XS', 'L', '38', '36', 'XXXL'];
    usort($sizes, static fn (string $a, string $b): int => Reh4MatSizeOptions::sortOrder($a) <=> Reh4MatSizeOptions::sortOrder($b));

    expect($sizes)->toBe(['XS', 'S', 'M/SLIM', 'M/WIDE', 'L', 'XL', '2XL', 'XXXL', '36', '38']);
});
