# SEO-02D — First approved legacy product redirect cohort

This patch freezes the first manually reviewed product redirect approvals. It does **not** install runtime redirects.

- approved products: **23**
- approved legacy source paths: **36**
- runtime redirects installed by this patch: **0**

Approval required exact product identity plus exact identifier agreement across the legacy `Indeks`, production variant SKU, and production external parent SKU. The target product and matched variant must both be active.

## Approved products

| Legacy ID | Legacy product | Identifier | Target path | Source paths |
|---:|---|---|---|---:|
| 3137 | Taboret toaletowy pod prysznic AT01004 | `AT01004` | `/products/taboret-toaletowy-pod-prysznic-at01004` | 1 |
| 3142 | Poduszka lędźwiowa - AT03003 | `AT03003` | `/products/poduszka-ledzwiowa-at03003` | 1 |
| 3143 | Poduszka lędźwiowa - AT03004 | `AT03004` | `/products/poduszka-ledzwiowa-at03004` | 1 |
| 3148 | Poduszka do siedzenia AT03011 | `AT03011` | `/products/poduszka-do-siedzenia-at03011` | 2 |
| 3149 | Poduszka do siedzenia AT03012 | `AT03012` | `/products/poduszka-do-siedzenia-at03012` | 1 |
| 3150 | Pas brzuszny - stomijny AT04603 | `AT04603` | `/products/pas-brzuszny-stomijny-at04603` | 2 |
| 3151 | Pas brzuszny z pelotą AT04604 | `AT04604` | `/products/pas-brzuszny-z-pelota-at04604` | 2 |
| 3152 | Pas brzuszny AT04605 | `AT04605` | `/products/pas-brzuszny-at04605` | 2 |
| 3158 | Orteza kręgosłupa AT04501 | `AT04501` | `/products/orteza-kregoslupa-at04501` | 2 |
| 3160 | Pas lędźwiowo-krzyżowy AT04503 | `AT04503` | `/products/pas-ledzwiowo-krzyzowy-at04503` | 2 |
| 3190 | Pas ciążowy - 2062 | `2062` | `/products/pas-ciazowy-2062` | 2 |
| 3193 | Pas brzuszny - 2162 | `2162` | `/products/pas-brzuszny-2162` | 2 |
| 3199 | Pas brzuszny - 2260 | `2260` | `/products/pas-brzuszny-2260` | 2 |
| 3223 | Orteza nadgarstka - 1082 | `1082` | `/products/orteza-nadgarstka-1082` | 2 |
| 3250 | Kołnierz ortopedyczny Professional 4098 | `4098` | `/products/kolnierz-ortopedyczny-professional-4098` | 1 |
| 3252 | Kołnierz ortopedyczny sztywny Primary 4290 | `4290` | `/products/kolnierz-ortopedyczny-sztywny-primary-4290` | 1 |
| 3297 | Orteza stawu barkowego ACCUTEX 2970 | `2970` | `/products/orteza-stawu-barkowego-accutex-2970` | 1 |
| 3303 | Kula łokciowa - ERGOTECH | `ERGOTECH` | `/products/kula-lokciowa-ergotech` | 2 |
| 3330 | Stabilizator kolana z zawiasami - 1031 | `1031` | `/products/stabilizator-kolana-z-zawiasami-1031` | 2 |
| 12506 | Orteza nadgarstka ze wzmocnieniem silikonowym AT53044 | `AT53044` | `/products/orteza-nadgarstka-ze-wzmocnieniem-silikonowym-at53044` | 2 |
| 12508 | Rotor rehabilitacyjny AT51124 | `AT51124` | `/products/rotor-rehabilitacyjny-at51124` | 1 |
| 12509 | Rotor rehabilitacyjny elektryczny AT51125 | `AT51125` | `/products/rotor-rehabilitacyjny-elektryczny-at51125` | 1 |
| 13138 | Chwytak dla osób niepełnosprawnych AT51123 | `AT51123` | `/products/chwytak-dla-osob-niepelnosprawnych-at51123` | 1 |

The next stage may consume this manifest to generate runtime redirect configuration, but must validate the target URLs before enabling redirects.

Evidence hashes:

- SEO-02C JSON: `8074eb2e8249af7f081ef2844d3d4558d0ec29ad08ed951e27f473976ad2c378`
- SEO-02C CSV: `580aaeaf1bf24092481de7fd3c48f3d7e041fc4476caeb724e6f0ba1132c2b8d`
