<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use InvalidArgumentException;

final class SigvarisOfficialPricePlanner
{
    public const IMPORT_MAP_SHA256 = '7f270865aebbab63c441f82c63d4075451f5c13fdbd49d735f43f00b427635aa';

    public const MARKUP_PERCENT = 20;

    /** @var array<string, array{base_net_minor:int,vat_rate:int,source_file:string,source_label:string}> */
    private const FIXED_RULES = [
        '97625' => ['base_net_minor' => 27700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Standard CompreFlex Udo'],
        '97603' => ['base_net_minor' => 18500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Standard CompreFlex Kolano'],
        '97590' => ['base_net_minor' => 34700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP CoolFlex Łydka'],
        '97569' => ['base_net_minor' => 52000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Standard CompreFlex Rękaw'],
        '57667' => ['base_net_minor' => 12800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS RĘKAWICZKA'],
        '8094' => ['base_net_minor' => 6100, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Cotton liner'],
        '7863' => ['base_net_minor' => 41700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Plus CompreBoot Stopa'],
        '8091' => ['base_net_minor' => 7900, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Basic liner'],
        '8073' => ['base_net_minor' => 39000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Complete CompreFlex Łydka'],
        '7860' => ['base_net_minor' => 12600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Standard CompreBoot Stopa'],
        '106235' => ['base_net_minor' => 14100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AG pasek'],
        '106230' => ['base_net_minor' => 23800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL CLASSICAL AT'],
        '8088' => ['base_net_minor' => 7900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Transition liner'],
        '8070' => ['base_net_minor' => 28000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Standard CompreFlex Łydka'],
        '8056' => ['base_net_minor' => 8200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING MICROFIBER SHADES AD'],
        '7887' => ['base_net_minor' => 28400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AG samonośne'],
        '106281' => ['base_net_minor' => 12600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0445'],
        '106280' => ['base_net_minor' => 9000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0446'],
        '106234' => ['base_net_minor' => 14100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AG pasek'],
        '25798' => ['base_net_minor' => 8500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0471'],
        '8085' => ['base_net_minor' => 8700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Complete liner'],
        '8082' => ['base_net_minor' => 8200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING MICROFIBER SHADES AD'],
        '7893' => ['base_net_minor' => 33100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AT'],
        '7890' => ['base_net_minor' => 28400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AG samonośne'],
        '7872' => ['base_net_minor' => 34700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS WRAP Transition CompreFlex Łydka'],
        '106236' => ['base_net_minor' => 24700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AG samonośne'],
        '76015' => ['base_net_minor' => 17900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AD'],
        '58128' => ['base_net_minor' => 9900, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0443'],
        '58038' => ['base_net_minor' => 13700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0442'],
        '25365' => ['base_net_minor' => 12900, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0461'],
        '7993' => ['base_net_minor' => 14000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AG pasek'],
        '7902' => ['base_net_minor' => 33100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AT Bodyform'],
        '106237' => ['base_net_minor' => 24700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AG samonośne'],
        '106229' => ['base_net_minor' => 19300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AG pasek'],
        '66666' => ['base_net_minor' => 17900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AD'],
        '25327' => ['base_net_minor' => 8200, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0432'],
        '24931' => ['base_net_minor' => 4100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0408'],
        '24857' => ['base_net_minor' => 9200, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0407'],
        '7934' => ['base_net_minor' => 26400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AT'],
        '97021' => ['base_net_minor' => 17900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AD'],
        '42703' => ['base_net_minor' => 9600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0403'],
        '25346' => ['base_net_minor' => 4000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0433'],
        '24096' => ['base_net_minor' => 11000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0401'],
        '8023' => ['base_net_minor' => 13300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THROMBO AG pasek'],
        '7975' => ['base_net_minor' => 24700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AG samonośne'],
        '7966' => ['base_net_minor' => 30000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AT'],
        '25576' => ['base_net_minor' => 9200, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0463'],
        '25219' => ['base_net_minor' => 8700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0415'],
        '25105' => ['base_net_minor' => 11300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0412'],
        '8008' => ['base_net_minor' => 22500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING HIGHLIGHT women AT'],
        '7960' => ['base_net_minor' => 32100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AT men'],
        '7908' => ['base_net_minor' => 28300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AG samonośne'],
        '7881' => ['base_net_minor' => 18500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AD'],
        '73595' => ['base_net_minor' => 18500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AD'],
        '58886' => ['base_net_minor' => 31600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AT'],
        '25988' => ['base_net_minor' => 5500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0473'],
        '25462' => ['base_net_minor' => 11300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0462'],
        '25012' => ['base_net_minor' => 11000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0410'],
        '106363' => ['base_net_minor' => 30800, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC BODY'],
        '70450' => ['base_net_minor' => 16700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AD'],
        '57984' => ['base_net_minor' => 18100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0441'],
        '25880' => ['base_net_minor' => 7800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0472'],
        '24557' => ['base_net_minor' => 7800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0405'],
        '8025' => ['base_net_minor' => 5200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 70 AD'],
        '106547' => ['base_net_minor' => 4800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0436'],
        '82298' => ['base_net_minor' => 11700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0414'],
        '70047' => ['base_net_minor' => 16700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AD'],
        '25290' => ['base_net_minor' => 7100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0431'],
        '8028' => ['base_net_minor' => 7500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 70 AG'],
        '7922' => ['base_net_minor' => 10700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THROMBO AG samonośne'],
        '106553' => ['base_net_minor' => 4800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0436'],
        '106279' => ['base_net_minor' => 16700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AD'],
        '76642' => ['base_net_minor' => 20600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AF'],
        '25067' => ['base_net_minor' => 10100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0411'],
        '8031' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 70 AT'],
        '7899' => ['base_net_minor' => 34500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AT maternity'],
        '106555' => ['base_net_minor' => 4000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0433'],
        '106546' => ['base_net_minor' => 10300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0435'],
        '79773' => ['base_net_minor' => 13800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL CLASSICAL AD'],
        '76700' => ['base_net_minor' => 20600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AF'],
        '24220' => ['base_net_minor' => 13000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0402'],
        '8034' => ['base_net_minor' => 8900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 70 AT maternity'],
        '7937' => ['base_net_minor' => 28400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AT maternity'],
        '106556' => ['base_net_minor' => 10300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0435'],
        '78911' => ['base_net_minor' => 13800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL CLASSICAL AD'],
        '76874' => ['base_net_minor' => 20600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AF'],
        '26092' => ['base_net_minor' => 10800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0474'],
        '7914' => ['base_net_minor' => 33700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AT maternity'],
        '106558' => ['base_net_minor' => 8200, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0432'],
        '62721' => ['base_net_minor' => 18400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AD'],
        '46640' => ['base_net_minor' => 33700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AT maternity'],
        '25685' => ['base_net_minor' => 14700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0464'],
        '106239' => ['base_net_minor' => 19000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL CLASSICAL AG samonośne'],
        '74576' => ['base_net_minor' => 18400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AD'],
        '106562' => ['base_net_minor' => 28300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AG samonośne'],
        '76072' => ['base_net_minor' => 20600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL TRADITIONAL AD samonośne'],
        '8076' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 70 AT'],
        '106564' => ['base_net_minor' => 31600, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AT'],
        '106560' => ['base_net_minor' => 7100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0431'],
        '74257' => ['base_net_minor' => 21100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL COMFORT AD samonośne'],
        '82723' => ['base_net_minor' => 18400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THERMOREGULATING AD samonośne'],
        '106231' => ['base_net_minor' => 20900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AD samonośne'],
        '7920' => ['base_net_minor' => 7300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL THROMBO AD'],
        '7978' => ['base_net_minor' => 17200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MASCULINE AD'],
        '7996' => ['base_net_minor' => 16200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL ULCER X set'],
        '7999' => ['base_net_minor' => 15300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL ULCER X liner'],
        '106561' => ['base_net_minor' => 18400, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'MEDICAL MAGIC AD'],
        '8002' => ['base_net_minor' => 13800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING HIGHLIGHT women AD'],
        '82851' => ['base_net_minor' => 15200, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC BRA'],
        '8005' => ['base_net_minor' => 19500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING HIGHLIGHT women AG'],
        '82874' => ['base_net_minor' => 17100, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC VEST'],
        '8037' => ['base_net_minor' => 5500, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 140 AD'],
        '82906' => ['base_net_minor' => 25600, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC LEGGINGS'],
        '8040' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 140 AG'],
        '82915' => ['base_net_minor' => 4500, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC EAR BAND'],
        '8043' => ['base_net_minor' => 8800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 140 AT'],
        '82947' => ['base_net_minor' => 9600, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC FACE GARMENT'],
        '8046' => ['base_net_minor' => 10200, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 140 AT maternity'],
        '82958' => ['base_net_minor' => 18100, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC BELT'],
        '8049' => ['base_net_minor' => 7100, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING SAMSON AD'],
        '82988' => ['base_net_minor' => 4500, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC BREAST STRAP'],
        '8079' => ['base_net_minor' => 8800, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING DELILAH 140 AT'],
        '24549' => ['base_net_minor' => 5500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0404'],
        '25270' => ['base_net_minor' => 3300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0418'],
        '25280' => ['base_net_minor' => 3300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf', 'source_label' => 'PT 0419'],
        '106242' => ['base_net_minor' => 16900, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC BRA II'],
        '106243' => ['base_net_minor' => 15800, 'vat_rate' => 8, 'source_file' => 'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf', 'source_label' => 'AESTHETIC SHORTS'],
        '106285' => ['base_net_minor' => 2300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0336'],
        '106244' => ['base_net_minor' => 9300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0335'],
        '71353' => ['base_net_minor' => 11600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0334'],
        '57487' => ['base_net_minor' => 6100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0333'],
        '45593' => ['base_net_minor' => 10500, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS UmeruSupport'],
        '45568' => ['base_net_minor' => 4100, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS ColluActive'],
        '45558' => ['base_net_minor' => 8700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0332'],
        '27242' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS GenuBand'],
        '27235' => ['base_net_minor' => 7000, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS EpiBand'],
        '27227' => ['base_net_minor' => 17600, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS MalleoSupport'],
        '27220' => ['base_net_minor' => 13700, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS ManuSupport'],
        '27215' => ['base_net_minor' => 8000, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS RhizoSupport'],
        '27204' => ['base_net_minor' => 13600, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS GenuWrap'],
        '27191' => ['base_net_minor' => 13100, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS GenuActive Pad'],
        '27177' => ['base_net_minor' => 11100, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS MalleoActive'],
        '27154' => ['base_net_minor' => 10700, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS ManuActive'],
        '27142' => ['base_net_minor' => 11600, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS EpiActive'],
        '24095' => ['base_net_minor' => 4000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0331'],
        '24060' => ['base_net_minor' => 6300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0320'],
        '24047' => ['base_net_minor' => 1800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0309'],
        '24038' => ['base_net_minor' => 2300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0308'],
        '24029' => ['base_net_minor' => 2500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0307'],
        '24024' => ['base_net_minor' => 1500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0306'],
        '24017' => ['base_net_minor' => 1800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0305'],
        '24011' => ['base_net_minor' => 1700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0304'],
        '24002' => ['base_net_minor' => 2500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0303'],
        '23997' => ['base_net_minor' => 1600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0302'],
        '23991' => ['base_net_minor' => 1800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0301'],
        '23976' => ['base_net_minor' => 18600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0238'],
        '23961' => ['base_net_minor' => 14800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0237'],
        '23946' => ['base_net_minor' => 9700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0236'],
        '23940' => ['base_net_minor' => 25300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0225'],
        '23934' => ['base_net_minor' => 29500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0224'],
        '23927' => ['base_net_minor' => 10100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0222'],
        '23919' => ['base_net_minor' => 11900, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0216'],
        '23912' => ['base_net_minor' => 3700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0214'],
        '23906' => ['base_net_minor' => 5100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0211'],
        '23893' => ['base_net_minor' => 3800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0209B'],
        '23880' => ['base_net_minor' => 3100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0209A'],
        '23862' => ['base_net_minor' => 10200, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0206'],
        '23856' => ['base_net_minor' => 3600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0204'],
        '23843' => ['base_net_minor' => 9400, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0202'],
        '23822' => ['base_net_minor' => 5100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0201'],
        '23814' => ['base_net_minor' => 8800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0121/28'],
        '23806' => ['base_net_minor' => 8800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0121/22'],
        '23798' => ['base_net_minor' => 5400, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0117'],
        '23790' => ['base_net_minor' => 4700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0116'],
        '23782' => ['base_net_minor' => 4700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0115'],
        '23775' => ['base_net_minor' => 9700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0114'],
        '23768' => ['base_net_minor' => 9700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0114A'],
        '23761' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0113'],
        '23755' => ['base_net_minor' => 10500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0112'],
        '23748' => ['base_net_minor' => 10400, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0111'],
        '23742' => ['base_net_minor' => 8300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0110'],
        '23731' => ['base_net_minor' => 7300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0109'],
        '23724' => ['base_net_minor' => 9900, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0108'],
        '23717' => ['base_net_minor' => 7400, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0107'],
        '23702' => ['base_net_minor' => 6700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0106'],
        '23687' => ['base_net_minor' => 7900, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0105'],
        '23674' => ['base_net_minor' => 5100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0104'],
        '23667' => ['base_net_minor' => 10300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0103/52'],
        '23653' => ['base_net_minor' => 10300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0103'],
        '23645' => ['base_net_minor' => 5000, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0102'],
        '23637' => ['base_net_minor' => 5100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0102A'],
        '23621' => ['base_net_minor' => 5500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0101A'],
        '106298' => ['base_net_minor' => 9300, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0337'],
        '106300' => ['base_net_minor' => 6100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0338'],
        '106303' => ['base_net_minor' => 11600, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0339'],
        '106307' => ['base_net_minor' => 18100, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0340'],
        '106331' => ['base_net_minor' => 7700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0342'],
        '106335' => ['base_net_minor' => 23500, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS MalleoWalker Air+'],
        '106356' => ['base_net_minor' => 7500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0343'],
        '106537' => ['base_net_minor' => 6800, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0344'],
        '106539' => ['base_net_minor' => 7300, 'vat_rate' => 8, 'source_file' => 'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf', 'source_label' => 'MOBILIS ColluActive 2in1'],
        '106548' => ['base_net_minor' => 5500, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => 'PT 0345'],
        '8059' => ['base_net_minor' => 13900, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING HIGHLIGHT men AD'],
        '8014' => ['base_net_minor' => 5700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'WELL BEING TRAVENO AD'],
        '106252' => ['base_net_minor' => 16700, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'DOFF N DONNER GUMA'],
        '106233' => ['base_net_minor' => 1200, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'ŚLISKA STOPKA'],
        '8062' => ['base_net_minor' => 17400, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'DOFF N DONNER STOŻEK'],
        '8052' => ['base_net_minor' => 5000, 'vat_rate' => 8, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'KLEJ SIGVARIS FIX'],
        '8011' => ['base_net_minor' => 1900, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'RĘKAWICZKI'],
        '7984' => ['base_net_minor' => 15800, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'Magnide on/off'],
        '7981' => ['base_net_minor' => 10400, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'SIGVARIS sim-slide'],
        '7957' => ['base_net_minor' => 1900, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'RĘKAWICZKI'],
        '7944' => ['base_net_minor' => 3200, 'vat_rate' => 23, 'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf', 'source_label' => 'PŁYN DO PRANIA 250ML'],
        '106544' => ['base_net_minor' => 700, 'vat_rate' => 8, 'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf', 'source_label' => '361504'],
    ];

    /** @var array<string, string> */
    private const UNMATCHED_REASONS = [
        '28035' => 'The current SIGVARIS price list does not identify whether this postoperative CLASSICAL stocking is the AF or AG listed construction.',
        '106565' => 'AESTHETIC BODY LONG is not present in the uploaded AESTHETIC price list; only BODY is listed.',
        '27276' => 'The limited-series children\'s sling is not explicitly present in the uploaded PTM orthopaedic price list.',
        '27275' => 'The limited-series children\'s sling is not explicitly present in the uploaded PTM orthopaedic price list.',
        '27268' => 'The limited-series patterned children\'s sling is not explicitly present in the uploaded PTM orthopaedic price list.',
    ];

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    public function build(array $map): array
    {
        if (($map['source'] ?? null) !== 'sigvaris') {
            throw new InvalidArgumentException('Price planning source must be sigvaris.');
        }

        $products = array_values(array_filter(
            $map['products'] ?? [],
            static fn (mixed $product): bool => is_array($product),
        ));

        $plannedProducts = [];
        $plannedVariants = [];
        $unmatchedProducts = [];
        $errors = [];

        foreach ($products as $mappedProduct) {
            $productData = is_array($mappedProduct['product'] ?? null) ? $mappedProduct['product'] : [];
            $externalId = $this->stringOrNull($productData['external_id'] ?? null);
            $name = $this->stringOrNull($productData['name'] ?? null) ?? '[unnamed]';

            if ($externalId === null) {
                $errors[] = 'Mapped product is missing external_id: '.$name;

                continue;
            }

            if (isset(self::UNMATCHED_REASONS[$externalId])) {
                $unmatchedProducts[] = [
                    'external_id' => $externalId,
                    'name' => $name,
                    'variant_count' => count(is_array($mappedProduct['variants'] ?? null) ? $mappedProduct['variants'] : []),
                    'reason' => self::UNMATCHED_REASONS[$externalId],
                ];

                continue;
            }

            $variants = array_values(array_filter(
                $mappedProduct['variants'] ?? [],
                static fn (mixed $variant): bool => is_array($variant),
            ));

            $productVariantPlans = [];

            foreach ($variants as $variant) {
                $rule = $this->ruleForVariant($externalId, $variant);

                if ($rule === null) {
                    $errors[] = 'No official price rule for '.$externalId.' / '.($variant['external_variant_id'] ?? '[missing variant ID]').'.';

                    continue;
                }

                $externalVariantId = $this->stringOrNull($variant['external_variant_id'] ?? null);
                $sku = $this->stringOrNull($variant['sku'] ?? null);

                if ($externalVariantId === null || $sku === null) {
                    $errors[] = 'Mapped variant is missing external_variant_id or SKU for product '.$externalId.'.';

                    continue;
                }

                $baseNetMinor = $rule['base_net_minor'];
                $vatRate = $rule['vat_rate'];
                $sellingNetMinor = $this->roundRatio($baseNetMinor * (100 + self::MARKUP_PERCENT), 100);
                $sellingGrossMinor = $this->roundRatio(
                    $baseNetMinor * (100 + $vatRate) * (100 + self::MARKUP_PERCENT),
                    10000,
                );

                $plan = [
                    'product_external_id' => $externalId,
                    'product_name' => $name,
                    'external_variant_id' => $externalVariantId,
                    'sku' => $sku,
                    'base_net_minor' => $baseNetMinor,
                    'vat_rate' => $vatRate,
                    'markup_percent' => self::MARKUP_PERCENT,
                    'selling_net_minor' => $sellingNetMinor,
                    'selling_gross_minor' => $sellingGrossMinor,
                    'currency' => 'PLN',
                    'source_file' => $rule['source_file'],
                    'source_label' => $rule['source_label'],
                    'current_map_net_minor' => $this->intOrNull($variant['price_net_minor'] ?? null),
                    'current_map_gross_minor' => $this->intOrNull($variant['price_gross_minor'] ?? null),
                    'current_map_vat_rate' => $this->numericOrNull($variant['vat_rate'] ?? null),
                ];

                $productVariantPlans[] = $plan;
                $plannedVariants[] = $plan;
            }

            if (count($productVariantPlans) !== count($variants)) {
                continue;
            }

            $plannedProducts[] = [
                'external_id' => $externalId,
                'name' => $name,
                'variant_count' => count($productVariantPlans),
                'distinct_prices' => array_values(array_unique(array_map(
                    static fn (array $plan): string => $plan['base_net_minor'].'|'.$plan['vat_rate'].'|'.$plan['selling_gross_minor'],
                    $productVariantPlans,
                ))),
            ];
        }

        return [
            'version' => 1,
            'source' => 'sigvaris',
            'price_list_effective_date' => '2026-01-21',
            'formula' => 'selling_gross = round(base_net * (1 + VAT) * 1.20, 2)',
            'markup_percent' => self::MARKUP_PERCENT,
            'database_writes' => false,
            'matched_product_count' => count($plannedProducts),
            'matched_variant_count' => count($plannedVariants),
            'unmatched_product_count' => count($unmatchedProducts),
            'unmatched_variant_count' => array_sum(array_column($unmatchedProducts, 'variant_count')),
            'error_count' => count($errors),
            'ready_for_price_write_implementation' => $errors === [] && $unmatchedProducts === [],
            'products' => $plannedProducts,
            'variants' => $plannedVariants,
            'unmatched_products' => $unmatchedProducts,
            'errors' => $errors,
            'source_fingerprints' => [
                'import_map' => self::IMPORT_MAP_SHA256,
                'Cennik_Sigvaris_podstawowy_21.01.2026.pdf' => 'a5c41a3459ebf12d8f64f0a304089183a20d9b004e8fd931094485d8e196d2ed',
                'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf' => 'a7ed53461e54af8bfe050fd3954264b87ff53e82dfcaed1358c046909d06ebde',
                'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf' => '15ac7f125888476289b05a776724d25877c07c4d053cf87b8a7d9848d09e7de9',
                'CENNIK_MOBILIS_PODSTAWOWY_21.01.2026.pdf' => '5b6f3989f03e915f5c0e816a9fda9a7667b2778ab5e20e8a8ae6be3ccce8ff2a',
                'Cennik_AESTHETIC_PODSTAWOWY_21.01.2026 (1).pdf' => 'e51e9756480f52c3266996caf1e297834e6410ce694757bede0944d149000c4f',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @return array{base_net_minor:int,vat_rate:int,source_file:string,source_label:string}|null
     */
    private function ruleForVariant(string $productExternalId, array $variant): ?array
    {
        $fixed = self::FIXED_RULES[$productExternalId] ?? null;

        if (is_array($fixed)) {
            return $fixed;
        }

        return match ($productExternalId) {
            '8067' => $this->mainPrice(
                $this->attributeValue($variant, 'dlon') === 'Z dłonią' ? 18400 : 15400,
                8,
                $this->attributeValue($variant, 'dlon') === 'Z dłonią'
                    ? 'RĘKAW TRADITIONAL / Z dłonią'
                    : 'RĘKAW TRADITIONAL / Bez dłoni',
            ),
            '8064' => $this->mainPrice(
                $this->attributeValue($variant, 'mankiet') === 'Samonośny'
                    && $this->attributeValue($variant, 'dlon') === 'Z dłonią'
                        ? 23000
                        : 22600,
                8,
                $this->attributeValue($variant, 'mankiet') === 'Samonośny'
                    && $this->attributeValue($variant, 'dlon') === 'Z dłonią'
                        ? 'RĘKAW ADVANCE SAMONOŚNY / Z dłonią'
                        : 'RĘKAW ADVANCE / pozostałe warianty',
            ),
            '83043' => $this->compressionPrice(
                str_contains((string) ($variant['source_reference'] ?? ''), '406A') ? 6900 : 6200,
                str_contains((string) ($variant['source_reference'] ?? ''), '406A') ? 'PT 0406A' : 'PT 0406',
            ),
            '106238' => $this->mainPrice(
                $this->attributeValue($variant, 'mankiet') === 'Mankiet guzkowy' ? 26400 : 23300,
                8,
                $this->attributeValue($variant, 'mankiet') === 'Mankiet guzkowy'
                    ? 'AG pończochy samonośne / TRADITIONAL'
                    : 'AG pończochy / TRADITIONAL',
            ),
            '106545', '106559' => $this->compressionPrice(
                str_contains((string) $this->attributeValue($variant, 'rozmiar'), 'Plus') ? 10400 : 8200,
                str_contains((string) $this->attributeValue($variant, 'rozmiar'), 'Plus') ? 'PT 0434 PLUS' : 'PT 0434',
            ),
            '23629' => $this->orthoPrice(
                $this->attributeValue($variant, 'rozmiar') === 'XXXL' ? 6400 : 5200,
                $this->attributeValue($variant, 'rozmiar') === 'XXXL' ? 'PT 0101 XXXL' : 'PT 0101',
            ),
            default => null,
        };
    }

    /** @return array{base_net_minor:int,vat_rate:int,source_file:string,source_label:string} */
    private function mainPrice(int $baseNetMinor, int $vatRate, string $label): array
    {
        return [
            'base_net_minor' => $baseNetMinor,
            'vat_rate' => $vatRate,
            'source_file' => 'Cennik_Sigvaris_podstawowy_21.01.2026.pdf',
            'source_label' => $label,
        ];
    }

    /** @return array{base_net_minor:int,vat_rate:int,source_file:string,source_label:string} */
    private function compressionPrice(int $baseNetMinor, string $label): array
    {
        return [
            'base_net_minor' => $baseNetMinor,
            'vat_rate' => 8,
            'source_file' => 'CENNIK_PTM_PODSTAWOWY_KOMPR_21.01.2026.pdf',
            'source_label' => $label,
        ];
    }

    /** @return array{base_net_minor:int,vat_rate:int,source_file:string,source_label:string} */
    private function orthoPrice(int $baseNetMinor, string $label): array
    {
        return [
            'base_net_minor' => $baseNetMinor,
            'vat_rate' => 8,
            'source_file' => 'CENNIK_PTM_PODSTAWOWY_ORTO_21.01.2026.pdf',
            'source_label' => $label,
        ];
    }

    /** @param array<string, mixed> $variant */
    private function attributeValue(array $variant, string $code): ?string
    {
        foreach (($variant['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute) || ($attribute['code'] ?? null) !== $code) {
                continue;
            }

            return $this->stringOrNull($attribute['value_label'] ?? null)
                ?? $this->stringOrNull($attribute['value'] ?? null);
        }

        return null;
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return (int) round($numerator / $denominator, 0, PHP_ROUND_HALF_UP);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}
