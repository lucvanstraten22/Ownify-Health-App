# Voeding, drawn

The Voeding page shows your own cijfer, what you ate and what you drank as
cards and charts, not as rows of numbers. It is the same on the website and
in the Android app (27.0). The server works out every number, range and word
(`lib/hydrate-nutrition.php`), and both apps draw what it sends.

```
Voeding ──┬── the score                    (unchanged, the ring at --hero-ring)
          ├── Hoe at je?                   your cijfer as a slider, for the day shown
          ├── Samenstelling                ‹ the day ›, the doughnut, three rows
          ├── Energie │ Water              two small area charts, side by side
          └── Verloop                      the Voeding score over time (unchanged)
```

Nothing here is advice and nothing is invented. A nutrient no app logged is
"niet gelogd", never 0 g; a day without all three macronutrients has no
doughnut; a reading without a basis for a verdict gets none.

## The reference values: one file

Every range lives in `config/nutrition-reference.php`, and nowhere else:

| | value | used for |
| --- | --- | --- |
| `kcal_per_gram` | carbohydrates 4, protein 4, fat 9 | the energy from each macronutrient |
| `kj_per_kcal` | 4.184 | the kJ beside every kcal |
| `macros.carbs` | no percentage (since 29.0) | its share, shown on a meter without a band; "Geen oordeel"; Richtlijn "Geen percentage" |
| `macros.protein` | your own goal (`nutrition_targets_for_user()`, g per kg for your activity) | its meter and "% van je doel" |
| `macros.fat` | 20–40 % of the energy | its meter, bubble and guideline |
| `details.fibre` | 12,6–14,6 g per 1.000 kcal of the day (since 29.0) | "… g per 1.000 kcal" and its bubble — only on a day whose energy is known |
| `details.sugar` | no verdict | "Geen oordeel" |
| `details.saturated_fat` | under 10 % of the energy — the interim step; the advice is as low as possible | its share and bubble |
| `energy.range` | 90–110 % of your energy target | the Energie chart's bereik |
| `water.range`, `water.target` | 1,5–2,0 L, 1,8 L | the Water chart's bereik and "van 1,8 L" |

Change a value there and both apps follow; nothing else needs to change.

**The 2026 norms** (29.0, the owner's decision of 27 October 2026): fat,
saturated fat, carbohydrates and fibre follow the Gezondheidsraad's
*Voedingsnormen voor vetten, vetzuren, verteerbare koolhydraten en
voedingsvezels* (2026/14, NUT-01 in [RESEARCH.md](RESEARCH.md)) — total fat
20–40 % of the energy; saturated fat as low as possible, 10 % as the interim
step; no percentage for carbohydrates (their source matters, which a log
does not show); fibre 3,0–3,5 g per MJ, 12,6–14,6 g per 1.000 kcal. Protein
keeps the person's own target (`nutrition_targets_for_user()`), unchanged.
Water is the Voedingscentrum's drinks a day, and the energy range around the
person's own target is Ownify's own assumption — neither is a reviewed
research source. The fat and carbohydrate rows, and the cijfer, carry the
research behind them (docs/INSIGHTS.md).

## Hoe at je? — the slider

A pill track from 1 to 10 with a glass thumb, the health green up to it.
It follows the finger (and the arrow keys, Home and End on the website;
TalkBack's adjust actions in the app) and **saves when it is let go**, for
the day shown in Samenstelling — today or one of the six days before
(`api/health/rating.php` takes an optional `date`, never in the future and
never more than six days back). The line under it says "Opgeslagen · 7 voor
vandaag", "Nog niet opgeslagen — schuif en laat los", or why it could not
be saved. Saving today's cijfer clears the Voeding dot (docs/NOTICES.md);
the score and points are read again.

The slider owns its horizontal drags (`data-gesture-own`, `ownsGestures`),
so moving it never swipes the page back.

## Samenstelling — the day

‹ the day's name › — Vandaag, Gisteren, Eergisteren, then "vrijdag 16
oktober" — back six days. The seven days are all sent at once, so stepping
needs no request.

- **The doughnut**, the score ring's size (`--hero-ring`, `heroRingSize`):
  each macronutrient's share of the day's energy, in three Voeding greens —
  carbohydrates lightest, protein `--nutrition-light`, fat deepest (an oklab
  mix with the theme's lift and shade, in both themes). Its middle shows the
  day's kcal and "% van" the energy target; with a part focused, that
  macronutrient's name, share and "g · kcal". A tap on a part opens its row.
- **The rows**, Koolhydraten, Eiwitten, Vetten: grams; "% van je energie" and,
  for fat, "richtlijn 20–40 %" (protein: "% van je doel"; carbohydrates have
  no guideline to show); a bubble; a meter with the range as a green band —
  none for carbohydrates — and the day's marker. A row **opens in
  place** (280 ms, the strong ease-out; at once with reduced motion), one at
  a time, and shows Gegeten, Deel van je energie and Richtlijn; protein's
  line on how its goal is set; fibre and sugar under carbohydrates,
  saturated fat under fat. A detail line too narrow for all it says (fibre's
  "per 1.000 kcal" on a small phone) moves what does not fit under it, on
  both apps; nothing runs over the name. Fat's and carbohydrates' rows end
  with their research ("Bekijk onderzoek").
- **The bubbles** are the goal wizard's glass chip (`.glass--chip`,
  `GlassChip`'s glass), as a status: green glass for Binnen bereik, plain
  glass for Onder bereik and Geen oordeel, gold glass with gold text for
  Boven bereik.

## Energie and Water

Two of Ownify's area charts (docs/CHARTS.md, `lib/area-charts.php`), small
side by side like Slaap's and Training's, each opening its own page with
7 dagen, 30 dagen, 90 dagen and 1 jaar. Both draw their bereik as the same
green zone behind the bars, and each day's reading says Binnen, Onder or
Boven bereik.

- Energie: "1.844 van 2.300 kcal", "7.715 kJ" under it; on its page kcal on
  the left, kJ on the right. Without an energy target (no profile) it has no
  bereik and no "van".
- Water: "1,2 L van 1,8 L", "1.200 ml" under it. Litres are written with a
  capital L everywhere.

## Counting each moment once

A meal or a glass two apps wrote at the same minute counts once — the app
first in `config/health-sources.php`'s `reconcile` order, then by name —
while two dishes one app logged at the same minute both count
(`health_reconcile_days()`, `includes/health-totals.php`). Water's records
have a span and are cut per moment like every span. Each nutrient keeps the
app that wrote it (`data_origin`).

## Where it lives

| | Server | Website | Android app |
| --- | --- | --- | --- |
| The view | `hydrate_nutrition()` (`lib/hydrate-nutrition.php`) | `components/nutrition-view.php`, `assets/css/nutrition.css`, `assets/js/nutrition.js` | `NutritionViewContent` (`ui/screens/health/NutritionView.kt`), `NutritionView` (`data/AppData.kt`) |
| The reference | `config/nutrition-reference.php` | — | — |
| The cijfer | `api/health/rating.php` | `nutrition.js` | `RatingSliderCard` |
| The charts | `area_charts()` with `band`, `of`, `sub` and a series' `alt` | `components/area-chart.php` | `AreaChartsGrid`, `AreaChartDetail` |

Tests: `tools/nutrition-view-test.php` (the database), `tools/metric-totals-test.php`
(counting once), `NutritionViewTest` (the app reading the view).

## Not (yet) here

- Water is shown in litres in imperial too: Ownify's units (docs/I18N.md)
  have no volume yet.
- Pull-to-sync on the website refreshes the scores in place, not this view;
  it shows the new day after the next load. The app reads the whole page
  again.
