<?php

declare(strict_types=1);

use App\Services\Neoxmed\NeoxmedCategoryResolver;

it('maps NeoxMed product families into the generic Konji orthopaedic taxonomy by slug', function (string $code, array $expected): void {
    $resolved = app(NeoxmedCategoryResolver::class)->resolve([
        'source_code' => $code,
        'external_product_id' => $code,
    ]);

    expect(array_column($resolved, 'target_slug'))->toBe($expected);
})->with([
    'shoulder' => ['B-01', ['produkty-ortopedyczne-bark-stabilizatory-ortopedyczne']],
    'knee' => ['K-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-kolanowy']],
    'elastic knee' => ['EK-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-kolanowy']],
    'ankle' => ['S-04', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-skokowy']],
    'elastic ankle' => ['ES-02', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-skokowy']],
    'walker' => ['S-05', ['walkery']],
    'elbow' => ['L-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-lokiec']],
    'elastic elbow' => ['EL-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-lokiec']],
    'wrist' => ['N-03', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-nadgarstek']],
    'elastic wrist' => ['EN-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-nadgarstek']],
    'thumb' => ['N-09', ['kciuk']],
    'hand finger fallback' => ['N-10', ['stabilizatory-ortopedyczne']],
    'spine' => ['P-02', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-kregoslup']],
    'abdomen' => ['P-30', ['brzuch']],
    'cervical spine' => ['SZ-01', ['kregoslup-szyjny']],
    'sling' => ['T-01', ['produkty-ortopedyczne-stabilizatory-ortopedyczne-temblaki']],
    'calf fallback' => ['C-01', ['stabilizatory-ortopedyczne']],
    'thigh fallback' => ['U-01', ['stabilizatory-ortopedyczne']],
]);

it('maps mixed wrist-thumb and shoulder-sling products to both relevant generic categories', function (): void {
    $resolver = app(NeoxmedCategoryResolver::class);

    expect(array_column($resolver->resolve([
        'source_code' => 'N-04',
        'external_product_id' => 'N-04',
    ]), 'target_slug'))->toBe([
        'produkty-ortopedyczne-stabilizatory-ortopedyczne-nadgarstek',
        'kciuk',
    ])->and(array_column($resolver->resolve([
        'source_code' => 'T-03',
        'external_product_id' => 'T-03',
    ]), 'target_slug'))->toBe([
        'produkty-ortopedyczne-stabilizatory-ortopedyczne-temblaki',
        'produkty-ortopedyczne-bark-stabilizatory-ortopedyczne',
    ]);
});

it('never resolves NeoxMed products to supplier-specific Pani Teresa or Sigvaris taxonomy slugs', function (): void {
    $resolver = app(NeoxmedCategoryResolver::class);
    $codes = ['B-01', 'K-03', 'N-02', 'P-06', 'SZ-01', 'T-01'];

    $slugs = collect($codes)
        ->flatMap(fn (string $code): array => array_column($resolver->resolve([
            'source_code' => $code,
            'external_product_id' => $code,
        ]), 'target_slug'))
        ->all();

    expect($slugs)
        ->not->toContain('temblaki')
        ->not->toContain('pani-teresa-medica')
        ->not->toContain('mobilis-by-sigvaris');
});
