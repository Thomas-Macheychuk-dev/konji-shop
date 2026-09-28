@extends('layouts.storefront')

@section('content')
    @php
        $seller = config('legal.seller');
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $termsPdf = asset((string) config('legal.documents.terms_pdf'));
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Informacje prawne</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Regulamin sklepu internetowego ORTEZKA.PL</h1>

            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-zinc-500">
                <span>Wersja: {{ config('legal.versions.terms') }}</span>
                <span>Obowiązuje od: {{ $effectiveDate }}</span>
                <a class="font-medium text-zinc-900 underline underline-offset-4" href="{{ $termsPdf }}" target="_blank" rel="noopener noreferrer">Pobierz Regulamin PDF</a>
            </div>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>§ 1. Sprzedawca i zakres Regulaminu</h2>
                <p>1. Sklep internetowy ORTEZKA.PL działający pod adresem https://ortezka.pl jest prowadzony przez {{ $seller['company_name'] }} z siedzibą w Poznaniu, ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}, wpisaną do rejestru przedsiębiorców Krajowego Rejestru Sądowego pod numerem KRS {{ $seller['business_registry_number'] }}, NIP {{ $seller['tax_id'] }}, REGON {{ $seller['regon'] }}, kapitał zakładowy {{ $seller['share_capital'] }}, zwaną dalej „Sprzedawcą”.</p>
                <p>2. Kontakt ze Sprzedawcą: e-mail: <a href="mailto:{{ $seller['email'] }}">{{ $seller['email'] }}</a>; telefon: {{ $seller['phone'] }}; adres korespondencyjny i adres do zwrotów/reklamacji: ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}, chyba że w potwierdzeniu zamówienia lub na stronie produktu wskazano inny właściwy adres obsługi danego Towaru.</p>
                <p>3. Regulamin określa zasady korzystania ze Sklepu, składania i realizacji zamówień, płatności i dostawy, odstąpienia od umowy, reklamacji, a także zasady świadczenia usług drogą elektroniczną.</p>
                <p>4. Regulamin stosuje się do sprzedaży na odległość za pośrednictwem Sklepu. Usługi najmu/wypożyczenia sprzętu, usługi wykonywane stacjonarnie oraz realizacja zleceń refundacyjnych NFZ mogą podlegać dodatkowym zasadom, jeżeli Sprzedawca udostępnia takie usługi. W takim przypadku Klient otrzymuje właściwe informacje przed zawarciem danej umowy.</p>

                <h2>§ 2. Definicje</h2>
                <p>1. <strong>Klient</strong> - osoba fizyczna, osoba prawna albo jednostka organizacyjna posiadająca zdolność prawną, korzystająca ze Sklepu lub zawierająca ze Sprzedawcą umowę.</p>
                <p>2. <strong>Konsument</strong> - osoba fizyczna dokonująca z przedsiębiorcą czynności prawnej niezwiązanej bezpośrednio z jej działalnością gospodarczą lub zawodową.</p>
                <p>3. <strong>Przedsiębiorca na prawach konsumenta</strong> - osoba fizyczna zawierająca umowę bezpośrednio związaną z jej działalnością gospodarczą, gdy umowa nie ma dla niej charakteru zawodowego w rozumieniu art. 7aa ustawy o prawach konsumenta. Jeżeli zawodowy charakter umowy nie wynika z jej treści, osoba ta może najpóźniej przy zawarciu umowy złożyć oświadczenie co do charakteru umowy; złożenie takiego oświadczenia nie może być warunkiem sprzedaży.</p>
                <p>4. <strong>Towar</strong> - rzecz oferowana w Sklepie, w tym w szczególności wyrób medyczny, produkt ortopedyczny, rehabilitacyjny, kompresyjny, higieniczny, jednorazowy, pomocniczy albo inny produkt z oferty Sklepu.</p>
                <p>5. <strong>Towar higieniczny</strong> - Towar, którego właściwości oraz sposób użycia powodują, że po otwarciu zapieczętowanego opakowania nie może być ponownie przyjęty do obrotu ze względu na ochronę zdrowia lub ze względów higienicznych, jeżeli spełnione są przesłanki art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta.</p>
                <p>6. <strong>Zabezpieczenie higieniczne</strong> - fabryczne albo zastosowane przez Sprzedawcę zabezpieczenie opakowania, którego naruszenie jest widoczne i które uniemożliwia dostęp do Towaru bez jego otwarcia lub uszkodzenia.</p>
                <p>7. <strong>Towar indywidualny</strong> - Towar nieprefabrykowany, wyprodukowany według specyfikacji Klienta lub służący zaspokojeniu jego zindywidualizowanych potrzeb.</p>
                <p>8. <strong>Umowa</strong> - umowa sprzedaży Towaru zawarta na odległość pomiędzy Sprzedawcą a Klientem.</p>
                <p>9. <strong>Trwały nośnik</strong> - materiał lub narzędzie umożliwiające przechowywanie kierowanych osobiście informacji w sposób umożliwiający dostęp do nich przez odpowiedni czas oraz odtworzenie ich w niezmienionej postaci, w szczególności wiadomość e-mail lub dokument PDF.</p>

                <h2>§ 3. Korzystanie ze Sklepu i usługi elektroniczne</h2>
                <p>1. Do korzystania ze Sklepu potrzebne jest urządzenie z dostępem do Internetu, aktualna przeglądarka internetowa obsługująca standardowe technologie używane przez serwis oraz aktywny adres e-mail; do niektórych funkcji może być potrzebny numer telefonu.</p>
                <p>2. Sprzedawca świadczy nieodpłatnie drogą elektroniczną w szczególności usługi: przeglądania oferty, składania zamówień, prowadzenia Konta Klienta - jeżeli funkcja Konta jest udostępniona - oraz korzystania z formularzy dostępnych w Sklepie. Newsletter, jeżeli jest dostępny, stanowi odrębną usługę dobrowolną i wymaga spełnienia warunków wskazanych przy zapisie.</p>
                <p>3. Utworzenie Konta nie jest warunkiem złożenia zamówienia, o ile Sklep udostępnia zakup bez rejestracji.</p>
                <p>4. Klient jest zobowiązany podawać dane prawdziwe i aktualne, chronić dane dostępowe do Konta oraz nie dostarczać treści bezprawnych, naruszających prawa osób trzecich, bezpieczeństwo systemu lub dobre obyczaje.</p>
                <p>5. Umowa o prowadzenie Konta jest zawierana na czas nieoznaczony i może zostać rozwiązana przez Klienta w każdym czasie przez usunięcie Konta w dostępnej funkcji albo przez żądanie skierowane do Sprzedawcy. Sprzedawca może wypowiedzieć taką umowę z ważnej przyczyny, w szczególności w przypadku istotnego naruszenia Regulaminu, po uprzednim wezwaniu do zaprzestania naruszeń, chyba że natychmiastowe działanie jest konieczne ze względów bezpieczeństwa lub prawa.</p>
                <p>6. Reklamacje dotyczące działania usług elektronicznych można zgłaszać na adres e-mail Sprzedawcy. Odpowiedź na reklamację Konsumenta jest udzielana w terminie 14 dni od dnia jej otrzymania na papierze lub innym trwałym nośniku.</p>

                <h2>§ 4. Towary medyczne, informacje o produkcie i dobór rozmiaru</h2>
                <p>1. Sklep specjalizuje się m.in. w wyrobach medycznych, produktach ortopedycznych i rehabilitacyjnych, wyrobach kompresyjnych, stabilizatorach i ortezach, sprzęcie pomocniczym i rehabilitacyjnym, produktach przeciwodleżynowych, sanitarnych, higienicznych i jednorazowych, sprzęcie diagnostycznym oraz innych produktach prezentowanych w aktualnej ofercie.</p>
                <p>2. Informacje na kartach produktów, w tym tabele rozmiarów, instrukcje pomiaru, klasy kompresji, wskazania producenta, parametry i materiały, służą prawidłowemu wyborowi Towaru. Klient powinien zapoznać się z nimi przed zakupem i stosować Towar zgodnie z instrukcją producenta.</p>
                <p>3. W przypadku Towarów wymagających pomiaru - w szczególności pończoch, podkolanówek, rajstop i innych wyrobów kompresyjnych, a także wybranych ortez i stabilizatorów - Klient powinien wykonać pomiary zgodnie z instrukcją i tabelą producenta dostępną przy Towarze. Po otrzymaniu przesyłki Klient powinien sprawdzić oznaczenie modelu, rozmiaru, strony, klasy ucisku i innych parametrów na opakowaniu przed naruszeniem Zabezpieczenia higienicznego.</p>
                <p>4. Materiały informacyjne Sklepu nie zastępują porady lekarza, fizjoterapeuty ani innego uprawnionego specjalisty, jeżeli ze względu na stan zdrowia lub przeznaczenie produktu konsultacja taka jest wymagana lub zalecana. Postanowienie to nie ogranicza odpowiedzialności Sprzedawcy za zgodność Towaru z Umową, w tym za szczególny cel, o którym Klient poinformował Sprzedawcę przed zawarciem Umowy i który Sprzedawca zaakceptował.</p>
                <p>5. Sprzedawca może oznaczać na karcie Towaru, że dany produkt jest wysyłany z Zabezpieczeniem higienicznym albo że stanowi Towar indywidualny. Ograniczenia prawa odstąpienia stosuje się wyłącznie wtedy, gdy spełnione są przesłanki określone przepisami prawa i Klient został o nich poinformowany przed zawarciem Umowy.</p>

                <h2>§ 5. Ceny, promocje i dostępność</h2>
                <p>1. Ceny Towarów są podawane w złotych polskich i zawierają podatki wymagane przepisami prawa. Koszt dostawy i inne należne koszty są wskazywane przed złożeniem zamówienia.</p>
                <p>2. Jeżeli Sprzedawca informuje o obniżeniu ceny Towaru, obok ceny obniżonej prezentuje informację o najniższej cenie tego Towaru obowiązującej w okresie 30 dni przed wprowadzeniem obniżki, a jeżeli Towar jest oferowany krócej niż 30 dni - najniższą cenę od rozpoczęcia jego oferowania do dnia obniżki, zgodnie z obowiązującymi przepisami.</p>
                <p>3. Cena potwierdzona w zawartej Umowie jest wiążąca. Sprzedawca nie podwyższa jednostronnie ceny Towaru po zawarciu Umowy. Oczywisty błąd techniczny dotyczący ceny lub opisu jest rozpatrywany zgodnie z przepisami prawa, z uwzględnieniem okoliczności konkretnego przypadku.</p>
                <p>4. Informacja o dostępności lub przewidywanym czasie realizacji jest prezentowana na stronie Towaru lub w koszyku. Jeżeli realizacja zamówienia okaże się niemożliwa, Sprzedawca niezwłocznie poinformuje Klienta i zwróci otrzymaną płatność w odpowiednim zakresie.</p>

                <h2>§ 6. Składanie zamówienia i zawarcie Umowy</h2>
                <p>1. Klient wybiera Towary, warianty i ilość, następnie podaje dane potrzebne do realizacji zamówienia, wybiera sposób dostawy i płatności oraz sprawdza podsumowanie zamówienia.</p>
                <p>2. Bezpośrednio przed złożeniem zamówienia Klient otrzymuje jasną informację o głównych cechach Towarów, łącznej cenie, kosztach dostawy oraz innych kosztach wymaganych przy danym zamówieniu.</p>
                <p>3. Zamówienie pociągające za sobą obowiązek zapłaty jest składane przy użyciu przycisku „Zamówienie z obowiązkiem zapłaty” albo innego równoważnego, jednoznacznego oznaczenia wymaganego przez prawo.</p>
                <p>4. Złożenie zamówienia stanowi ofertę zawarcia Umowy. Po jego otrzymaniu Sprzedawca przesyła automatyczne potwierdzenie złożenia zamówienia. Umowa zostaje zawarta w chwili przesłania Klientowi przez Sprzedawcę wiadomości potwierdzającej przyjęcie zamówienia do realizacji, chyba że komunikaty Sklepu jednoznacznie wskazują wcześniejszy moment zawarcia Umowy.</p>
                <p>5. Po zawarciu Umowy Sprzedawca przekazuje Klientowi jej potwierdzenie na trwałym nośniku, w szczególności e-mailem, wraz z Regulaminem albo odnośnikiem umożliwiającym jego trwałe zapisanie oraz informacją o prawie odstąpienia.</p>

                <h2>§ 7. Płatności</h2>
                <p>1. Dostępne sposoby płatności są każdorazowo prezentowane Klientowi w koszyku lub podczas składania zamówienia. Mogą obejmować w szczególności przelew bankowy, płatność elektroniczną Paynow, kartę płatniczą oraz płatność za pobraniem, o ile dana metoda jest aktualnie dostępna.</p>
                <p>2. Jeżeli Klient wybiera przedpłatę, realizacja zamówienia może rozpocząć się po pozytywnej autoryzacji płatności lub uznaniu rachunku Sprzedawcy, zgodnie z informacją podaną przy danej metodzie.</p>
                <p>3. W przypadku zwrotu płatności Sprzedawca używa takiego samego sposobu płatności, jakiego użył Klient, chyba że Klient wyraźnie zgodzi się na inny sposób, który nie powoduje dla niego kosztów.</p>

                <h2>§ 8. Dostawa i odbiór</h2>
                <p>1. Aktualne sposoby dostawy, ich ceny, terytorium realizacji i przewidywane terminy są przedstawiane przed złożeniem zamówienia. Regulamin nie utrwala stawek przewoźników, które mogą się zmieniać dla przyszłych zamówień.</p>
                <p>2. Sprzedawca wydaje Towar w terminie wskazanym na stronie Towaru lub przy zamówieniu. W braku szczególnego uzgodnienia stosuje się terminy wynikające z bezwzględnie obowiązujących przepisów prawa.</p>
                <p>3. Klient powinien w miarę możliwości sprawdzić stan przesyłki przy odbiorze. Protokół szkody przewozowej może ułatwić dochodzenie roszczeń wobec przewoźnika, ale jego brak nie pozbawia Konsumenta praw z tytułu niezgodności Towaru z Umową.</p>
                <p>4. Jeżeli Klient nie odbierze prawidłowo nadanej przesyłki bez uzasadnionej przyczyny, Sprzedawca może dochodzić rzeczywiście poniesionych i prawnie należnych kosztów wynikających z niewykonania zobowiązania przez Klienta; każda sytuacja jest oceniana indywidualnie.</p>
                <p>5. Jeżeli Sklep umożliwia odbiór osobisty zamówienia zawartego online, sam odbiór w lokalu nie pozbawia Konsumenta prawa odstąpienia od Umowy zawartej na odległość.</p>

                <h2>§ 9. Prawo odstąpienia od Umowy - zasada ogólna</h2>
                <p>1. Konsument oraz Przedsiębiorca na prawach konsumenta, do którego mają zastosowanie przepisy rozdziału 4 ustawy o prawach konsumenta, może co do zasady odstąpić od Umowy zawartej na odległość w terminie 14 dni bez podawania przyczyny, z wyjątkami określonymi w ustawie i niniejszym Regulaminie.</p>
                <p>2. Termin biegnie od objęcia Towaru w posiadanie przez uprawnioną osobę albo wskazaną przez nią osobę trzecią inną niż przewoźnik; przy wielu Towarach dostarczanych osobno - od objęcia w posiadanie ostatniego Towaru, partii lub części.</p>
                <p>3. Aby zachować termin, wystarczy przed jego upływem przesłać jednoznaczne oświadczenie o odstąpieniu na adres e-mail lub adres korespondencyjny Sprzedawcy. Klient może skorzystać ze wzoru stanowiącego Załącznik nr 1, ale nie jest to obowiązkowe.</p>
                <p>4. Po odstąpieniu Klient powinien zwrócić Towar niezwłocznie, nie później niż w ciągu 14 dni od złożenia oświadczenia. Do zachowania terminu wystarcza odesłanie Towaru przed jego upływem.</p>
                <p>5. Klient ponosi bezpośredni koszt odesłania Towaru, chyba że Sprzedawca zgodził się go ponieść albo nie poinformował Klienta o tym obowiązku. W przypadku Towaru, którego nie można zwrócić zwykłą przesyłką, informacja o przewidywanym koszcie zwrotu jest przedstawiana Klientowi przed zawarciem Umowy.</p>
                <p>6. Sprzedawca zwraca otrzymane od Klienta płatności, w tym koszt najtańszego zwykłego sposobu dostawy oferowanego dla danego zamówienia, niezwłocznie, nie później niż w terminie 14 dni od otrzymania oświadczenia o odstąpieniu. Sprzedawca może wstrzymać zwrot do chwili otrzymania Towaru albo dostarczenia dowodu jego odesłania - w zależności od tego, które zdarzenie nastąpi wcześniej.</p>

                <h2>§ 10. Wyjątki od prawa odstąpienia - Towary higieniczne i indywidualne</h2>
                <p><strong>Zasada dla wyrobów medycznych:</strong> Sam fakt, że produkt jest wyrobem medycznym albo ma kontakt z ciałem, nie wyłącza automatycznie prawa odstąpienia. Wyłączenie stosuje się tylko wtedy, gdy spełnione są przesłanki konkretnego wyjątku ustawowego.</p>
                <p>1. Prawo odstąpienia nie przysługuje w odniesieniu do Towaru nieprefabrykowanego, wyprodukowanego według specyfikacji Konsumenta lub służącego zaspokojeniu jego zindywidualizowanych potrzeb, zgodnie z art. 38 ust. 1 pkt 3 ustawy o prawach konsumenta. Może to dotyczyć w szczególności indywidualnie wykonywanych wkładek, wyrobów wykonywanych na miarę oraz Towarów modyfikowanych specjalnie dla Klienta. Sam wybór standardowego rozmiaru, koloru lub gotowego wariantu z katalogu nie przesądza o zastosowaniu tego wyjątku.</p>
                <p>2. Prawo odstąpienia nie przysługuje w odniesieniu do Towaru dostarczonego w zapieczętowanym opakowaniu, którego po otwarciu nie można zwrócić ze względu na ochronę zdrowia lub ze względów higienicznych, jeżeli opakowanie zostało otwarte po dostarczeniu - art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta.</p>
                <p>3. W przypadku Towarów oznaczonych w Sklepie jako objęte Zabezpieczeniem higienicznym Sprzedawca stosuje przed wysyłką zabezpieczenie, którego naruszenie jest widoczne. Klient jest informowany przed zakupem o skutkach jego naruszenia.</p>
                <p>4. Do kategorii, w których - zależnie od właściwości konkretnego Towaru i sposobu opakowania - może znaleźć zastosowanie wyjątek higieniczny, należą w szczególności: pończochy, podkolanówki i rajstopy kompresyjne, rękawy i inne wyroby uciskowe, wybrane ortezy, stabilizatory i pasy mające bezpośredni kontakt ze skórą, wybrane produkty sanitarne i inkontynencyjne, wyroby sterylne, opatrunkowe, jednorazowe oraz inne Towary, których bezpieczne ponowne wprowadzenie do obrotu po otwarciu nie jest możliwe ze względów ochrony zdrowia lub higieny. Lista ta nie stanowi automatycznego wyłączenia dla całej kategorii - decydują właściwości konkretnego Towaru, zapieczętowanie i informacja przekazana przed zakupem.</p>
                <p>5. W szczególności przy wyrobach kompresyjnych wymagających pomiaru Klient powinien przed otwarciem Zabezpieczenia higienicznego porównać oznaczenie na opakowaniu z zamówieniem i tabelą producenta. Naruszenie Zabezpieczenia higienicznego w Towarze spełniającym przesłanki art. 38 ust. 1 pkt 5 powoduje utratę prawa odstąpienia od Umowy w odniesieniu do tego Towaru.</p>
                <p>6. Powyższe wyłączenia nie ograniczają prawa do reklamacji Towaru niezgodnego z Umową, w szczególności gdy Sprzedawca dostarczył niewłaściwy model, rozmiar, klasę kompresji lub Towar jest wadliwy.</p>

                <h2>§ 11. Sprawdzenie Towaru a używanie - zmniejszenie wartości</h2>
                <p>1. Jeżeli prawo odstąpienia przysługuje, Konsument może sprawdzić Towar w zakresie koniecznym do stwierdzenia jego charakteru, cech i funkcjonowania. Nie oznacza to prawa do używania Towaru przez okres próbny w sposób odpowiadający normalnemu użytkowaniu.</p>
                <p>2. Konsument ponosi odpowiedzialność za zmniejszenie wartości Towaru wynikające z korzystania z niego w sposób wykraczający poza zakres konieczny do jego sprawdzenia, o ile został prawidłowo poinformowany o prawie odstąpienia.</p>
                <p>3. Przy ocenie zmniejszenia wartości Sprzedawca bierze pod uwagę stan konkretnego Towaru, rodzaj i intensywność używania, ślady noszenia, zabrudzenia, zapach, odkształcenia, uszkodzenia, brak elementów oraz - w Towarach mających kontakt z ciałem - zanieczyszczenia biologiczne lub higieniczne i możliwość bezpiecznego ponownego wprowadzenia Towaru do obrotu.</p>
                <p>4. Zmniejszenie wartości jest ustalane indywidualnie i powinno odpowiadać rzeczywistej utracie wartości handlowej Towaru. W szczególnie uzasadnionym przypadku, gdy wskutek używania wykraczającego poza konieczne sprawdzenie Towar obiektywnie utracił całą wartość handlową i nie może zostać ponownie sprzedany ani bezpiecznie przywrócony do obrotu, zmniejszenie wartości może odpowiadać pełnej wartości Towaru. Nie jest to potrącenie automatyczne.</p>
                <p>5. Sprzedawca może dokumentować stan zwróconego Towaru, w szczególności zdjęciami, protokołem przyjęcia, informacją producenta dotyczącą higienizacji, dezynfekcji, ponownego użycia lub trwałości oraz innymi obiektywnymi dowodami.</p>

                <h2>§ 12. Reklamacje - niezgodność Towaru z Umową</h2>
                <p>1. Sprzedawca odpowiada wobec Konsumenta i Przedsiębiorcy na prawach konsumenta za brak zgodności Towaru z Umową na zasadach określonych w rozdziale 5a ustawy o prawach konsumenta.</p>
                <p>2. Towar jest zgodny z Umową, jeżeli odpowiada w szczególności uzgodnionemu opisowi, rodzajowi, ilości, jakości, kompletności i funkcjonalności oraz nadaje się do szczególnego celu, o którym Klient poinformował Sprzedawcę najpóźniej przy zawarciu Umowy i który Sprzedawca zaakceptował.</p>
                <p>3. Sprzedawca ponosi odpowiedzialność za brak zgodności istniejący w chwili dostarczenia i ujawniony w ciągu dwóch lat od tej chwili, chyba że dłuższy termin wynika z przepisów lub właściwości Towaru. Zasady domniemania istnienia braku zgodności wynikają z obowiązujących przepisów.</p>
                <p>4. Jeżeli Towar jest niezgodny z Umową, Konsument może żądać naprawy lub wymiany. Sprzedawca może w przypadkach przewidzianych ustawą zaproponować drugi sposób doprowadzenia do zgodności albo odmówić naprawy i wymiany, jeżeli są niemożliwe lub wymagałyby nadmiernych kosztów.</p>
                <p>5. W przypadkach określonych w ustawie Konsument może żądać obniżenia ceny albo odstąpić od Umowy, w szczególności gdy Sprzedawca odmówił doprowadzenia Towaru do zgodności, nie dokonał tego prawidłowo, brak zgodności nadal występuje, jest istotny albo z okoliczności wynika, że Towar nie zostanie doprowadzony do zgodności w rozsądnym czasie lub bez nadmiernych niedogodności.</p>
                <p>6. Naprawa lub wymiana odbywa się w rozsądnym czasie i bez nadmiernych niedogodności dla Konsumenta, z uwzględnieniem charakteru Towaru i celu jego nabycia. Koszty naprawy lub wymiany, w tym koszty przesyłki, ponosi Sprzedawca w zakresie wymaganym przez prawo.</p>
                <p>7. Reklamację można złożyć e-mailem lub pisemnie na dane kontaktowe Sprzedawcy. Dla sprawnego rozpatrzenia zaleca się podanie numeru zamówienia lub innego dowodu zakupu, nazwy Towaru, opisu problemu, daty jego ujawnienia i żądania Klienta. Brak paragonu nie jest samodzielną podstawą do odmowy przyjęcia reklamacji, jeżeli zakup może być wykazany w inny sposób.</p>
                <p>8. Sprzedawca udziela odpowiedzi na reklamację Konsumenta w terminie 14 dni od dnia jej otrzymania. Brak odpowiedzi w tym terminie oznacza uznanie reklamacji. Odpowiedź jest przekazywana na papierze lub innym trwałym nośniku.</p>
                <p>9. W przypadku wyrobów kompresyjnych i innych produktów eksploatacyjnych naturalne zużycie wynikające z normalnego używania zgodnego z instrukcją nie stanowi samo przez się braku zgodności z Umową. Ocena zawsze zależy jednak od właściwości konkretnego Towaru, zapewnień producenta i Sprzedawcy, oczekiwanej trwałości oraz okoliczności sprawy.</p>
                <p>10. Gwarancja producenta lub dystrybutora, jeżeli została udzielona, jest niezależna od ustawowej odpowiedzialności Sprzedawcy za zgodność Towaru z Umową i nie ogranicza praw Konsumenta.</p>

                <h2>§ 13. Przesyłanie Towaru w reklamacji i zwrocie</h2>
                <p>1. Towar odsyłany w związku z odstąpieniem lub reklamacją należy zabezpieczyć przed uszkodzeniem w transporcie. Dołączenie formularza może ułatwić identyfikację przesyłki, ale skorzystanie z formularza nie jest warunkiem wykonania ustawowego prawa.</p>
                <p>2. W reklamacji z tytułu niezgodności Towaru z Umową Konsument udostępnia Towar Sprzedawcy, a Sprzedawca odbiera go na swój koszt w przypadkach wymaganych przepisami. Sposób przekazania Towaru może zostać uzgodniony z obsługą Sklepu.</p>
                <p>3. Towar zwracany w ramach odstąpienia od Umowy jest odsyłany na koszt Klienta, z zastrzeżeniem wyjątków ustawowych i sytuacji, gdy Sprzedawca dobrowolnie zaoferuje odbiór na swój koszt.</p>

                <h2>§ 14. Zakupy przedsiębiorców</h2>
                <p>1. Postanowienia Regulaminu przyznające szczególne prawa Konsumentowi stosuje się także do Przedsiębiorcy na prawach konsumenta w zakresie, w jakim wynika to z art. 7aa ustawy o prawach konsumenta i innych obowiązujących przepisów.</p>
                <p>2. Do Klienta będącego przedsiębiorcą, dla którego Umowa ma charakter zawodowy, nie stosuje się przepisów konsumenckich, chyba że bezwzględnie obowiązujące przepisy stanowią inaczej. Odpowiedzialność Sprzedawcy z tytułu rękojmi w relacjach zawodowych B2B może zostać wyłączona w najszerszym zakresie dopuszczalnym przez prawo; wyłączenie nie działa w przypadkach, w których ustawa na to nie zezwala, w szczególności przy podstępnym zatajeniu wady.</p>

                <h2>§ 15. Opinie o Towarach</h2>
                <p>1. Jeżeli Sklep udostępnia opinie o Towarach, obok opinii lub w miejscu łatwo dostępnym dla Klienta zamieszcza informację, czy i w jaki sposób Sprzedawca zapewnia, aby publikowane opinie pochodziły od osób, które używały lub nabyły dany Towar.</p>
                <p>2. Sprzedawca nie oznacza opinii jako „zweryfikowanej”, „potwierdzonej zakupem” ani równoważnym sformułowaniem, jeżeli nie zastosował rzeczywistego i proporcjonalnego mechanizmu weryfikacji.</p>

                <h2>§ 16. Dane osobowe, prywatność i cookies</h2>
                <p>1. Zasady przetwarzania danych osobowych, podstawy prawne, okresy przechowywania, prawa osób oraz dane kontaktowe administratora są opisane w aktualnej <a href="{{ route('legal.privacy') }}">Polityce prywatności</a> dostępnej w Sklepie.</p>
                <p>2. Zasady wykorzystywania plików cookies i podobnych technologii oraz zarządzania zgodami są opisane w <a href="{{ route('legal.cookie-policy') }}">Polityce cookies</a> lub narzędziu zarządzania zgodami dostępnym w Sklepie.</p>
                <p>3. Akceptacja Regulaminu nie oznacza wyrażenia zgody marketingowej. Zgody na marketing, newsletter lub inne działania wymagające zgody są zbierane odrębnie, jeżeli Sprzedawca z nich korzysta.</p>

                <h2>§ 17. Pozasądowe rozwiązywanie sporów</h2>
                <p>1. Konsument może korzystać z pomocy miejskiego lub powiatowego rzecznika konsumentów oraz z właściwych podmiotów uprawnionych do pozasądowego rozwiązywania sporów konsumenckich. Aktualne informacje o systemie ADR są dostępne na stronach UOKiK.</p>
                <p>2. Jeżeli po rozpatrzeniu reklamacji spór nie zostanie rozwiązany, Sprzedawca przekazuje Konsumentowi na papierze lub innym trwałym nośniku oświadczenie, czy zgadza się na udział w odpowiednim postępowaniu ADR, czy odmawia udziału; w przypadku zgody wskazuje właściwy podmiot uprawniony.</p>
                <p>3. W Regulaminie nie podaje się odnośnika do unijnej platformy ODR, ponieważ platforma została zlikwidowana, a rozporządzenie ustanawiające ją utraciło moc 20 lipca 2025 r.</p>

                <h2>§ 18. Zmiany Regulaminu i postanowienia końcowe</h2>
                <p>1. Do zamówienia stosuje się wersję Regulaminu obowiązującą w chwili złożenia zamówienia. Późniejsza zmiana Regulaminu nie zmienia warunków wcześniej zawartych Umów.</p>
                <p>2. Sprzedawca może zmienić Regulamin na przyszłość z ważnej przyczyny, w szczególności zmiany prawa, sposobów płatności lub dostawy, funkcjonalności Sklepu, wymogów bezpieczeństwa albo zmiany danych Sprzedawcy. Jeżeli zmiana dotyczy trwającej usługi Konta, użytkownik otrzyma informację na trwałym nośniku z odpowiednim wyprzedzeniem, chyba że natychmiastowa zmiana jest konieczna z przyczyn prawnych lub bezpieczeństwa.</p>
                <p>3. W sprawach nieuregulowanych zastosowanie mają obowiązujące przepisy prawa polskiego, w szczególności ustawa z 30 maja 2014 r. o prawach konsumenta, Kodeks cywilny, ustawa z 18 lipca 2002 r. o świadczeniu usług drogą elektroniczną oraz - w zakresie właściwym dla asortymentu - ustawa z 7 kwietnia 2022 r. o wyrobach medycznych i bezpośrednio stosowane przepisy Unii Europejskiej dotyczące wyrobów medycznych.</p>
                <p>4. Jeżeli którekolwiek postanowienie Regulaminu okaże się mniej korzystne dla Konsumenta niż bezwzględnie obowiązujący przepis prawa, stosuje się ten przepis, a pozostała część Regulaminu pozostaje w mocy.</p>
                <p>5. Regulamin wchodzi w życie dnia {{ $effectiveDate }}.</p>

                <h2>Załącznik nr 1 - wzór oświadczenia o odstąpieniu od umowy</h2>
                <p>Formularz można wykorzystać, ale nie jest to obowiązkowe.</p>
                <p><strong>Adresat:</strong> {{ $seller['company_name'] }}, ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}, e-mail: {{ $seller['email'] }}</p>
                <p>Niniejszym informuję/informujemy o odstąpieniu od umowy sprzedaży następujących Towarów:</p>
                <p>.................................................................................................................................</p>
                <p>Numer zamówienia (jeżeli jest znany): ...................................................................................</p>
                <p>Data zawarcia umowy / data odbioru Towaru: ........................................................................</p>
                <p>Imię i nazwisko Konsumenta: .................................................................................................</p>
                <p>Adres Konsumenta: ................................................................................................................</p>
                <p>Adres e-mail / telefon (opcjonalnie, dla ułatwienia kontaktu): ....................................................</p>
                <p>Numer rachunku do zwrotu - wyłącznie jeżeli uzgodniono zwrot inną metodą niż pierwotna płatność: .................................................................................................................................</p>
                <p>Data: ............................................... Podpis (tylko formularz papierowy): ...............................................</p>
                <p><strong>Uwaga:</strong> W przypadku Towarów z naruszonym Zabezpieczeniem higienicznym oraz Towarów indywidualnych prawo odstąpienia może nie przysługiwać na zasadach opisanych w § 10 Regulaminu. Nie ogranicza to prawa do reklamacji Towaru niezgodnego z Umową.</p>
            </div>
        </div>
    </div>
@endsection
