<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Item-page texts for the rental offer seeded by {@see RentalItemSeeder}.
 * Each description is long enough to pass RentalItem::MIN_INDEXABLE_WORDS and
 * says how the item differs from its neighbours, so the pages are not
 * near-duplicates. Prices stay out of the text: they are shown from the
 * price fields and change independently.
 */
final class RentalItemContent
{
    /** @var array<string, array{seo_title: string, seo_description: string, description: string}> */
    public const ITEMS = [
        'lozko-rehabilitacyjne-manualne' => [
            'seo_title' => 'Łóżko rehabilitacyjne manualne – wynajem Poznań',
            'seo_description' => 'Wypożycz manualne łóżko rehabilitacyjne w Poznaniu. Regulacja korbami, leże 95 × 195 cm. Dostawa, wniesienie i montaż w domu pacjenta.',
            'description' => <<<'HTML'
                <h2>Dla kogo jest to łóżko</h2>
                <p>Manualne łóżko rehabilitacyjne to najprostsze i najtańsze rozwiązanie dla osoby, która przez kilka tygodni lub miesięcy spędza w łóżku większą część dnia – po operacji, po urazie albo w czasie choroby. Sprawdza się w domu, w którym opiekun jest na miejscu i może sam ustawić pozycję pacjenta.</p>
                <h2>Regulacja i wymiary</h2>
                <p>Segmenty leża ustawia się korbami. Uniesione oparcie pleców daje pozycję półsiedzącą, w której łatwiej jeść, czytać i rozmawiać; w zależności od modelu można też zmienić ułożenie nóg. Leże ma wymiary 95 × 195 cm, więc łóżko zmieści się w większości sypialni. Korby nie potrzebują prądu, dlatego łóżko działa także przy przerwie w zasilaniu i nie wymaga gniazdka w pobliżu.</p>
                <h2>Czym różni się od łóżek elektrycznych</h2>
                <p>Każdą zmianę pozycji wykonuje opiekun, obracając korbę – pacjent nie zmieni jej samodzielnie. Jeśli ułożenie ma się zmieniać wiele razy dziennie albo opiekun ma ograniczone siły, wygodniejsze będzie łóżko elektryczne sterowane pilotem.</p>
                <h2>Jak wygląda wypożyczenie</h2>
                <p>Łóżko przywozimy, wnosimy i składamy na miejscu. Wynajem trwa tyle, ile potrzebujesz: po zakończeniu okresu możesz przedłużyć umowę albo oddać sprzęt. Do łóżka warto dobrać materac przeciwodleżynowy – zapytaj o niego przy rezerwacji.</p>
                HTML,
        ],
        'lozko-rehabilitacyjne-elektryczne-2-segmenty' => [
            'seo_title' => 'Łóżko rehabilitacyjne elektryczne – wynajem Poznań',
            'seo_description' => 'Wynajem elektrycznego łóżka rehabilitacyjnego w Poznaniu: AKS RehaBed, Invacare lub Scandinavian Mobility. Leże 100 × 200 cm, dostawa i montaż.',
            'description' => <<<'HTML'
                <h2>Dla kogo</h2>
                <p>Elektryczne łóżko rehabilitacyjne to dobry wybór, gdy pozycję pacjenta trzeba zmieniać kilka razy dziennie: przy dłuższej rekonwalescencji, w opiece nad osobą starszą albo leżącą po udarze. Silniki wykonują pracę, którą w łóżku manualnym opiekun wykonuje korbą, więc zmiana ułożenia jest szybsza i mniej męcząca. Pacjent, który sprawnie posługuje się rękami, może zmienić pozycję sam.</p>
                <h2>Regulacja i wymiary</h2>
                <p>Łóżko ma dwa elektrycznie sterowane zakresy regulacji, które pozwalają unieść oparcie do pozycji półsiedzącej i zmienić ułożenie nóg. Leże 100 × 200 cm jest nieco większe niż w łóżku manualnym i daje więcej miejsca osobie wyższej. Wypożyczamy modele AKS RehaBed, Invacare lub Scandinavian Mobility – o egzemplarzu dostępnym w danym momencie poinformujemy przy rezerwacji.</p>
                <h2>Kiedy wybrać inny model</h2>
                <p>Jeśli przy pielęgnacji przyda się regulacja wysokości całego leża, sprawdź łóżko 3-funkcyjne. Dla pacjentów o większej masie ciała mamy wersję ze wzmocnioną konstrukcją do 200 kg, a przy pielęgnacji wymagającej zmiany kąta całego leża – łóżko z funkcją przechyłu.</p>
                <h2>Wypożyczenie</h2>
                <p>Dostarczamy łóżko pod wskazany adres w Poznaniu i okolicach, wnosimy je i przygotowujemy do pracy. Okres wynajmu ustalasz sam i możesz go przedłużyć, kiedy zajdzie taka potrzeba.</p>
                HTML,
        ],
        'lozko-rehabilitacyjne-elektryczne-3-funkcyjne' => [
            'seo_title' => 'Łóżko rehabilitacyjne 3-funkcyjne – wynajem Poznań',
            'seo_description' => 'Wypożycz elektryczne łóżko rehabilitacyjne 3-funkcyjne sterowane pilotem, z regulacją wysokości. Leże 105 × 205 cm. Dostawa i montaż w Poznaniu.',
            'description' => <<<'HTML'
                <h2>Dla kogo</h2>
                <p>Łóżko 3-funkcyjne wybierają najczęściej rodziny, które przez dłuższy czas opiekują się w domu osobą leżącą. Trzy zakresy regulacji sterowane pilotem pozwalają ustawić nie tylko pozycję pacjenta, lecz także wygodną dla opiekuna wysokość pracy. Przy myciu, zmianie pościeli, opatrunkach czy karmieniu nie trzeba się pochylać, co odciąża kręgosłup osoby, która codziennie zajmuje się chorym.</p>
                <h2>Regulacja</h2>
                <p>Pilotem unosi się oparcie pleców, zmienia ułożenie nóg i reguluje wysokość leża. Obniżone łóżko ułatwia bezpieczne siadanie na brzegu i przesiadanie na wózek inwalidzki, a podniesione – czynności pielęgnacyjne. Pilot może trzymać pod ręką także sam pacjent, jeśli jest w stanie z niego korzystać.</p>
                <h2>Wymiary</h2>
                <p>Leże 105 × 205 cm należy do największych w naszej ofercie, dlatego łóżko dobrze sprawdzi się u osób wysokich. Przed dostawą sprawdź, czy w pokoju będzie dostęp do łóżka przynajmniej z jednego dłuższego boku – najlepiej z obu.</p>
                <h2>Wypożyczenie</h2>
                <p>Przywozimy łóżko, wnosimy je i przygotowujemy do użycia. Wynajem możesz przedłużać albo zakończyć w dogodnym momencie. Zapytaj też o materac przeciwodleżynowy, jeśli pacjent będzie leżał przez większą część doby.</p>
                HTML,
        ],
        'lozko-rehabilitacyjne-elektryczne-200-kg' => [
            'seo_title' => 'Łóżko rehabilitacyjne do 200 kg – wynajem Poznań',
            'seo_description' => 'Wynajem wzmocnionego łóżka rehabilitacyjnego do 200 kg w Poznaniu. Trzy funkcje sterowane pilotem, leże 100 × 200 cm, dostawa i montaż.',
            'description' => <<<'HTML'
                <h2>Dla kogo</h2>
                <p>To łóżko powstało z myślą o pacjentach, dla których standardowe łóżko rehabilitacyjne ma zbyt małą nośność. Wzmocniona konstrukcja przenosi obciążenie do 200 kg, dzięki czemu osoba o większej masie ciała może bezpiecznie leżeć, siadać i zmieniać pozycję, a opiekun nie musi obawiać się o stabilność ramy przy codziennej pielęgnacji.</p>
                <h2>Regulacja</h2>
                <p>Łóżko ma trzy funkcje sterowane pilotem: podnoszenie oparcia pleców, zmianę ułożenia nóg i regulację wysokości leża. Regulacja wysokości jest tu szczególnie przydatna – przy obniżonym leżu łatwiej usiąść i wstać, a przy podniesionym opiekun może pracować bez pochylania się, co ma znaczenie przy obracaniu i myciu cięższego pacjenta.</p>
                <h2>Na co zwrócić uwagę</h2>
                <p>Leże ma wymiary 100 × 200 cm. Do masy pacjenta dolicza się zwykle także materac i akcesoria, dlatego jeśli masa ciała zbliża się do granicy nośności, zadzwoń – pomożemy ocenić, czy ten model będzie odpowiedni.</p>
                <h2>Wypożyczenie</h2>
                <p>Łóżko dostarczamy w Poznaniu i okolicach, wnosimy je i przygotowujemy do pracy. Okres wynajmu dopasujesz do potrzeb i możesz go przedłużyć w każdej chwili.</p>
                HTML,
        ],
        'lozko-rehabilitacyjne-elektryczne-4-segmenty' => [
            'seo_title' => 'Łóżko rehabilitacyjne z przechyłem – wynajem Poznań',
            'seo_description' => 'Wypożycz łóżko rehabilitacyjne z przechyłem leża i czterema zakresami regulacji. Leże 105 × 205 cm. Dostawa i montaż w Poznaniu i okolicach.',
            'description' => <<<'HTML'
                <h2>Dla kogo</h2>
                <p>Łóżko z funkcją przechyłu to najbardziej rozbudowany model w naszej wypożyczalni. Wybierają je opiekunowie osób, które wymagają intensywnej pielęgnacji i rehabilitacji w domu – na przykład pacjentów długotrwale leżących, po rozległych zabiegach albo z ograniczoną możliwością samodzielnej zmiany pozycji.</p>
                <h2>Regulacja i przechył</h2>
                <p>Cztery elektrycznie sterowane zakresy regulacji pozwalają precyzyjnie ułożyć plecy, uda i podudzia pacjenta oraz dopasować wysokość leża do opiekuna. Dodatkowo całe leże można przechylić. Przechył ułatwia część czynności pielęgnacyjnych i zmianę ułożenia ciała bez podnoszenia pacjenta. O tym, czy i w jakim zakresie korzystać z przechyłu, powinien zdecydować lekarz lub fizjoterapeuta prowadzący.</p>
                <h2>Wymiary</h2>
                <p>Leże 105 × 205 cm zapewnia dużo miejsca także osobom wysokim. Ze względu na zakres ruchu łóżka zostaw wokół niego trochę wolnej przestrzeni i nie dosuwaj go ściśle do mebli. Najlepiej, gdy opiekun ma dostęp do pacjenta z obu dłuższych boków łóżka.</p>
                <h2>Wypożyczenie</h2>
                <p>Przywozimy łóżko, wnosimy je i przygotowujemy do użycia. Jeśli nie potrzebujesz przechyłu, tańszą alternatywą będzie łóżko 3-funkcyjne. Okres wynajmu możesz przedłużać według potrzeb.</p>
                HTML,
        ],

        'koncentrator-tlenu-aeroplus' => [
            'seo_title' => 'Koncentrator tlenu Aeroplus – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz koncentrator tlenu Aeroplus 6 lub Aeroplus 600 w Poznaniu. Ekonomiczna opcja domowej tlenoterapii, z dostawą pod wskazany adres.',
            'description' => <<<'HTML'
                <h2>Jak działa koncentrator tlenu</h2>
                <p>Koncentrator pobiera powietrze z pomieszczenia i oddziela z niego tlen, który podaje pacjentowi przez kaniulę nosową lub maskę. Nie wymaga wymiany butli – wystarczy podłączenie do gniazdka. Tlenoterapię stosuje się wyłącznie zgodnie z zaleceniem lekarza, który określa przepływ i liczbę godzin terapii w ciągu doby.</p>
                <h2>Dla kogo jest model Aeroplus</h2>
                <p>Aeroplus 6 i Aeroplus 600 należą do najtańszych koncentratorów w naszej wypożyczalni. Dobrze sprawdzają się wtedy, gdy tlen jest potrzebny przez ograniczony czas – na przykład po pobycie w szpitalu, w czasie infekcji albo do chwili, gdy pacjent otrzyma własne urządzenie. To rozsądny wybór, jeśli koncentrator może stać w pokoju dziennym lub w miejscu, w którym jego praca nie będzie przeszkadzać w czasie snu.</p>
                <h2>Kiedy wybrać inny koncentrator</h2>
                <p>Jeśli urządzenie będzie pracowało nocą w sypialni, rozważ jeden z modeli cichych: Philips Respironics EverFlo, Invacare Perfecto2 lub Kröber O2.</p>
                <h2>Wypożyczenie</h2>
                <p>Koncentrator dostarczamy pod wskazany adres w Poznaniu i okolicach i przygotowujemy go do użycia. Przy rezerwacji zapytaj o akcesoria potrzebne do terapii, na przykład kaniulę nosową. Wynajem możesz przedłużać z miesiąca na miesiąc.</p>
                HTML,
        ],
        'koncentrator-tlenu-devilbiss-inva' => [
            'seo_title' => 'Koncentrator tlenu DeVilbiss / Invacare – wynajem',
            'seo_description' => 'Wypożycz koncentrator tlenu DeVilbiss 515 lub Invacare Inva 5 w Poznaniu. Sprawdzone urządzenia do domowej tlenoterapii, dostawa pod adres.',
            'description' => <<<'HTML'
                <h2>Sprawdzone urządzenia znanych producentów</h2>
                <p>DeVilbiss 515 i Invacare Inva 5 to stacjonarne koncentratory tlenu renomowanych producentów sprzętu medycznego, od lat używane w domowej tlenoterapii. Stanowią środek naszej oferty: zwykle są droższe od modeli Aeroplus, ale tańsze od koncentratorów oznaczonych jako ciche.</p>
                <h2>Jak korzystać z koncentratora</h2>
                <p>Urządzenie zasilane z gniazdka wytwarza tlen z powietrza w pomieszczeniu, więc nie trzeba wymieniać butli. Pacjent oddycha przez kaniulę nosową lub maskę. Przepływ tlenu i czas terapii w ciągu doby ustala lekarz – nie należy zmieniać ich samodzielnie. Koncentrator ustaw z dala od źródeł ognia i ciepła, a w jego pobliżu nie pal tytoniu.</p>
                <h2>Dla kogo</h2>
                <p>Ten model wybierają zwykle osoby, które potrzebują tlenu przez dłuższy czas i oczekują urządzenia dobrze znanego lekarzom i serwisom. Jeśli koncentrator ma pracować przez całą noc tuż obok łóżka, sprawdź także modele ciche, na przykład Philips Respironics EverFlo.</p>
                <h2>Wypożyczenie</h2>
                <p>Przy rezerwacji poinformujemy, który z dwóch modeli jest dostępny. Koncentrator przywozimy pod wskazany adres w Poznaniu i okolicach i przygotowujemy do pracy. Kaucja jest zwrotna po oddaniu sprawnego urządzenia, a wynajem możesz przedłużać według zaleceń lekarza.</p>
                HTML,
        ],
        'koncentrator-tlenu-krober-o2' => [
            'seo_title' => 'Cichy koncentrator tlenu Kröber O2 – wynajem Poznań',
            'seo_description' => 'Wypożycz cichy koncentrator tlenu Kröber O2 w Poznaniu. Niemieckie urządzenie do tlenoterapii w domu, także w sypialni. Dostawa i uruchomienie.',
            'description' => <<<'HTML'
                <h2>Cichy koncentrator do domu</h2>
                <p>Kröber O2 to koncentrator tlenu niemieckiego producenta Kröber Medizintechnik, zaliczany do urządzeń cichych. Jego praca w mniejszym stopniu zakłóca sen i rozmowę, dlatego dobrze sprawdza się u pacjentów, którzy korzystają z tlenu w nocy albo przez wiele godzin dziennie w tym samym pokoju, w którym odpoczywają.</p>
                <h2>Jak działa</h2>
                <p>Urządzenie pobiera powietrze z otoczenia i zagęszcza zawarty w nim tlen, który pacjent otrzymuje przez kaniulę nosową lub maskę. Koncentrator wymaga jedynie zasilania z gniazdka – nie trzeba zamawiać ani wymieniać butli. Przepływ i liczbę godzin tlenoterapii określa lekarz; ustawień nie należy zmieniać na własną rękę.</p>
                <h2>Kiedy wybrać ten model</h2>
                <p>Kröber O2 należy do droższych koncentratorów w naszej wypożyczalni. Wybierz go, jeśli zależy Ci na cichej pracy i urządzeniu, które ma pracować przez długi czas. Inne ciche modele w ofercie to Philips Respironics EverFlo i Invacare Perfecto2 – zapytaj, który z nich jest w danej chwili dostępny i czym się różnią.</p>
                <h2>Wypożyczenie</h2>
                <p>Koncentrator dostarczamy w Poznaniu i okolicach i przygotowujemy go do użycia. Kaucję zwracamy po oddaniu urządzenia, a wynajem możesz przedłużać z miesiąca na miesiąc.</p>
                HTML,
        ],
        'koncentrator-tlenu-everflo' => [
            'seo_title' => 'Koncentrator tlenu EverFlo – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz cichy koncentrator tlenu Philips Respironics EverFlo, do 5 l/min, w Poznaniu. Kompaktowe urządzenie do tlenoterapii w domu.',
            'description' => <<<'HTML'
                <h2>Kompaktowy i cichy</h2>
                <p>Philips Respironics EverFlo to stacjonarny koncentrator tlenu o wydajności do 5 l/min, ceniony za kompaktową obudowę i cichą pracę. Zajmuje niewiele miejsca obok łóżka lub fotela, a jego dźwięk w mniejszym stopniu przeszkadza w czasie snu niż w przypadku standardowych urządzeń.</p>
                <h2>Dla kogo</h2>
                <p>Model ten dobrze sprawdza się u pacjentów, którym lekarz zalecił domową tlenoterapię przez wiele godzin na dobę, w tym w nocy. Wydajność do 5 l/min pokrywa zalecenia większości osób korzystających z koncentratora w domu, ale o właściwym przepływie zawsze decyduje lekarz – przed wypożyczeniem sprawdź, jaki przepływ został zalecony.</p>
                <h2>Jak działa</h2>
                <p>Koncentrator wytwarza tlen z powietrza w pomieszczeniu i podaje go przez kaniulę nosową lub maskę. Potrzebuje tylko zasilania z gniazdka, bez butli. Ustaw go z dala od źródeł ciepła i otwartego ognia oraz zostaw kilka centymetrów wolnej przestrzeni wokół otworów wentylacyjnych.</p>
                <h2>Wypożyczenie</h2>
                <p>EverFlo przywozimy pod wskazany adres w Poznaniu i okolicach i przygotowujemy do pracy. Podobne parametry ma Invacare Perfecto2 – jeśli jeden z modeli jest zajęty, zaproponujemy drugi. Wynajem możesz przedłużać miesiąc po miesiącu.</p>
                HTML,
        ],
        'koncentrator-tlenu-perfecto2' => [
            'seo_title' => 'Koncentrator tlenu Invacare Perfecto2 – wynajem',
            'seo_description' => 'Wypożycz cichy koncentrator tlenu Invacare Perfecto2, do 5 l/min, w Poznaniu. Do długotrwałej tlenoterapii w domu, z dostawą pod adres.',
            'description' => <<<'HTML'
                <h2>Cichy koncentrator do długiej terapii</h2>
                <p>Invacare Perfecto2 to stacjonarny koncentrator tlenu o wydajności do 5 l/min, należący do urządzeń cichych. Przeznaczony jest do długotrwałej tlenoterapii w domu – także w nocy, w sypialni, gdzie głośniejsze urządzenie mogłoby przeszkadzać pacjentowi i domownikom.</p>
                <h2>Jak korzystać</h2>
                <p>Koncentrator zagęszcza tlen z powietrza w pomieszczeniu i podaje go przez kaniulę nosową lub maskę. Wymaga tylko zasilania z gniazdka. Lekarz określa przepływ i czas terapii w ciągu doby; tych ustawień nie zmieniaj samodzielnie. W pobliżu działającego koncentratora nie pal tytoniu i nie używaj otwartego ognia.</p>
                <h2>Dla kogo</h2>
                <p>Perfecto2 wybierają osoby, które korzystają z tlenu wiele godzin dziennie i chcą urządzenia producenta dobrze znanego w opiece domowej. Parametrami odpowiada modelowi Philips Respironics EverFlo, więc o wyborze między nimi zwykle decyduje dostępność w danym momencie. Jeśli tlen jest potrzebny tylko przez krótki czas, tańszą opcją będzie koncentrator Aeroplus.</p>
                <h2>Wypożyczenie</h2>
                <p>Koncentrator dostarczamy w Poznaniu i okolicach i przygotowujemy do użycia. Kaucja jest zwrotna, a okres wynajmu możesz wydłużać zgodnie z zaleceniami lekarza.</p>
                HTML,
        ],

        'podnosnik-pacjenta' => [
            'seo_title' => 'Podnośnik pacjenta do 175 kg – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz podnośnik pacjenta do 175 kg w Poznaniu. Bezpieczne przenoszenie z łóżka na wózek przy codziennej pielęgnacji. Dostawa i montaż.',
            'description' => <<<'HTML'
                <h2>Do czego służy podnośnik</h2>
                <p>Podnośnik pacjenta pozwala bezpiecznie przenieść osobę, która nie może samodzielnie wstać, z łóżka na wózek inwalidzki, fotel lub krzesło toaletowe i z powrotem. Pacjent siedzi lub leży w nosidle, a ciężar przejmuje konstrukcja urządzenia. Opiekun nie musi dźwigać, co chroni jego kręgosłup i zmniejsza ryzyko upadku podczas przesiadania.</p>
                <h2>Dla kogo</h2>
                <p>Podnośnik przydaje się w opiece nad osobami leżącymi, po udarze, z niedowładem kończyn albo po urazach, gdy przemieszczanie trzeba wykonywać kilka razy dziennie. Wzmocniona konstrukcja przenosi obciążenie do 175 kg, więc nadaje się także dla pacjentów o większej masie ciała.</p>
                <h2>Na co zwrócić uwagę</h2>
                <p>Podstawa podnośnika musi wjechać pod łóżko lub obok fotela, dlatego przed dostawą sprawdź, ile jest wolnego miejsca pod łóżkiem i wokół niego. Obsługa jest prosta, ale pierwsze przeniesienie warto przećwiczyć we dwie osoby i zawsze postępować zgodnie z instrukcją producenta. Podnośnik dobrze współpracuje z łóżkiem rehabilitacyjnym z regulacją wysokości.</p>
                <h2>Wypożyczenie</h2>
                <p>Podnośnik przywozimy, wnosimy i przygotowujemy do użycia. Kaucja jest zwrotna po oddaniu sprzętu, a wynajem możesz przedłużać z miesiąca na miesiąc.</p>
                HTML,
        ],
        'schodolaz-z-krzeslem' => [
            'seo_title' => 'Schodołaz z krzesłem – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz schodołaz z krzesłem w Poznaniu. Pomoc w pokonaniu schodów przy wyjazdach, wizytach i wydarzeniach rodzinnych. Wynajem krótko- i długoterminowy.',
            'description' => <<<'HTML'
                <h2>Do czego służy schodołaz</h2>
                <p>Schodołaz z krzesłem pozwala przetransportować po schodach osobę, która nie może samodzielnie po nich chodzić. Pasażer siedzi na wbudowanym krześle, a urządzenie prowadzone przez opiekuna pokonuje kolejne stopnie, zamiast nieść całą osobę na rękach. To rozwiązanie dla budynków bez windy i dla miejsc, w których nie ma podjazdu.</p>
                <h2>Kiedy warto go wypożyczyć</h2>
                <p>Najczęściej wypożyczamy go na konkretną okazję: przeprowadzkę, wizytę u rodziny mieszkającej na piętrze, wesele, chrzciny albo wyjazd. Sprawdza się też w okresie przejściowym, na przykład po powrocie ze szpitala, zanim rodzina znajdzie stałe rozwiązanie. Wybierz go, gdy pasażer nie korzysta na co dzień z wózka inwalidzkiego albo wózek nie pasuje do schodołazu.</p>
                <h2>Bezpieczeństwo</h2>
                <p>Obsługa schodołazu wymaga wprawy. Przed pierwszym transportem przećwicz ją na kilku stopniach bez pasażera i postępuj zgodnie z instrukcją producenta. Jeśli schody są kręte, bardzo wąskie albo mają nietypowe stopnie, opisz je przy rezerwacji – sprawdzimy, czy urządzenie sobie z nimi poradzi.</p>
                <h2>Wypożyczenie</h2>
                <p>Schodołaz dostarczamy w Poznaniu i okolicach. Kaucja jest zwrotna po oddaniu sprzętu.</p>
                HTML,
        ],
        'schodolaz-do-wozka' => [
            'seo_title' => 'Schodołaz do wózka inwalidzkiego – wynajem Poznań',
            'seo_description' => 'Wypożycz schodołaz do wózka inwalidzkiego w Poznaniu. Pokonuj schody bez przesiadania pasażera z wózka. Na wyjazdy i wydarzenia rodzinne.',
            'description' => <<<'HTML'
                <h2>Schody bez przesiadania z wózka</h2>
                <p>Schodołaz do wózka inwalidzkiego pozwala wnieść lub znieść po schodach osobę, która siedzi na swoim wózku. Wózek mocuje się do urządzenia, a opiekun prowadzi całość stopień po stopniu. Pasażer nie musi się przesiadać, co jest wygodniejsze i bezpieczniejsze dla osób, dla których każda zmiana miejsca jest wysiłkiem.</p>
                <h2>Kiedy wybrać ten model</h2>
                <p>To dobre rozwiązanie dla osób, które na co dzień poruszają się na wózku inwalidzkim, a od czasu do czasu muszą pokonać schody: przy wizycie u rodziny, w budynku bez windy, w czasie wyjazdu albo uroczystości. Jeśli pasażer nie korzysta z wózka, wygodniejszy będzie schodołaz z wbudowanym krzesłem.</p>
                <h2>Czy pasuje do mojego wózka</h2>
                <p>Nie każdy wózek da się zamocować w każdym schodołazie. Przy rezerwacji podaj model wózka albo jego szerokość i rodzaj ramy, a także opisz schody – szerokość biegu, kształt i liczbę stopni. Dzięki temu sprawdzimy, czy zestaw będzie bezpieczny. Przed pierwszym użyciem przećwicz obsługę bez pasażera i trzymaj się instrukcji producenta.</p>
                <h2>Wypożyczenie</h2>
                <p>Schodołaz dostarczamy w Poznaniu i okolicach. Kaucja jest zwrotna po oddaniu sprawnego urządzenia, a okres wynajmu dopasujesz do swoich planów.</p>
                HTML,
        ],

        'wozek-inwalidzki-standardowy' => [
            'seo_title' => 'Wózek inwalidzki standardowy – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz standardowy wózek inwalidzki w Poznaniu, szerokość siedziska 41–52 cm. Na czas rekonwalescencji, wizyt lekarskich i wyjazdów.',
            'description' => <<<'HTML'
                <h2>Dla kogo</h2>
                <p>Standardowy wózek inwalidzki to najczęściej wypożyczany wózek w naszej ofercie. Sprawdza się w czasie rekonwalescencji po urazie lub operacji, gdy chodzenie jest chwilowo utrudnione, a także u osób starszych, które pokonują pieszo krótkie odcinki, ale na dłuższy spacer, wizytę u lekarza czy zakupy potrzebują wózka.</p>
                <h2>Dobór rozmiaru</h2>
                <p>Mamy wózki z siedziskiem o szerokości od 41 do 52 cm. Zmierz szerokość bioder osoby siedzącej i dodaj kilka centymetrów zapasu – siedzisko nie powinno uciskać, ale zbyt szerokie utrudnia samodzielne napędzanie kół. Jeśli nie wiesz, który rozmiar wybrać, zadzwoń: pomożemy dobrać wózek do wzrostu i budowy ciała. Cena wynajmu zależy od rozmiaru i modelu.</p>
                <h2>Kiedy wybrać inny wózek</h2>
                <p>Jeśli wózek będzie często składany i wkładany do bagażnika, rozważ lżejszy wózek ultralekki. Po złamaniu kończyny dolnej lepszy będzie model z regulacją kąta podnóżka.</p>
                <h2>Wypożyczenie i NFZ</h2>
                <p>Wózek dostarczamy w Poznaniu i okolicach. Wynajem możesz przedłużać, a gdy wózek okaże się potrzebny na stałe, zapytaj o możliwość zakupu z dofinansowaniem NFZ na podstawie zlecenia od lekarza.</p>
                HTML,
        ],
        'wozek-inwalidzki-ultralekki' => [
            'seo_title' => 'Wózek inwalidzki ultralekki – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz ultralekki wózek inwalidzki z hamulcami w Poznaniu, siedzisko 32–42 cm. Łatwy do złożenia i przewożenia w samochodzie.',
            'description' => <<<'HTML'
                <h2>Lekki wózek na co dzień i w podróży</h2>
                <p>Wózek ultralekki waży wyraźnie mniej niż standardowy wózek inwalidzki, dlatego łatwiej go złożyć, podnieść i włożyć do bagażnika. To ważne, gdy opiekun często przewozi pacjenta samochodem – na rehabilitację, do lekarza albo do rodziny – lub gdy wózek trzeba wnosić po kilku stopniach.</p>
                <h2>Hamulce dla opiekuna</h2>
                <p>Model jest wyposażony w hamulce, które ułatwiają opiekunowi kontrolę nad wózkiem, na przykład przy zjeździe z pochylni czy na nierównym chodniku. Przy każdym zatrzymaniu i przesiadaniu zablokuj koła, aby wózek nie odjechał.</p>
                <h2>Rozmiar</h2>
                <p>Szerokość siedziska wynosi od 32 do 42 cm, więc wózek pasuje przede wszystkim osobom szczupłym i niższym. Dla osób o szerszych biodrach lepszy będzie wózek standardowy z siedziskiem do 52 cm. W razie wątpliwości podaj nam szerokość bioder w pozycji siedzącej, a dobierzemy odpowiedni model.</p>
                <h2>Wypożyczenie i NFZ</h2>
                <p>Wózek dostarczamy w Poznaniu i okolicach. Kaucja jest zwrotna po oddaniu sprzętu. Jeśli okaże się, że wózek będzie potrzebny na dłużej, zapytaj o możliwość zakupu z dofinansowaniem NFZ.</p>
                HTML,
        ],
        'wozek-inwalidzki-regulowany-podnozek' => [
            'seo_title' => 'Wózek z regulowanym podnóżkiem – wynajem Poznań',
            'seo_description' => 'Wypożycz wózek inwalidzki z regulacją kąta podnóżka w Poznaniu. Dla osób ze złamaniem nogi, w gipsie lub ortezie. Dostawa pod adres.',
            'description' => <<<'HTML'
                <h2>Dla osób po złamaniu nogi</h2>
                <p>Ten wózek jest przeznaczony dla osób ze złamaniami lub urazami kończyn dolnych. Regulowany kąt podnóżka pozwala ułożyć nogę w gipsie, ortezie lub po zabiegu w pozycji wyprostowanej albo lekko zgiętej, zamiast trzymać ją opuszczoną pod kątem prostym, jak w zwykłym wózku. Dzięki temu łatwiej zachować ułożenie zalecone przez lekarza.</p>
                <h2>Kiedy się przydaje</h2>
                <p>Najczęściej wypożyczamy go na kilka tygodni po złamaniu kości podudzia, stawu skokowego lub kolana, po operacjach ortopedycznych i w czasie, gdy chodzenie o kulach jest jeszcze zbyt trudne albo niezalecane. Ustawienie podnóżka można zmieniać wraz z postępami leczenia.</p>
                <h2>Na co zwrócić uwagę</h2>
                <p>Uniesiony podnóżek wydłuża wózek, więc zajmuje on więcej miejsca w windzie, w wąskim korytarzu czy w samochodzie. O tym, jak długo i w jakiej pozycji trzymać nogę, zdecyduje lekarz prowadzący. Jeśli uraz nie dotyczy nóg, tańszy będzie standardowy wózek inwalidzki.</p>
                <h2>Wypożyczenie</h2>
                <p>Wózek dostarczamy pod wskazany adres w Poznaniu i okolicach. Po zdjęciu gipsu lub zakończeniu leczenia oddajesz sprzęt, a kaucja jest zwracana. Wynajem możesz przedłużyć, jeśli rekonwalescencja potrwa dłużej.</p>
                HTML,
        ],
        'wozek-inwalidzki-dzieciecy' => [
            'seo_title' => 'Wózek inwalidzki dziecięcy – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz aktywny wózek inwalidzki dla dziecka w Poznaniu. Kilka modeli i rozmiarów, na czas leczenia lub przed zakupem z dofinansowaniem NFZ.',
            'description' => <<<'HTML'
                <h2>Wózek dopasowany do dziecka</h2>
                <p>Aktywny wózek dziecięcy jest mniejszy i lżejszy od wózka dla dorosłych, a jego konstrukcja pozwala dziecku samodzielnie napędzać koła i swobodnie się poruszać. Dobrze dobrany wózek wspiera samodzielność dziecka w domu, w szkole i w czasie zabawy, zamiast ograniczać je do roli pasażera.</p>
                <h2>Kiedy warto wypożyczyć</h2>
                <p>Rodzice wypożyczają go najczęściej na czas leczenia po urazie lub operacji, a także po to, by sprawdzić, czy dany typ wózka sprawdzi się na co dzień, zanim zdecydują się na zakup. Dzieci szybko rosną, więc wynajem pozwala zmienić wózek na większy bez kupowania kolejnego.</p>
                <h2>Dobór modelu</h2>
                <p>Mamy kilka modeli w różnych rozmiarach; cena wynajmu i kaucja zależą od wybranego wózka. Przy rezerwacji podaj wiek, wzrost i szerokość bioder dziecka w pozycji siedzącej, a także informację, czy dziecko będzie samo napędzać wózek. Przy schorzeniach wymagających specjalnego pozycjonowania dobór warto skonsultować z fizjoterapeutą.</p>
                <h2>NFZ</h2>
                <p>Ten wózek można także kupić z dofinansowaniem NFZ na podstawie zlecenia lekarza – zapytaj nas o szczegóły i dostępne modele. Wypożyczony wózek dostarczamy w Poznaniu i okolicach.</p>
                HTML,
        ],
        'wozek-inwalidzki-aktywny' => [
            'seo_title' => 'Aktywny wózek inwalidzki ultralekki – wynajem Poznań',
            'seo_description' => 'Wypożycz aktywny, ultralekki wózek inwalidzki w Poznaniu. Dla osób samodzielnie napędzających wózek. Sprawdź model przed zakupem z NFZ.',
            'description' => <<<'HTML'
                <h2>Dla osób samodzielnie napędzających wózek</h2>
                <p>Aktywny wózek ultralekki jest przeznaczony dla osób, które same napędzają koła i prowadzą aktywny tryb życia: pracują, uczą się, prowadzą samochód, podróżują. Lekka i sztywna konstrukcja sprawia, że wózek łatwo się prowadzi, szybko reaguje na ruch rąk i mniej męczy przy codziennym poruszaniu się po mieście.</p>
                <h2>Czym różni się od wózka standardowego</h2>
                <p>W przeciwieństwie do standardowego wózka, którym zwykle kieruje opiekun, wózek aktywny ustawia się tak, aby jak najlepiej pasował do użytkownika – jego wzrostu, długości nóg i sposobu napędzania. Dzięki temu siedzi się w nim stabilniej, a ręce pracują efektywniej. Jest też łatwiejszy do zdemontowania i włożenia do samochodu przez samego użytkownika.</p>
                <h2>Wypożyczenie przed zakupem</h2>
                <p>Wózek aktywny to duży wydatek, dlatego wiele osób wypożycza go najpierw na kilka tygodni, aby sprawdzić, czy taki typ wózka odpowiada ich potrzebom. Ten model można także kupić z dofinansowaniem NFZ na podstawie zlecenia od lekarza – zapytaj o szczegóły.</p>
                <h2>Jak wypożyczyć</h2>
                <p>Przy rezerwacji podaj wzrost, wagę i szerokość bioder w pozycji siedzącej. Wózek dostarczamy w Poznaniu i okolicach, a kaucja jest zwrotna po oddaniu sprzętu.</p>
                HTML,
        ],

        'rollator' => [
            'seo_title' => 'Rollator składany – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz składany rollator w Poznaniu. Pewniejszy krok na spacerach, w drodze do sklepu i przychodni. Możliwy zakup z dofinansowaniem NFZ.',
            'description' => <<<'HTML'
                <h2>Dla kogo jest rollator</h2>
                <p>Rollator to czterokołowy chodzik dla osób, które chodzą samodzielnie, ale potrzebują podparcia i pewności na dłuższym dystansie. Ułatwia spacery, wyjście do sklepu, przychodni czy kościoła. Najczęściej korzystają z niego osoby starsze, osoby po urazach i operacjach oraz osoby z chorobami, które utrudniają utrzymanie równowagi.</p>
                <h2>Jak z niego korzystać</h2>
                <p>Rollator prowadzi się przed sobą, nie trzeba go podnosić przy każdym kroku – w przeciwieństwie do balkonika. Rollatory mają zwykle hamulce w uchwytach i siedzisko do odpoczynku w czasie spaceru; zapytaj, jak wyposażony jest dostępny egzemplarz. Zanim usiądziesz, zawsze zablokuj hamulce. Wysokość uchwytów ustaw tak, aby przy wyprostowanej sylwetce ręce były lekko zgięte w łokciach.</p>
                <h2>Rollator czy balkonik</h2>
                <p>Rollator lepiej sprawdza się na zewnątrz i na dłuższych odcinkach. W małym mieszkaniu, przy pierwszych krokach po operacji albo gdy potrzebne jest bardzo stabilne podparcie, wygodniejszy może być balkonik rehabilitacyjny.</p>
                <h2>Wypożyczenie i NFZ</h2>
                <p>Rollator jest składany, więc łatwo przewieźć go samochodem. Dostarczamy go w Poznaniu i okolicach. Jeśli okaże się potrzebny na stałe, zapytaj o zakup z dofinansowaniem NFZ na podstawie zlecenia lekarza.</p>
                HTML,
        ],
        'balkonik-rehabilitacyjny' => [
            'seo_title' => 'Balkonik rehabilitacyjny – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz balkonik rehabilitacyjny w Poznaniu. Stabilne podparcie przy chodzeniu po domu, po operacji lub urazie. Możliwy zakup z dofinansowaniem NFZ.',
            'description' => <<<'HTML'
                <h2>Stabilne podparcie w domu</h2>
                <p>Balkonik rehabilitacyjny to lekka rama, która otacza użytkownika z trzech stron i daje pewne podparcie przy chodzeniu. Przydaje się przede wszystkim w domu: przy przechodzeniu z pokoju do łazienki, do kuchni czy do fotela. Wybierają go osoby, które po operacji, urazie albo dłuższym leżeniu wracają do samodzielnego chodzenia, a także osoby starsze z zaburzeniami równowagi.</p>
                <h2>Jak z niego korzystać</h2>
                <p>Balkonik przestawia się przed sobą, a następnie robi krok, opierając część ciężaru ciała na uchwytach. Taka technika jest wolniejsza niż chodzenie z rollatorem, ale bardziej stabilna, dlatego często zalecają ją fizjoterapeuci na pierwszym etapie rehabilitacji. Wysokość uchwytów dopasuj do wzrostu, a w domu usuń z drogi dywaniki i przewody, o które łatwo zahaczyć.</p>
                <h2>Balkonik czy rollator</h2>
                <p>Na dłuższe spacery i wyjścia z domu lepszy będzie rollator z kółkami, hamulcami i siedziskiem. Balkonik zajmuje mniej miejsca i dobrze sprawdza się w mniejszych mieszkaniach.</p>
                <h2>Wypożyczenie i NFZ</h2>
                <p>Balkonik dostarczamy w Poznaniu i okolicach. Wynajem możesz przedłużać, a jeśli sprzęt będzie potrzebny na dłużej, zapytaj o zakup z dofinansowaniem NFZ na podstawie zlecenia lekarza.</p>
                HTML,
        ],
        'kula-lokciowa' => [
            'seo_title' => 'Kula łokciowa – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz kulę łokciową z regulacją wysokości w Poznaniu. Na czas leczenia urazu nogi lub po operacji. Cena za sztukę, możliwość wynajmu pary.',
            'description' => <<<'HTML'
                <h2>Kiedy przydaje się kula łokciowa</h2>
                <p>Kula łokciowa odciąża nogę po urazie, złamaniu, skręceniu stawu lub operacji ortopedycznej i pomaga utrzymać równowagę przy chodzeniu. To najczęściej wybierany rodzaj kul: obejmę zakłada się na przedramię, a ciężar ciała przenosi się na uchwyt dłonią. Kule łokciowe są lżejsze i zajmują mniej miejsca niż kule pachowe.</p>
                <h2>Regulacja wysokości</h2>
                <p>Wysokość kuli regulujesz samodzielnie. Ustaw ją tak, aby przy wyprostowanej sylwetce i opuszczonych rękach uchwyt znajdował się mniej więcej na wysokości nadgarstka, a łokieć przy podparciu był lekko zgięty. Źle dobrana wysokość obciąża bark i nadgarstek. Sposób chodzenia i stopień obciążania chorej nogi powinien określić lekarz lub fizjoterapeuta.</p>
                <h2>Jedna kula czy para</h2>
                <p>Cena dotyczy jednej sztuki. Jedna kula wystarcza zwykle wtedy, gdy noga może być częściowo obciążana; przy całkowitym odciążeniu potrzebne są dwie. Jeśli potrzebujesz pary, zamów dwie sztuki. Gdy kule łokciowe są niewygodne, na przykład przy słabszych nadgarstkach, rozważ kule pachowe.</p>
                <h2>Wypożyczenie</h2>
                <p>Kule możesz wypożyczyć na dowolny okres – na czas noszenia gipsu, ortezy lub do zakończenia rehabilitacji – i przedłużać wynajem, jeśli leczenie potrwa dłużej. Kaucja jest zwrotna po oddaniu sprzętu.</p>
                HTML,
        ],
        'kule-pachowe' => [
            'seo_title' => 'Kule pachowe – wypożyczalnia Poznań',
            'seo_description' => 'Wypożycz parę kul pachowych w Poznaniu. Stabilne podparcie przy całkowitym odciążeniu nogi po złamaniu lub operacji. Wynajem na czas leczenia.',
            'description' => <<<'HTML'
                <h2>Dla kogo są kule pachowe</h2>
                <p>Kule pachowe dają większą stabilność niż kule łokciowe, ponieważ podpierają ciało w dwóch punktach: pod pachą i w dłoni. Sprawdzają się zwłaszcza wtedy, gdy noga po złamaniu lub operacji nie może być w ogóle obciążana, a także u osób, które mają mniej siły w nadgarstkach lub dopiero uczą się chodzić o kulach.</p>
                <h2>Jak prawidłowo ich używać</h2>
                <p>Ciężar ciała przenosi się przede wszystkim na uchwyty dłońmi, a nie na górną część kuli. Między górną podpórką a pachą powinna zostać przerwa na mniej więcej dwa–trzy palce, ponieważ długotrwały nacisk pod pachą może podrażniać nerwy i naczynia krwionośne. Uchwyt ustaw na wysokości nadgarstka przy opuszczonej ręce. Technikę chodzenia, zwłaszcza po schodach, warto przećwiczyć z fizjoterapeutą.</p>
                <h2>Kule pachowe czy łokciowe</h2>
                <p>Kule pachowe są większe i mniej poręczne, dlatego przy dłuższym leczeniu, gdy noga może już być częściowo obciążana, wiele osób przechodzi na lżejsze kule łokciowe. Na pierwsze tygodnie po urazie są jednak często wygodniejsze i bezpieczniejsze.</p>
                <h2>Wypożyczenie</h2>
                <p>Cena dotyczy pary kul. Wynajem możesz przedłużać przez cały czas leczenia, a kaucję zwracamy po oddaniu sprzętu.</p>
                HTML,
        ],
    ];
}
