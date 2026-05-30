<?php

use Illuminate\Support\Facades\Http;

it('returns an empty list when the search query is too short', function (): void {
    Http::fake();

    $this->getJson(route('checkout.inpost-parcel-lockers', ['query' => 'w']))
        ->assertOk()
        ->assertExactJson([]);

    Http::assertNothingSent();
});

it('searches InPost parcel lockers through the current Polkurier courier point endpoint', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'id' => 'WAW01A',
                    'provider' => 'INPOST_PACZKOMAT',
                    'city' => 'Warszawa',
                    'zip' => '00-001',
                    'street' => 'Testowa 1',
                    'description' => 'Przy sklepie',
                    'available' => true,
                    'status' => 'Operating',
                    'collect' => true,
                    'visible' => true,
                    'address' => '00-001 Warszawa, Testowa 1',
                ],
                [
                    'id' => 'WAW02A',
                    'provider' => 'INPOST_PACZKOMAT',
                    'city' => 'Warszawa',
                    'zip' => '00-002',
                    'street' => 'Hidden 2',
                    'available' => true,
                    'status' => 'Operating',
                    'collect' => true,
                    'visible' => false,
                    'address' => '00-002 Warszawa, Hidden 2',
                ],
                [
                    'id' => 'WAW03A',
                    'provider' => 'INPOST_PACZKOMAT',
                    'city' => 'Warszawa',
                    'zip' => '00-003',
                    'street' => 'Unavailable 3',
                    'available' => false,
                    'status' => 'Operating',
                    'collect' => true,
                    'visible' => true,
                    'address' => '00-003 Warszawa, Unavailable 3',
                ],
            ],
        ]),
    ]);

    $this->getJson(route('checkout.inpost-parcel-lockers', ['query' => 'waw']))
        ->assertOk()
        ->assertExactJson([
            [
                'code' => 'WAW01A',
                'label' => 'WAW01A — 00-001 Warszawa, Testowa 1',
            ],
        ]);

    Http::assertSent(fn ($request): bool => $request['apimethod'] === 'get_courier_point'
        && $request['data']['couriers'] === ['INPOST_PACZKOMAT']
        && $request['data']['searchquery'] === 'waw'
        && $request['data']['functions'] === ['collect']
        && $request['data']['limit'] === 20
        && $request['data']['page'] === 1);
});

it('formats courier point addresses when Polkurier does not return a ready address field', function (): void {
    Http::fake([
        '*' => Http::response([
            'status' => 'success',
            'response' => [
                [
                    'id' => 'TOR01A',
                    'provider' => 'INPOST_PACZKOMAT',
                    'city' => 'Toruń',
                    'zip' => '87-100',
                    'street' => 'Kopernika 1',
                    'description' => 'Obok wejścia',
                    'available' => true,
                    'status' => 'Operating',
                    'collect' => true,
                    'visible' => true,
                ],
            ],
        ]),
    ]);

    $this->getJson(route('checkout.inpost-parcel-lockers', ['query' => 'tor']))
        ->assertOk()
        ->assertExactJson([
            [
                'code' => 'TOR01A',
                'label' => 'TOR01A — 87-100 Toruń, Kopernika 1, Obok wejścia',
            ],
        ]);
});
