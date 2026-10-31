#!/usr/bin/env python3
"""Reproduce the frozen editorial manifest; never connects to the shop database."""
import argparse,hashlib,json,re
from collections import Counter
from pathlib import Path
from manual_facts import FACTS

def plain(s):
    return re.sub(r'\s+', ' ', s or '').strip()

def facts_for(r):
    if r['id'] in FACTS:
        text,quote=FACTS[r['id']]
        assert quote.casefold() in r['description'].casefold(), (r['id'],quote)
        return [(text,quote)]
    body=re.split(r'Sposób użycia|Dlaczego wybrać specjalistyczne|Niezbędnik opieki|Parametry produktu|Dostępne warianty|Tabela rozmiarów',r['description'])[0]; facts=[]
    def add(pattern,text,flags=re.I):
        m=re.search(pattern,body,flags)
        if m and re.search(r'(?:nie zawiera(?:ją)?|bez)\s*$',body[max(0,m.start()-25):m.start()],re.I):return
        if text.startswith(('Z kwasem','Z masłem','Z witaminą','Z olejem','Z ekstraktem','Z prowitaminą','Z tlenkiem','Z pantenolem','Z alantoiną','Z gliceryną')) and not re.search(r'krem|balsam|mydło|żel|piank|płyn|olejek|szampon|maść|emulsj|puder',r['name'],re.I): return
        if text=='Maszynowo wykonana baza na tresach.' and any(f[0]=='Baza na tresach z mikroskórą.' for f in facts):return
        if m and text.casefold() not in r['name'].casefold(): facts.append((text,m.group(0)))
    # Restrict fabric composition to one explicit, contiguous percentage list.
    if r['external_source']=='eldan':
        m=re.search(r'skład(?:\s+tkaniny)?\s*:\s*((?:\d+(?:[,.]\d+)?%\s*(?:poliester|bawełna|spandex|elastan|wiskoza|poliamid|lyocell|tencel)\s*[,/;+]?\s*){1,4})',body,re.I)
        if m and sum(float(v.replace(',','.')) for v in re.findall(r'(\d+(?:[,.]\d+)?)%',m[1]))==100:
            value=plain(m[1]).rstrip(',/;+ ');facts.append(('Skład: '+value+'.',m[0]))
    if r['external_source'] in ['eldan','wojdak']:
        for pattern,text in [
            (r'kopertowy dekolt w szpic','Kopertowy dekolt w szpic.'),
            (r'półokrągły dekolt','Półokrągły dekolt.'),
            (r'dekolt[^.]{0,25}(?:litery V|w kształcie V|w szpic)','Dekolt w szpic.'),
            (r'rozcięcia boczne|boczne rozcięcia','Boczne rozcięcia.'),
            (r'(?:dwie|2)\s+(?:pojemne\s+|symetryczne, naszyte\s+)?kieszenie\s+(?:dolne|na dole)','Dwie dolne kieszenie.'),
            (r'kiesze(?:nie|nki|ń)[^.]{0,25}(?:na zamek|na suwak)','Kieszeń zapinana na zamek.'),
            (r'kieszeń typu „kangurka”','Kieszeń typu kangurka.'),
            (r'zapinana na ozdobne zatrzaski','Zapięcie na ozdobne zatrzaski.'),
            (r'krój[^.]{0,12}taliowany|taliowany krój|bluza jest taliowana','Taliowany krój.'),
            (r'skór(?:a|y) naturaln(?:a|ej)','Skóra naturalna.'),
            (r'(?:wierzch i wysciółka|wierzch i wyściółka) wykonan[ae] ze skóry','Skórzany wierzch i wyściółka.'),
            (r'(?:białej|granatowej|naturalnej|zamszowej|welurowej) skóry|skóry zamszowej','Elementy skórzane.'),
            (r'(?:okolice palców są zakryte|okolice palców zostały starannie zakryte|zakryte palce)','Zakryte palce.'),
            (r'(?:okolice palców są odkryte|ma odkryte palce)','Odkryte palce.'),
            (r'(?:cholewka buta jest perforowana|cholewa buta jest wyposażona w perforację)','Perforowana cholewka.'),
            (r'(?:regulowane paski z klamrami)','Regulowane paski z klamrami.'),
            (r'(?:guma wszyta na całym obwodzie pasa|gumę ze sznurkiem)','Elastyczny pas.'),
            (r'wewnętrzne troczki','Wewnętrzne troczki do regulacji.'),
            (r'(?:zapinany jest na trwałe, nierdzewne springi \(napy\)|zapinany na nierdzewne springi \(napy\))','Zapięcie na nierdzewne napy.'),
            (r'zapinanie z przodu na zamek i guzik','Zapięcie na zamek i guzik.'),
            (r'lekko zwężane nogawki','Lekko zwężane nogawki.'),
            (r'sandały w kolorze białym','Sandały w kolorze białym.'),
            (r'(?:ozdobne napy|perłowe napy)','Ozdobne napy.'),
            (r'(?:zapinana|zapinany|zapinane) na (?:wygodne |praktyczne )?napy|kroju na napy','Zapięcie na napy.'),
            (r'(?:zapinana z tyłu na zamek|krytym zamkiem z tyłu|zapinana z tyłu na dyskretny, kryty zamek|zapinanie z tyłu na kryty zamek)','Zamek z tyłu.'),
            (r'dwie kieszonki|dwie praktyczne kieszenie|dwie praktyczne kieszonki|dw i?e kieszenie','Dwie kieszenie.'),
            (r'cztery (?:praktyczne )?kieszenie','Cztery kieszenie.'),
            (r'(?:taliowany fason)','Taliowany fason.'),
            (r'(?:z okrągłym dekoltem)','Okrągły dekolt.'),
            (r'(?:elastycznej dzianiny fitness|dzianiny poliestrowo-bawełnianej z dodatkiem lycry)','Elastyczna dzianina.'),
            (r'(?:elastycznej gumce z troczkami|w pasie sznurek)','Regulacja w pasie sznurkiem.'),
            (r'(?:dw ie|dwie) kieszenie biodrowe','Dwie kieszenie biodrowe.'),
            (r'(?:wyższym stanem i długością 7/8)','Wyższy stan i długość 7/8.'),
            (r'imitujące drewniaki podeszwy','Podeszwa imitująca drewniaki.'),
            (r'buty na nieco wyższej podeszwie','Podwyższona podeszwa.'),
            (r'połączeniem mikrofibry i skóry welurowej','Mikrofibra i skóra welurowa.'),
            (r'pianki „memory foam','Wkładka z pianki memory foam.'),
            (r'szeroki przód i elastyczna podeszwa','Szeroki przód i elastyczna podeszwa.'),
            (r'zerowemu dropowi','Zerowy drop.'),
            (r'nogawki proste zwężające się ku dołowi','Proste nogawki zwężane ku dołowi.'),
            (r'profilowan[ayą] wkładk[aię]|wyprofilowan[ayą] wkładk[aię]','Profilowana wkładka.'),
            (r'(?:podeszwa|podeszwie)[^.]{0,20}antypoślizgow','Antypoślizgowa podeszwa.'),
        ]:add(pattern,text)
    if r['external_source']=='peruka':
        for pattern,text in [
            (r'wykonanie maszynowe na tresach oraz mikroskóra na (?:górnej części|czubku) głowy','Baza na tresach z mikroskórą.'),
            (r'przód peruki został wykonany ręcznie, natomiast tył maszynowo','Ręcznie wykonany przód i maszynowy tył bazy.'),
            (r'(?:włosy|pasma) (?:dziewicze|virgin)|nie poddawan[ey] (?:wcześniej )?obróbce chemicznej','Włosy virgin.'),
            (r'całkowite wykonanie ręczne na tiulu|wykonanie ręczne na tiulu','Ręcznie wykonana baza na tiulu.'),
            (r'wykonanie maszynowe na tresach','Maszynowo wykonana baza na tresach.'),
            (r'mikroskóra na linii przedziałka','Mikroskóra na linii przedziałka.'),
            (r'linia rozmyta z przodu','Rozmyta linia włosów z przodu.'),
            (r'długa cieniowana fryzura','Długa, cieniowana fryzura.'),
            (r'łuska włosów jest zachowana','Zachowana łuska włosów.'),
            (r'monofilament \(góra\)','Monofilament na górze bazy.'),
        ]:add(pattern,text)
    if r['external_source'] in ['vaya','seni24']:
        # Construction only. No efficacy, diagnostic, therapeutic or availability claims.
        rules=[
            (r'(?:wkład chłonny zabezpieczony jest mikrosiateczką|chłonnym wkładem[^.]{0,35}elastycznej włókniny)','Wkład chłonny i elastyczna włóknina.'),
            (r'41% bawełna, 59% poliamid','Skład: 41% bawełna, 59% poliamid.'),
            (r'100% bawełn(?:a|y)|w 100% z bawełny','Bawełna w składzie materiału.'),
            (r'65% wiskoza i 35% poliester','Skład: 65% wiskoza, 35% poliester.'),
            (r'brzegi kompresów podwijane','Podwijane brzegi kompresów.'),
            (r'hydrofilowa pulpa celulozowa','Wkład z hydrofilowej pulpy celulozowej.'),
            (r'(?<!nie)(?:każdy gazik został zapakowany w pojedynczą saszetkę)','Każdy gazik w osobnej saszetce.'),
            (r'70% alkoholem izopropylowym','Nasączenie 70% alkoholem izopropylowym.'),
            (r'uszczelce na całym obwodzie opatrunku','Uszczelnienie na całym obwodzie opatrunku.'),
            (r'siatka opatrunkowa jest wygodnym zamiennikiem dla bandaża lub przylepca','Siatka do mocowania opatrunków.'),
            (r'(?:bez zawartości pudru|niepudrowan[aye])','Bez pudru.'),
            (r'(?:z mankietem rolowanym|mankiet: rolowany)','Rolowany mankiet.'),
            (r'teksturowana na końcach palców','Teksturowane końce palców.'),
            (r'(?:wykonany|wykonana|wykonane) z silikonu','Wykonanie z silikonu.'),
            (r'(?:wykonany|wykonana|wykonane) z teflonu','Wykonanie z teflonu.'),
            (r'(?:wykonany|wykonana|wykonane) z lateksu','Wykonanie z lateksu.'),
            (r'(?:dwudrożny|dwudrożne|dwudrożna)','Konstrukcja dwudrożna.'),
            (r'(?:trójdrożny|trójdrożne|trójdrożna)','Konstrukcja trójdrożna.'),
            (r'(?:z portem do iniekcji|port iniekcyjny)','Port do iniekcji.'),
            (r'(?:z kranikiem spustowym|kranik spustowy)','Kranik spustowy.'),
            (r'(?:z zaworem antyzwrotnym|zawór antyzwrotny)','Zawór antyzwrotny.'),
            (r'(?:folię ochronną|folia ochronna|folii ochronnej)','Warstwa folii ochronnej.'),
            (r'(?:z zamknięciem strunowym|zamknięcie strunowe)','Zamknięcie strunowe.'),
            (r'(?:miarka[^.]{0,15}na opakowaniu|opakowanie[^.]{0,15}miarkę)','Opakowanie z miarką.'),
            (r'70% wiskoz[ay][, +/]+30% polieste?r','Skład: 70% wiskoza, 30% poliester.'),
            (r'(?<!nie)(?:bez zawartości lateksu|nie zawiera(?:ją)? lateksu|bezlateksow[aye])','Bez lateksu.'),
            (r'(?:nie są pudrowane|bezpudrow[aye]|pudrowanie: brak)','Bez pudru.'),
            (r'końcówk[ęaą] luer[ -]lock','Końcówka Luer Lock.'),
            (r'portem pobocznym, skrzydełkami oraz 2 liniami RTG','Port poboczny, skrzydełka i 2 linie RTG.'),
            (r'(?:bez spłukiwania|nie wymaga spłukiwania)','Do stosowania bez spłukiwania.'),
            (r'(?:bez konieczności stosowania dodatkowych zapinek|nie wymagając stosowania dodatkowych zapinek)','Mocowanie bez dodatkowych zapinek.'),
            (r'(?:z tkanymi brzegami|tkane brzegi)','Tkane brzegi.'),
            (r'(?<!nie)(?:każda kaniula zapakowana indywidualnie|każda igła jest sterylna i zapakowana oddzielnie)','Każda sztuka pakowana oddzielnie.'),
            (r'(?:można rwać ręcznie|rwany za pomocą rąk|możliwość ręcznego odrywania)','Możliwość ręcznego odrywania.'),
            (r'(?:bez dodatku alkoholu|nie zawiera alkoholu|bez alkoholu)','Bez alkoholu.'),
            (r'(?:bez dodatku substancji zapachowych|bez substancji zapachowych|bezzapachow[ay])','Bez substancji zapachowych.'),
            (r'(?:wkład chłonny z celulozy|wkładem chłonnym z celulozy)','Celulozowy wkład chłonny.'),
            
            
            (r'(?:przylepiec[^.]{0,30}papierow|papierow[ay] podłoż)','Papierowe podłoże.'),
            (r'(?:na podłożu z jedwabiu|podłoże z jedwabiu)','Jedwabne podłoże.'),
            (r'(?:zawiera[^.]{0,15}tlenek cynku|z tlenkiem cynku)','Z tlenkiem cynku.'),
            (r'(?:zawiera[^.]{0,15}pantenol|z pantenolem)','Z pantenolem.'),
            (r'(?:z ekstraktem z aloesu|zawiera aloes|ekstrakt z liści aloesu)','Z ekstraktem z aloesu.'),
            (r'(?:wykonana ze stali nierdzewnej|wykonane ze stali nierdzewnej)','Wykonanie ze stali nierdzewnej.'),
            (r'cylinder jest przezroczysty','Przezroczysty cylinder.'),
            (r'ruchomą osłonę','Ruchoma osłona igły.'),
            (r'(?:końcówka luer-lock|końcówkę luer-lock)','Końcówka Luer Lock.'),
            (r'(?:komorę kroplową|komora kroplowa)','Komora kroplowa.'),
            (r'(?:z filtrem hydrofobowym|filtr hydrofobowy)','Filtr hydrofobowy.'),
            (r'(?:z powłoką silikonową|silikonowa powłoka)','Powłoka silikonowa.'),
            (r'(?:miękkie zauszniki|miękkimi zausznikami)','Miękkie zauszniki.'),
            (r'(?:elastyczny zacisk na nos|modelowany zacisk na nos|regulowany zacisk na nos)','Regulowany zacisk na nos.'),
            (r'(?:warstwę nieprzemakalnej folii|nieprzemakalna folia|nieprzemakalnej folii)','Nieprzemakalna warstwa foliowa.'),
            (r'(?:z perforacją|posiadają perforację|perforacja ułatwia)','Perforacja ułatwiająca oddzielanie odcinków.'),
            (r'(?:z białej porowatej włókniny)','Podłoże z białej porowatej włókniny.'),
            (r'ze sztucznego jedwabiu','Podłoże ze sztucznego jedwabiu.'),
            (r'z przeźroczystej mikroporowatej folii','Podłoże z przezroczystej mikroporowatej folii.'),
            (r'(?:bez pudrowe|bez pudru|nie pudrowane)','Bez pudru.'),
            (r'(?:bez zapinki|bez zapinek)','Bez zapinki.'),
            (r'warstwą lateksu','Warstwa lateksu.'),
            (r'(?:z olejem lnianym|zawiera olej lniany|olej lniany –)','Z olejem lnianym.'),
            (r'(?:z ekstraktem z owsa|ekstrakt z owsa)','Z ekstraktem z owsa.'),
            (r'(?:z ekstraktem z jagód goji|ekstrakt z jagód goji)','Z ekstraktem z jagód goji.'),
            (r'(?:z ekstraktem ze świetlika|ekstrakt ze świetlika)','Z ekstraktem ze świetlika.'),
            (r'(?:z ekstraktem z zielonej herbaty|ekstrakt z zielonej herbaty)','Z ekstraktem z zielonej herbaty.'),
            (r'(?:zawiera alantoinę|z alantoiną|alantoina –)','Z alantoiną.'),
            (r'(?:z masłem shea|masło shea|masła shea)','Z masłem shea.'),
            (r'(?:z kwasem hialuronowym|kwas hialuronowy|kwasu hialuronowego)','Z kwasem hialuronowym.'),
            (r'(?:z olejem migdałowym|olej ze słodkich migdałów|olejek migdałowy)','Z olejem migdałowym.'),
            (r'(?:z olejem arganowym|olej arganowy|oleju arganowego)','Z olejem arganowym.'),
            (r'(?:z ekstraktem z rumianku|ekstrakt z rumianku)','Z ekstraktem z rumianku.'),
            (r'(?:z ekstraktem z nagietka|ekstrakt z nagietka)','Z ekstraktem z nagietka.'),
            (r'(?:z prowitaminą B5|prowitamina B5)','Z prowitaminą B5.'),
            (r'(?:z witaminą E|witamina E)','Z witaminą E.'),
            (r'(?:z gliceryną|zawiera glicerynę)','Z gliceryną.'),
            (r'(?:posiada tubkę ułatwiającą nakładanie punktowe)','Tubka do aplikacji punktowej.'),
            (r'(?:w postaci przezroczystego żelu)','Postać przezroczystego żelu.'),
            (r'(?:z otworem na kciuk|otwór na kciuk)','Otwór na kciuk.'),
            (r'cienki i samoprzylepny opatrunek silikonowy','Cienka, samoprzylepna konstrukcja silikonowa.'),
            (r'(?:z dozownikiem|dozownik z pompką|dozownik w postaci pompki)','Opakowanie z dozownikiem.'),
            (r'(?:z atomizerem|wygodny atomizer)','Opakowanie z atomizerem.'),
            (r'(?:z zawartością gliceryny|gliceryna -|glicerynę)','Z gliceryną.'),
            (r'(?:zawiera[^.]{0,10}pantenol|pantenol -|pantenol –)','Z pantenolem.'),
            (r'(?:olej lniany -|olej lniany –|oleju lnianego)','Z olejem lnianym.'),
            (r'(?:kwas hialuronowy|kwasu hialuronowego)','Z kwasem hialuronowym.'),
            (r'(?:ekstrakt z arbuza|ekstraktem z arbuza)','Z ekstraktem z arbuza.'),
            (r'(?:nie są perfumowane|nie zawiera substancji zapachowych)','Bez substancji zapachowych.'),
            (r'(?:przeznaczony do bardzo wrażliwej cery|pielęgnacji cery wrażliwej)','Do pielęgnacji cery wrażliwej.'),
            (r'(?:do codziennej pielęgnacji skóry suchej i wrażliwej|demakijażu skóry suchej i wrażliwej)','Do skóry suchej i wrażliwej.'),
            (r'(?:z cerą mieszaną i tłustą)','Do cery mieszanej i tłustej.'),
            (r'(?:przeznaczony do codziennego stosowania)','Do codziennego stosowania.'),
            (r'(?:do codziennej higieny rąk i całego ciała)','Do codziennej higieny rąk i ciała.'),
            (r'(?:przeznaczone do mycia powierzchni szklanych|przeznaczony do mycia powierzchni szklanych)','Do powierzchni szklanych i zmywalnych.'),
            (r'(?:zmywarek domowych)','Do zmywarek domowych.'),
            (r'(?:podwójnego, szczelnego zamknięcia)','Podwójne zamknięcie.'),
            (r'(?:wypełniony żelem|wypełnienie żelowe)','Żelowe wypełnienie.'),
            (r'(?:w postaci żelu|w formie żelu)','Postać żelu.'),
            (r'(?:trójwarstwowa konstrukcja|trzywarstwowa konstrukcja)','Konstrukcja trójwarstwowa.'),
            (r'(?:czterowarstwowa konstrukcja|cztery warstwy)','Konstrukcja czterowarstwowa.'),
            (r'(?:dwuwarstwowa konstrukcja|dwie warstwy bibuły)','Dwie warstwy bibuły.'),
            (r'(?:wyprodukowano metodą tradycyjną)','Wytwarzane metodą tradycyjną.'),
            (r'(?:kompleks koziego mleka)','Z kompleksem koziego mleka.'),
            (r'(?:ekstrakt z kory dębu|wyciąg z kory dębu)','Z ekstraktem z kory dębu.'),
            (r'(?:wkład chłonny z superabsorbentem|superabsorbent)','Wkład z superabsorbentem.'),
            (r'(?:wykonana z pianki poliuretanowej|wykonany z pianki poliuretanowej)','Wykonanie z pianki poliuretanowej.'),
            (r'(?:wykonany z silikonu medycznego|wykonana z silikonu medycznego)','Wykonanie z silikonu medycznego.'),
            (r'wykonane są z 17-nitkowej gazy bawełnianej','Wykonanie z 17-nitkowej gazy bawełnianej.'),
            (r'przędzy z włókien poliestrowych / wiskozowych','Przędza poliestrowo-wiskozowa.'),
            (r'wkład, który został pokryty siateczką','Wkład pokryty siateczką.'),
            (r'wyraźnie zaznaczoną perforację','Perforacja do oddzielania odcinków.'),
            (r'składane, wyjmowane pojedynczo z opakowania','Podkłady wyjmowane pojedynczo z opakowania.'),
            (r'dwuwarstwowej bibuły','Dwuwarstwowa bibuła.'),
            (r'potrójnie ścięte, posmarowane silikonowym lubrykantem ostrze ze stali nierdzewnej','Potrójnie ścięte ostrze ze stali nierdzewnej.'),
            (r'klarownie przezroczysty cylinder','Przezroczysty cylinder.'),
            (r'miękkiego, elastycznego silikonu','Miękki, elastyczny silikon.'),
            (r'biała, porowata i elastyczna włóknina','Podłoże z białej porowatej włókniny.'),
            (r'elastycznej, porowatej włókniny','Podłoże z elastycznej porowatej włókniny.'),
            (r'pojedynczej warstwy bibuły i foli','Warstwa bibuły i folii.'),
            (r'trójpłaszczyznowe ostrze ze stali nierdzewnej','Trójpłaszczyznowe ostrze ze stali nierdzewnej.'),
            (r'dwupłaszczyznowe ostrze','Dwupłaszczyznowe ostrze.'),
            (r'przymocowane w rogach gumki mocujące','Gumki mocujące w rogach.'),
            (r'możliwość przerwania bez konieczności użycia nożyczek','Możliwość ręcznego przerywania.'),
            (r'nierdzewnej stali chromowo-niklowej','Wykonanie ze stali chromowo-niklowej.'),
            (r'nie zawiera żadnych lateksowych elementów','Bez elementów lateksowych.'),
            (r'pakowana podwójnie w opakowania typu blister lub w torebki','Podwójne pakowanie w blistry lub torebki.'),
            (r'każda strzykawka wypełniona lubrykantem OneGel posiada oddzielne opakowanie','Każda strzykawka w osobnym opakowaniu.'),
            (r'przezroczystą obudowę i końcówkę luer','Przezroczysta obudowa i końcówka Luer.'),
            (r'bawełna frotte \+ laminowany poliester','Bawełna frotte i laminowany poliester.'),
            (r'pokryty warstwą silikonu','Powłoka silikonowa.'),
            (r'z blachy aluminiowej i gąbki','Blacha aluminiowa i gąbka.'),
            (r'owalnym kształcie','Owalny kształt.'),
            (r'otworem z tyłu|otwór z tyłu','Otwór z tyłu.'),
            (r'zamkniętej konstrukcji','Zamknięta konstrukcja.'),
            (r'ścięty pod kątem|ścięta pod kątem','Końcówka ścięta pod kątem.'),
            (r'ostrzon[ey] trójpłaszczyznowo','Ostrze szlifowane w trzech płaszczyznach.'),
            (r'cienkościennymi igłami','Cienkościenna konstrukcja igły.'),
            (r'spienionej pianki poliuretanowej|pianki poliuretanowej','Pianka poliuretanowa w konstrukcji.'),
            (r'opatrunek wykonany z hydrofibry','Wykonanie z hydrofibry.'),
            (r'ręcznego mycia naczyń','Do ręcznego mycia naczyń.'),
            (r'w postaci koncentratu','Postać koncentratu.'),
            (r'formuła żelowa|żelowa konsystencja','Żelowa konsystencja.'),
            (r'żelowej konsystencji','Żelowa konsystencja.'),
            (r'nie zawiera mydła, ani substancji zapachowych','Bez mydła i substancji zapachowych.'),
            (r'cienka poliuretanowa warstwa','Cienka warstwa poliuretanowa.'),
            (r'cienki, elastyczny opatrunek piankowy','Cienka, elastyczna konstrukcja piankowa.'),
            (r'profilowaną końcówką','Profilowana końcówka butelki.'),
            (r'materiał stazy: nylon materiał klamry: plastik','Nylonowa taśma i plastikowa klamra.'),
            (r'ekstrakt z koziego mleka','Z ekstraktem z koziego mleka.'),
            (r'proteiny jedwabiu oraz mleczko bawełniane','Proteiny jedwabiu i mleczko bawełniane.'),
            (r'nie zawiera barwników, środków zapachowych i konserwujących','Bez barwników, środków zapachowych i konserwujących.'),
            (r'3-częściowa komorę','Komora trzyczęściowa.'),
            (r'z dodatkiem Lycry','Z dodatkiem Lycry.'),
            (r'wzmocnione pięty i palce','Wzmocnione pięty i palce.'),
            (r'nienasycone kwasy tłuszczowe','Z nienasyconymi kwasami tłuszczowymi.'),
            (r'60% PET Politereftalan etylenu, 40% wiskoza','Materiał chusteczek: 60% PET, 40% wiskoza.'),
            (r'(?:polimerem superchłonnym \(SAP\)|superchłonny polimer SAP)','Wkład chłonny z polimerem SAP.'),
            (r'(?:gumki mocujące|gumkami mocującymi)','Gumki mocujące do materaca.'),
            (r'powłoka składa się z cząstek złota, srebra i palladu','Powłoka z cząstek złota, srebra i palladu.'),
            (r'tkaniny wiskozowej o niskiej przyczepności','Tkanina wiskozowa o niskiej przyczepności.'),
            (r'silikonowa perforowana warstwa','Perforowana warstwa silikonowa.'),
            (r'nie zawierają barwników i substancji zapachowych','Bez barwników i substancji zapachowych.'),
            (r'plastikowe komponenty wykonane z ABS','Komponenty z tworzywa ABS.'),
            (r'papierowe typu peel-pack','Papierowe opakowanie peel-pack.'),
            (r'wyraźną miarkę','Wyraźna miarka na cylindrze.'),
            (r'przezroczysty cylinder','Przezroczysty cylinder.'),
            (r'wykonana z polipropylenu, wolna od PCV','Polipropylen bez PCV.'),
            (r'ożebrowaną strukturę strzykawki i czarna miarkę','Ożebrowana konstrukcja i czarna miarka.'),
            (r'każda sztuka jest pakowana pojedynczo|pakowane pojedynczo igły','Indywidualne pakowanie.'),
            (r'produkt jednorazowego użytku|wyrobem jednorazowym','Produkt jednorazowego użytku.'),
            (r'produkt wielokrotnego użytku','Produkt wielokrotnego użytku.'),
            (r'(?:boczne skrzydełka|elastyczne skrzydełka)','Boczne skrzydełka.'),
            (r'cienkimi ściankami','Cienkie ścianki igły.'),
            (r'ostry lancet, wielokrotnie szlifowany','Wielokrotnie szlifowane ostrze.'),
            (r'wielokrotnie ostrzone w procesie produkcji|wielokrotnie ostrzone','Wielokrotnie ostrzone igły.'),
            (r'wkładki ze specjalną pianką|warstwa pianki','Warstwa pianki w konstrukcji.'),
            (r'podłoże poliuretanowe|warstwa poliuretanowa','Warstwa poliuretanowa.'),
            (r'(?:z alginianu wapnia|alginian wapnia)','Z alginianem wapnia.'),
            (r'karboksymetylocelulozy|karboksymetyloceluloza','Z karboksymetylocelulozą.'),
            (r'wiskoz[ay] nasączon[ay]','Nasączona tkanina wiskozowa.'),
            (r'włókniny polipropylenowej','Włóknina polipropylenowa.'),
            (r'pokryte hydrożelem|pokryty hydrożelem','Powłoka hydrożelowa.'),
            (r'(?:folii poliuretanowej|folia poliuretanowa)','Folia poliuretanowa w konstrukcji.'),
            (r'nie zawierają uczulającego lateksu','Bez lateksu.'),
            (r'neutralne pH|pH neutralne','Formuła o neutralnym pH.'),
            (r'(?:nadtlenku wodoru|nadtlenek wodoru)','Z nadtlenkiem wodoru.'),
            (r'olejek lawendowy|olejkiem lawendowym','Z olejkiem lawendowym.'),
            (r'olejkiem eukaliptusowym|olejek eukaliptusowy','Z olejkiem eukaliptusowym.'),
            (r'ekstraktem z lnu|ekstrakt z lnu','Z ekstraktem z lnu.'),
            (r'kwasu mlekowego|kwas mlekowy','Z kwasem mlekowym.'),
            (r'kwas salicylowy|kwasu salicylowego','Z kwasem salicylowym.'),
            (r'do pielęgnacji skóry bardzo suchej','Do pielęgnacji skóry bardzo suchej.'),
            (r'do punktowego, miejscowego stosowania','Do stosowania punktowego.'),
            (r'(?:z podwójną skalą|podwójna skala)','Podwójna skala na cylindrze.'),
            (r'zimno i ciepło|na ciepło i na zimno','Do stosowania na ciepło i zimno.'),
            (r'szczebli użyto drzewa liściastego','Szczeble z drewna liściastego.'),
            (r'długi, wygodny wężyk','Igła z długim wężykiem.'),
            (r'elektroniczny wyświetlacz|wyświetlacz LCD','Wyświetlacz elektroniczny.'),
            (r'wykonane z włókna szklanego|wykonany z włókna szklanego','Wykonanie z włókna szklanego.'),
        
            (r'przeznaczony do uniwersalnej pielęgnacji każdego rodzaju sierści','Do pielęgnacji każdego rodzaju sierści.'),
        
        
        ]
        for pattern,text in rules:add(pattern,text)
        return facts
    return facts

# END_RULES

def main():
    parser=argparse.ArgumentParser();parser.add_argument('source');parser.add_argument('output');args=parser.parse_args()
    raw=Path(args.source).read_bytes();data=json.loads(raw);entries=[];held=[]
    for r in data['products']:
        if 'basic_generated_description' not in r['review_reasons']:continue
        facts=facts_for(r);subject=re.sub(r'\s*\|\s*Sklep$', '',r['seo_title']).strip().rstrip(' .');description=subject[0].upper()+subject[1:]+'.';chosen=[]
        for fact,quote in facts:
            if any(stem in fact.casefold() and stem in description.casefold() for stem in ['gumki mocując','piank','superabsorbent']):continue
            if len(description+' '+fact)<=210 and fact.casefold().strip('.') not in description.casefold():
                description+=' '+fact;chosen.append({'field':'description','quote':quote,'fact':fact})
            if len(chosen)==2:break
        if re.search(r'(?<!nie)jałow',r['name'],re.I) and re.search(r'kompresy włókninowe, niejałowe',r['description'],re.I):
            held.append({'id':r['id'],'reason':'Name says sterile; source description says non-sterile'});continue
        if not chosen:held.append({'id':r['id'],'reason':'No reviewed construction or composition fact yet'});continue
        entries.append({'id':r['id'],'expected':{k:r[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_short_description_sha256':hashlib.sha256(r['short_description'].encode()).hexdigest(),'source_description_sha256':hashlib.sha256(r['description'].encode()).hexdigest(),'source_description_truncated':r['description_truncated'],'after':{'seo_title':r['seo_title'],'seo_description':description},'evidence':chosen})
    # Explicit duplicate corrections; source text identifies the model/height/colour.
    indexed={r['id']:r for r in data['products']}
    title_changes={
        158: ('Clog Back Guard Scholl – klapki granatowe', 'short_description', 'klapki granatowe'),
        159: ('Clog Back Guard Scholl – klapki białe', 'short_description', 'Białe klapki'),
        10048: ('Nasadka toaletowa z klapą KING-10L-20 – wysokość 10 cm', 'description', 'wysokość 10 cm'),
        10145: ('Nasadka toaletowa z klapą KING-15L-20 – wysokość 15 cm', 'description', 'wysokość 15 cm'),
    }
    for product_id,(title,field,quote) in title_changes.items():
        r=indexed[product_id];assert quote.casefold() in r[field].casefold()
        entries.append({'id':product_id,'expected':{k:r[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_short_description_sha256':hashlib.sha256(r['short_description'].encode()).hexdigest(),'source_description_sha256':hashlib.sha256(r['description'].encode()).hexdigest(),'source_description_truncated':r['description_truncated'],'after':{'seo_title':title,'seo_description':r['seo_description']},'evidence':[{'field':field,'quote':quote,'fact':title}]})
    entries.sort(key=lambda e:e['id'])
    for row in held:
        r=indexed[row['id']];row['name']=r['name'];row['source']=r['external_source']
        if row['reason']=='No reviewed construction or composition fact yet':
            row['reason']='Source review required before adding a reliable, product-specific non-promotional fact'
        if r['id']==13274:row['reason']='Active test product; source is Test123. Catalogue cleanup required'
        elif not r['description'].strip():row['reason']='Source product description is empty'
        elif len(r['description'])<150:row['reason']='Source has only a heading, model name or size-table reference; insufficient construction details'
        elif r['id'] in [14345,14352]:row['reason']='Source text mixes model names; manufacturer identity confirmation required'
        elif r['id']==13961:row['reason']='Title says solution; body describes hydrogel; confirm product identity'
        elif r['id']==14177:row['reason']='Source includes an expiry date of 2026-05-30; verify current stock and source text'
    duplicate_review=[]
    for group in data['duplicate_groups']:
        rows=[indexed[i] for i in group]
        if all(i in title_changes for i in group):status='resolved_by_source_identified_colour_or_height'
        elif rows[0]['external_source']=='medi':status='held_sale_and_regular_listing_identity_review'
        elif rows[0]['external_source']=='mobilex':status='held_colour_or_model_identity_not_uniquely_established'
        elif rows[0]['external_source']=='peruka':status='held_same_model_and_colour_repeated_import_review'
        else:status='held_repeated_model_requires_manufacturer_identifier_review'
        duplicate_review.append({'product_ids':group,'current_title':rows[0]['seo_title'],'status':status})
    result={'repair':'product-seo-editorial-20261031-v1','source_sha256':hashlib.sha256(raw).hexdigest(),'source_exported_at':data['exported_at'],'original_basic_count':944,'entries':entries,'held_descriptions':held,'duplicate_review':duplicate_review}
    Path(args.output).write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print('DESCRIPTIONS',len(entries),'HELD',len(held));print(Counter(next(r['external_source'] for r in data['products'] if r['id']==e['id']) for e in entries))
if __name__=='__main__':main()
