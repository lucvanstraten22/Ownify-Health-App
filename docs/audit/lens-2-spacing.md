# Lens 2 — Spacing, alignment and rhythm

Reviewer 2 of 9. Read-only. `anthropic-skills:apple-design` was loaded (Craft: every spacing value
deliberate; Grouping: proximity implies relationship). The review was **stopped early by the
coordinator** to save usage. Only the web app (Dark, 393 px) was measured. Android, 320 px, the
popups and the off-scale grep were **not** done (see the last section).

Method: `$A/web/phone-dark/*.json` scroll steps were stitched into page coordinates. A step is
724 px. Elements not yet revealed sit +14 px lower (`.js .reveal`, theme.css:793), and the layers
beneath a detail were filtered out by document order. Gaps are the next block's `y` − (`y + h`).
"Inset" is the first or last visible content measured against the card's edge. The scratch
scripts are outside the repo.

## Summary

1. **Overzicht's title "Vandaag" does not sit where every other page title sits:** x 17 / y 98
   against x 24 / y 93. So the title jumps 7 px sideways and 5 px down when you swipe between
   tabs, which the code itself says must not happen.
2. **Full-width cards use two inner paddings, 24 and 16.** Text inside cards starts at x 41 on
   Overzicht, Gezondheid and the details, but at x 33 on Community, Uitdagingen and Training's
   wide mini chart.
3. **Lists of equivalent cards use three gaps:** 16 (page stack), 12 (pillar cards, mini-chart
   grid, goals list) and 8 (Uitdagingen cards).
4. **`.settings-rows` cards are bottom-heavy:** 13 px at the top, 25–26 px at the bottom (Over de
   app › App, Voorkeuren › Nu in gebruik). They also draw their hairline and row height
   differently from the settings card right beside them.
5. **Mini area-chart cards are bottom-heavy too:** 17 px at the top, 30 px at the bottom. Chart
   cards without a hint keep an empty 45 px band at the bottom, against 25 px for the ones with
   a hint.
6. **A text link as the last thing in a card adds ~12 px of visual bottom padding** through its
   44 px target. Only the calibration card corrects for it.
7. **Doelen's intro sits 7 px above its switch**, where every other page leaves 15–16 px between
   intro and content.
8. **Floating "+" and the OwnifyAI grip:** content clears both on every page at the end of the
   scroll (28 px under the + at its closest). The overlaps seen are only at rest and mid-scroll,
   e.g. the + over the SpO₂ day labels.

## Measured gap table (web, 393 × 852, Dark)

| Screen | Title / lede → first content | Card → card | Card insets (t / b / l, px) |
|---|---|---|---|
| Overzicht | hero card at 93, title inside it at 98 | 16, 16, 15 | hero 5/8/1; goal 25/25/25; insights 25/38/25; suggestion 25/17 (to link box)/25 |
| Gezondheid | lede 150 → pillars 165 (15) | pillars → trend 16; pillars side by side 12 | pillars 17/17; trend 25/24/25 |
| Doelen | lede 150 → switch ≈157 (7); switch → card 16 | – | empty card 33/32 |
| Community | title 127 → card 143 (16) | 16 | cm-dest 17/17/17 and 17/25/17 (padding 16) |
| Instellingen | lede 150 → identity 165 (15) | eyebrow → card 8, card → eyebrow 16, last card → Uitloggen 31 | rows 56 tall |
| Slaap / Voeding / Training | hero at 93 | 16 (15–17 from rounding); mini grid 12 × 12 | standard cards 25/24–29/25; minis 17/30/16 |
| Chart details (SpO₂, HRV, …) | title 127 → card 143 (16) | chart → "goed om te weten" 39–40 (eyebrow 16 + 8 + 16) | chart 25/25 with a hint, **25/45 without one** (Huidtemperatuur, Ademfrequentie, Energie, Hartbelasting) |
| Training session | lede 150 → card 165 (15) | 16 | 25/25 |
| Invoeren | lede 169 → card 184 (15) | – | 25/24 |
| Uitdagingen | lede 150 → action pills 174, eyebrow 241 → card 265 | **8** | 17/16–17 (padding 16) |
| Uitdaging 1–3 | lede 197 → card 213 (16) | 15–16 | 25/25 (challenge 2–3, card 2: 25/35) |
| Inzicht C01–C17 | lede → eyebrow 15, eyebrow → card 8 | 40 (with eyebrow), 24 before the link card | state card 13/23; note card 17/5 (to link box); list 13/13 |
| Settings details | lede → eyebrow 15 | 40 / 39 (with eyebrow) | options 13/12–13; **settings-rows 13/25–26** |

Bottom clearance (last card → end of page) is 154 on every page whose last block is a card. That
equals `.app__main` padding-bottom (dock-clearance 66 + 32 + 32 + 24, theme.css:745–749).

## Findings

### 2.1 "Vandaag" is not at the page-title position
- Status: VERIFIED · Kind: defect
- Where: Overzicht, `$A/web/phone-dark/page-overview-0.png` compared with `page-health-0.png`,
  `page-settings-0.png` and `page-community-0.png`. Web: pages/overview.php:29–31 (title inside
  `.card--hero .card__head`), components.css:98–115 (`.card--hero` padding `4px 0 8px`, 1 px
  border), health.css:12 (`.page-intro { padding-inline: var(--space-2) }`). Android: not checked.
- Problem: `h1.card__title` "Vandaag" sits at x 17, y 98. Every other rail and detail page title
  (`h1.page-intro__title`) sits at x 24, y 93, at the same 28/33.6 size. The difference is the
  hero's 1 px border plus 4 px padding-top, and no 8 px inline inset. theme.css:529–531 says
  `--page-intro-top` is "the same on every page, so a title does not jump when you swipe between
  tabs". It jumps 7 px left and 5 px down.
- Why it matters: Overzicht is the home tab. A title that shifts on every swipe reads as
  careless (apple-design §16 Craft, Familiarity).
- Proposal: give the hero's head the page intro's inset with existing tokens:
  `.card--hero .card__head { padding-inline: var(--space-2) }`, and move the 4 px + 1 px so the
  title lands on y 93. Either set `margin-top: calc(-1 * (var(--space-1) + 1px))` on the head, or
  move the hero's top padding below the head. The chip on the right keeps its x (24 from the
  right is fine).
- Affects: Overzicht only. Check `OverviewPage.kt` (HealthScoreCard) for the same offset.
- Complexity: S · Risk: the ring and legend below are centred and unaffected; re-check 320 px.
- Priority: P2

### 2.2 Full-width cards pad 24 on some pages and 16 on others
- Status: VERIFIED (metrics); CSS line for `.cm-dest` and `.ch-card2` not located · Kind: defect
  (inconsistency)
- Where:
  - Community `page-community-0.png`: `section.card.cm-dest`, padding 16, content at x 33.
  - Uitdagingen `detail-challenges-0.png`: `button.card.ch-card2`, padding 16.
  - Training `detail-training-3.png`: the fifth `area-chart--mini` spans the full width (x 16,
    w 362) at padding 16.
  - Compared with Overzicht, Gezondheid and every detail card: padding 24, content at x 41.
  - Rule: components.css:16–23 ("full-width cards pad with --space-5, two-up cards with
    --space-4").
- Problem: content left edges are x 41 in one set and x 33 in the other. Community's two cards
  are the only rail-page cards at 16. The Instellingen identity card is also 16, which is right
  there, because it lines up with the grouped rows below it.
- Why it matters: the system says there is one card. Two paddings break the left edge you see
  between tabs.
- Proposal: `.cm-dest` and `.ch-card2` use `--space-5`, as the rule says. A mini chart left
  alone in its row either keeps its two-up width or takes `--space-5`.
- Affects: community.css (`.cm-dest`), challenges.css (`.ch-card2`), area-charts-grid (an odd
  number of minis). Android's equivalents were not checked.
- Complexity: S–M · Risk: the podium and challenge cards lose 16 px of width; check that long
  names still fit at 320 px.
- Priority: P2

### 2.3 Equivalent card stacks use 16, 12 and 8
- Status: VERIFIED (gaps) · PREFERENCE (which value) · Kind: preference / consistency
- Where:
  - `.stack` 16 (theme.css:751–755), between all page cards.
  - `.grid` 12 (dashboard.css:9–12): Gezondheid pillars side by side 12, but 16 down to the
    trend card.
  - Mini-chart grid 12 × 12 on Slaap / Voeding / Training (`detail-sleep-2.png`; its own rule,
    not located).
  - `.goals-list` / `.goals-slot` 12 (goals.css:72–77), with `.goals-view` 8 (goals.css:53–57).
  - Uitdagingen cards **8** (`detail-challenges-0.png`: 450 → 458, 622 → 630).
- Problem: three spacings for "a list of cards of one kind". Uitdagingen's 8 is half the page
  gap, so its cards read as one block, while the same-sized cards on Community and Doelen
  sit 16 and 12 apart.
- Proposal: pick one list gap (12, `--space-3`, already used by grids and goals) for repeated
  cards of one kind, and keep 16 between different blocks. Uitdagingen 8 → 12. Needs the owner's
  choice.
- Affects: the places above, plus Android's equivalents (not checked).
- Complexity: S · Risk: low.
- Priority: P3

### 2.4 `.settings-rows` cards: top 13 px, bottom 25–26 px; a second divider and row style
- Status: VERIFIED · Kind: defect
- Where:
  - Instellingen › Over de app (`detail-settings-about-0.png`): App card 167–274. "Naam" starts
    13 px below the card top; "Versie" ends 26 px above its bottom.
  - Voorkeuren › Nu in gebruik (`detail-settings-preferences-0.png`): 473–720, 13 / 25.
  - Web: health.css:395 (`.metric-row:first-child { padding-top: 0 }`, no `:last-child` rule);
    settings.css:695 (`.card.settings-rows { padding: 12px 16px }`); health.css:384–392 (row
    padding 12, `border-top`); settings.css:47–56 (settings-card hairline from the row inset to
    the card's right edge).
- Problem:
  - The last metric row keeps its 12 px padding-bottom on top of the card's 12, so the card is
    twice as heavy below as above.
  - Its hairlines run x 33–361, inset both sides. The choice card right above it (Stelsel) runs
    its hairline x 33–377, to the card edge.
  - Its rows are 47 tall, against 56 for every other settings row; on Over de app the App card
    (47) sits directly above Juridisch (56).
  - The comment at settings.css:692–694 promises "the same inner padding as the choice lists
    beside them".
- Proposal: `.settings-rows .metric-row:last-child { padding-bottom: 0 }`. Better still, make
  these rows the settings card's rows: padding 0 on the card, each row at `--space-3`
  `--space-4` and 56 min-height, with the shared `::before` hairline. Then one row style holds
  across Instellingen.
- Affects: every `.card.settings-rows`. Possibly `.integration__body .metric-rows` in the
  devices panel (settings.css:627; not measured).
- Complexity: S · Risk: the device rows (`.metric-row--device`) reuse `.metric-row`; check the
  devices panel.
- Priority: P2

### 2.5 Mini area-chart cards and hint-less chart cards are bottom-heavy
- Status: VERIFIED (metrics) · cause HYPOTHESIS · Kind: defect (balance)
- Where:
  - Every `section.card.area-chart--mini` on Slaap, Voeding and Training
    (`detail-sleep-2.png`, `detail-sleep-3.png`): first content 17 px below the top, the day
    ticks end 30 px above the bottom.
  - Chart details without a "Tik of schuif" hint (`detail-sleep-skin_temp-0`,
    `detail-sleep-respiratory_rate-0`, `detail-nutrition-energy-0`,
    `detail-training-training_load-0`): ticks end 45 px above the bottom, against 25 when the
    hint is there.
- Problem: the space reserved under the axis (or for the absent hint) stays empty. The minis
  look top-light (17 / 30) and the hint-less chart cards end in an empty band.
- Proposal: confirm the rule in area-charts.css / health.css (the axis margin or a hint slot
  that keeps its height when empty) and collapse it when there is no hint. Balance the minis to
  ≈ 17 / 17–20.
- Affects: the area-chart component everywhere; check `HistoryChart.kt` / `Charts.kt`.
- Complexity: S–M · Risk: chart heights change; the CHARTS.md axis positions must not move.
- Priority: P2

### 2.6 A text link as a card's last element enlarges the bottom inset
- Status: VERIFIED (metrics) · PREFERENCE (fix) · Kind: preference
- Where:
  - Overzicht "Eén kleine suggestie": the link box ends 17 px above the card bottom; the text,
    centred in its 44 px target, ends ≈ 29 px above it, against 25 at the top.
  - Slaap and Training "Nuttige inzichten" (29 to the box).
  - Voeding rating card (16 to the box).
  - Inzicht note cards (5 to the box).
  - Licenties "Bron" cards (`detail-legal-licenses-8.png`): text ≈ 25–28 above the bottom,
    against ≈ 20 at the top.
  - The existing correction: `.calibration__open { margin-bottom: calc(-1 * var(--space-2)) }`
    (dashboard.css:148–153).
- Proposal: apply that convention once to every `.text-link:last-child` in a card, so the
  target keeps 44 px and the visible text sits 24 from the edge.
- Complexity: S · Risk: overlaps are allowed only into padding (the hit area stays in the card).
- Priority: P3

### 2.7 Doelen: intro → switch 7 px, against 15–16 px intro → content elsewhere
- Status: VERIFIED · PREFERENCE · Kind: preference / consistency
- Where: `page-goals-0.png`: lede ends at 150, the Actief / Afgerond switch starts at ≈ 157 and
  ends at ≈ 197, the card starts at 213. goals.css:13–17 (`.goals-intro { gap: var(--space-2) }`).
- Problem: the switch hangs off the lede. Gezondheid, Instellingen and every detail leave 15–16
  under the intro.
- Proposal: `.goals-intro` gap to `--space-4`, or put the switch in the `.stack` (16).
- Complexity: S · Risk: none.
- Priority: P3

### 2.8 A label above a card sits 8 px from it, 12 px with a lede
- Status: VERIFIED · PREFERENCE · Kind: preference
- Where: Account (`detail-settings-account-1.png`): "Eenmalig in te vullen" eyebrow 884–900,
  lede 904–942, card at 954 (12). Without a lede, eyebrow → card is 8. settings.css:12–29
  (`.settings-eyebrow` margin-bottom 8, `.settings-block__lede` margin-top −4 and
  margin-bottom 12).
- Problem: the same "label over its card" relationship has two distances.
- Proposal: lede margin-bottom `--space-2`.
- Complexity: S · Priority: P3

### 2.9 Floating "+" and the OwnifyAI grip over content
- Status: VERIFIED · Kind: usability (minor)
- Where:
  - FAB 333–377 × 726–770 (components.css:1853–1883: `bottom: dock-clearance + 16`, flush with
    the column's right edge through `--fab-x`). The grip's 128 × 40 target ≈ 132–260 × 746–786
    (components.css:1580–1586).
  - Mid-scroll: the + covers the SpO₂ "Di Wo" ticks (`detail-sleep-1.png`).
  - At rest (step 0) the + lies over the text of `detail-sleep-bed-0`, `detail-training-steps-0`,
    `detail-training-energy-0`, `detail-entry-training-0` and `detail-research-c14-0`. The grip
    lies over text on almost every page's first screen.
  - At the scroll end nothing lies under either on any page: the last card ends 28 px above the
    + and 48 px above the grip.
- Problem: this is the normal behaviour of floating glass. The dock's scroll-edge fade
  (components.css:1567–1577, 134 px) is only ≈ 0.5 → 0 strong where the + sits, so text under it
  stays fairly legible.
- Proposal: keep the end-of-scroll clearance (correct). Optional PREFERENCE: none needed. Do not
  enlarge the bottom padding.
- Complexity: – · Priority: P3

### 2.10 Checked and fine
- Label and value in settings rows (15 px label, 13 px value) are vertically centred within
  0.5 px. Their baselines are within ≈ 1 px (Account: Gebruikersnaam 466–488 / dev_jij 468–487).
- Title → first content on every page with `.page-intro`: 15–16 px. Card → card: 16 (±1 from
  rounding). Settings group rhythm: eyebrow 8 → card, 16 → next eyebrow. The bottom clearance of
  154 is the same on every page.

## Not checked / could not verify (stopped early)

- **Android** (`$A/android/*/app-*.json` dp compared with web px): not done. That includes
  whether "Vandaag" and the card paddings match on Android.
- **320 px** (`$A/web/small-dark/`): overflow, cramped rows and wraps.
- **Popups and sheets**: account popup (`acc-*`), login (`$A/web/live/*`), goal and challenge
  wizards, confirm dialogs, devices panel, wheel.
- **Off-scale values**: the grep of raw px in CSS and raw `.dp` in Compose against
  `--space-*` / `Ownify.Space*` (scale 4/8/12/16/24/32). Raw values spotted in passing: `2px`
  margins (components.css:118, settings.css row value), `3px` (health.css:411), the 56 px row
  min-height (settings.css:71) against `--row-min` 52.
- **Causes not located**: the 31 px between the last Instellingen card and Uitloggen; the 80 px
  of extra bottom on the Scorekompas (clearance 234); the 35 px bottom of challenge 2–3 card 2;
  the mini-chart grid rule.
- **Other screens**: the Inzichten list (stitching unreliable), the leaderboard detail, White
  Mode, English and imperial.
- **Licenties** was captured only in part (the capture stops mid-page), so its end could not be
  checked.
