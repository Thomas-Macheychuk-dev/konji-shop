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
        ->and($options->sizes)->toBe(['S', 'M', 'L', 'XL', 'XXL'])
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

    expect($options->sizes)->toBe(['S', 'M', 'XXXL']);
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

it('reports products without a size table', function (): void {
    expect(Reh4MatSizeOptions::fromDescription('<p>Pelota silikonowa</p>')->status)->toBe(Reh4MatSizeOptions::STATUS_NO_TABLE)
        ->and(Reh4MatSizeOptions::fromDescription(null)->status)->toBe(Reh4MatSizeOptions::STATUS_NO_TABLE);
});

it('orders sizes from small to large', function (): void {
    $sizes = ['XL', '2XL', 'S', 'M/WIDE', 'M/SLIM', 'XS', 'L', '38', '36', 'XXXL'];
    usort($sizes, static fn (string $a, string $b): int => Reh4MatSizeOptions::sortOrder($a) <=> Reh4MatSizeOptions::sortOrder($b));

    expect($sizes)->toBe(['XS', 'S', 'M/SLIM', 'M/WIDE', 'L', 'XL', '2XL', 'XXXL', '36', '38']);
});
