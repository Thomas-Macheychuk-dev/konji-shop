<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RentalItem;
use Illuminate\Database\Seeder;

/**
 * The rental offer from the legacy ortezka.pl "Wypożyczalnia sprzętu medycznego" page (November 2026).
 * Insert-only: rows that already exist keep any prices or texts changed since, so it is safe to rerun.
 */
class RentalItemSeeder extends Seeder
{
    public const ITEMS = [
        // slug, group, name, details, deposit [min, max] or null, monthly [min, max], NFZ purchase, image
        ['lozko-rehabilitacyjne-manualne', 'beds', 'Łóżko rehabilitacyjne manualne', 'Regulacja korbami, leże 95 × 195 cm.', null, [80, null], false, 'lozko-manualne'],
        ['lozko-rehabilitacyjne-elektryczne-2-segmenty', 'beds', 'Łóżko rehabilitacyjne elektryczne', 'Sterowanie elektryczne, dwa poziomy regulacji, leże 100 × 200 cm. Modele AKS RehaBed, Invacare lub Scandinavian Mobility.', null, [100, null], false, 'lozko-elektryczne-2-poziomy'],
        ['lozko-rehabilitacyjne-elektryczne-3-funkcyjne', 'beds', 'Łóżko rehabilitacyjne elektryczne 3-funkcyjne', 'Sterowanie pilotem, trzy poziomy regulacji, leże 105 × 205 cm.', null, [125, null], false, 'lozko-elektryczne-3-funkcyjne'],
        ['lozko-rehabilitacyjne-elektryczne-200-kg', 'beds', 'Łóżko rehabilitacyjne elektryczne 3-funkcyjne do 200 kg', 'Sterowanie pilotem, trzy poziomy regulacji, wzmocniona konstrukcja do 200 kg, leże 100 × 200 cm.', null, [150, null], false, 'lozko-elektryczne-200-kg'],
        ['lozko-rehabilitacyjne-elektryczne-4-segmenty', 'beds', 'Łóżko rehabilitacyjne elektryczne z funkcją przechyłu', 'Sterowanie elektryczne, cztery poziomy regulacji i przechył leża, do pielęgnacji i rehabilitacji pacjenta. Leże 105 × 205 cm.', null, [150, null], false, 'lozko-elektryczne-4-poziomy'],

        ['koncentrator-tlenu-aeroplus', 'oxygen', 'Koncentrator tlenu Aeroplus', 'Model Aeroplus 6 lub Aeroplus 600.', [250, null], [80, null], false, 'koncentrator-aeroplus'],
        ['koncentrator-tlenu-devilbiss-inva', 'oxygen', 'Koncentrator tlenu DeVilbiss / Invacare', 'Model DeVilbiss 515 lub Invacare Inva 5.', [400, null], [100, null], false, 'koncentrator-devilbiss'],
        ['koncentrator-tlenu-krober-o2', 'oxygen', 'Cichy koncentrator tlenu Kröber O2', null, [600, null], [180, null], false, 'koncentrator-krober'],
        ['koncentrator-tlenu-everflo', 'oxygen', 'Cichy koncentrator tlenu Philips Respironics EverFlo', 'Wydajność do 5 l/min.', [500, null], [130, null], false, 'koncentrator-everflo'],
        ['koncentrator-tlenu-perfecto2', 'oxygen', 'Cichy koncentrator tlenu Invacare Perfecto2', 'Wydajność do 5 l/min.', [500, null], [130, null], false, 'koncentrator-perfecto'],

        ['podnosnik-pacjenta', 'transfer', 'Podnośnik pacjenta', 'Wzmocniona konstrukcja do 175 kg. Ułatwia przeniesienie pacjenta na łóżko lub wózek inwalidzki podczas codziennej pielęgnacji.', [1000, null], [250, null], false, 'podnosnik-pacjenta'],
        ['schodolaz-z-krzeslem', 'transfer', 'Schodołaz z krzesłem', 'Na wyjazdy i jednorazowe wydarzenia.', [2000, null], [350, null], false, 'schodolaz-z-krzeslem'],
        ['schodolaz-do-wozka', 'transfer', 'Schodołaz do wózka inwalidzkiego', 'Na wyjazdy i jednorazowe wydarzenia.', [1500, null], [250, null], false, 'schodolaz-z-wozkiem'],

        ['wozek-inwalidzki-standardowy', 'wheelchairs', 'Wózek inwalidzki standardowy', 'Szerokość siedziska 41–52 cm.', [100, 150], [35, 50], true, 'wozek-standardowy'],
        ['wozek-inwalidzki-ultralekki', 'wheelchairs', 'Wózek inwalidzki ultralekki z hamulcami', 'Szerokość siedziska 32–42 cm.', [250, null], [100, null], true, 'wozek-ultralekki'],
        ['wozek-inwalidzki-regulowany-podnozek', 'wheelchairs', 'Wózek inwalidzki z regulacją kąta podnóżka', 'Dla osób ze złamaniami kończyn dolnych.', [300, null], [100, null], false, 'wozek-regulowany-podnozek'],
        ['wozek-inwalidzki-dzieciecy', 'wheelchairs', 'Aktywny wózek inwalidzki dziecięcy', 'Cena zależy od modelu.', [200, 500], [75, 125], true, 'wozek-dzieciecy'],
        ['wozek-inwalidzki-aktywny', 'wheelchairs', 'Aktywny wózek inwalidzki ultralekki', null, [500, null], [250, null], true, 'wozek-aktywny'],

        ['rollator', 'mobility', 'Rollator składany', 'Ułatwia spacery i poruszanie się poza domem.', [150, null], [35, null], true, 'rollator'],
        ['balkonik-rehabilitacyjny', 'mobility', 'Balkonik rehabilitacyjny', 'Ułatwia poruszanie się po domu.', [100, null], [35, null], true, 'balkonik'],
        ['kula-lokciowa', 'mobility', 'Kula łokciowa z regulacją wysokości', 'Cena za 1 sztukę.', [30, null], [15, null], false, 'kula-lokciowa'],
        ['kule-pachowe', 'mobility', 'Kule pachowe', 'Cena za parę.', [80, null], [40, null], false, 'kule-pachowe'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $order => [$slug, $group, $name, $details, $deposit, $monthly, $nfz, $image]) {
            RentalItem::query()->firstOrCreate(['slug' => $slug], [
                'group' => $group,
                'name' => $name,
                'details' => $details,
                'deposit_min' => $deposit[0] ?? null,
                'deposit_max' => $deposit[1] ?? null,
                'monthly_min' => $monthly[0],
                'monthly_max' => $monthly[1],
                'nfz_purchase' => $nfz,
                'image_path' => 'images/rental/'.$image.'.webp',
                'sort_order' => ($order + 1) * 10,
                'is_active' => true,
            ]);
        }
    }
}
