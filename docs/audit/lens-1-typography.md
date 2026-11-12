# Lens 1 — Typography and visual hierarchy

Reviewer 1 of 9. Read-only; evidence `$A` = the Phase 1 set (BRIEF.md). Skill
loaded: `anthropic-skills:apple-design` (§15 typography: hierarchy from weight,
size and leading as a set; §16 clarity: the most important thing most obvious).
Numbers are from the metrics JSON (web: CSS px; Android: sp/dp from the layout
dumps). The review was stopped early on the coordinator's instruction: see
"Not checked" at the end.

## Summary

1. **Login popup (live):** the dialog heading "Inloggen" is 15/600. That is only
   2 px larger than the underlined "Wachtwoord vergeten?" link (13/600, primary
   ink), and it repeats the 13/600 button label "Inloggen". The link competes
   with the heading, and the button reads weakest.
2. **Dialog titles come in three sizes for one role**: the account and wizard
   panels use 15/600 (`.card__eyebrow`, `PanelHead`), sheets use 17/600
   (`.ch-sheet__title`, `SheetHead`) and AI consent uses 22/600. Inside the
   15/600 panels, the step's own title (17/600) is larger than the panel title.
3. **Card heads come in two sizes for one anatomy** (tile, title, sub, chevron):
   Overzicht, Gezondheid and the details use 15/600, Community uses 17/600. At
   15/600 the head equals the item titles inside it, so the hierarchy is flat.
   THEME.md also calls "eyebrows" Caption caps, while `.card__eyebrow` is
   Body semibold.
4. **Primary actions have two label sizes**: `.btn`/`.btn--primary`/`Btn`
   use 13/600, `.glass--cta`/`GlassCta`, the welcome buttons and `.ch-act` use
   15/600. Quiet destructive actions (`.btn--link`: "Uitdaging verlaten",
   "Verwijderen") use 11/600.
5. **Web and Android differ in 7 places** (weight or size of the same text): the
   pillar values, the nutrition grams, the training-session duration, the
   goal-wizard option meta, "niet gelogd", the neutral verdict bubble and
   "Uitdaging verlaten".
6. **Text that wraps badly**: "Geen bestand gekozen" sits in a 68 px box, so it
   breaks to 2 lines and clamps to "Geen bestand…" (both apps). Community's
   "Nieuwe uitdaging" and "Code invoeren" break to 2 lines. "Tijd in bed +
   Regelmaat" wraps and pushes its value 17 px below its neighbour's.
7. **The scale itself is respected.** Every visible size is a step or derived
   from one. The gaps are the weights outside the step table (Title at 700,
   Caption at 400) and titles that inherit body leading (1.45) where THEME
   says each rule names its own step's leading.
8. **Contrast:** rendered Dark muted text on real cards measures 5.7–6.3:1, so
   it passes. However, THEME.md documents Dark `--text-muted` at 4.2:1 at
   worst, and `tools/theme-test.php` only enforces 4.5:1 in White. Nothing
   guards Dark small text.

## Measured type table (web, all captured sets; count of text elements)

| fs / fw / line-height ratio | count | main roles |
|---|---|---|
| 13/400/1.45 | 8773 | hints, ledes, metas, row values (Subhead; THEME line height 1.35) |
| 11/400/1.45 | 3881 | chart ticks, legends, milestones, wizard count (Caption at 400) |
| 15/400/1.45 | 3315 | body, row labels |
| 11/600/1.0 | 2927 | tab labels |
| 13/400/1.35 | 2276 | Subhead with its own line height |
| 11/600/1.45 caps +0.66 px | 1853 | eyebrows, field labels |
| 13/600/1.45 | 1828 | `.btn`, `.text-link`, area-chart titles, goal-option labels |
| 15/600/1.45 | 1372 | `.card__eyebrow`, `.card__open`, insight titles, `.glass--cta` |
| 28/700 | 1064 + 786 | page titles (1.2); score values, goal % (1.0) |
| 15/700/1.0 | 747 | pillar values |
| 22/600/1.45 | 613 | app name, empty-state title, challenge code |
| 17/600/1.45 | 503 | goal name, detail bar title, Community card head, wizard title, account username |
| 22/700/1.45 | 391 | Gezondheidsscore heading, detail hero label (Title at 700) |
| 58.08/700 | 377 | hero ring number (44 × 1.32, derived) |

Sizes not on the scale: 58.08 (hero number, 44 × 1.32), 26.4 and 16.8 (the
empty "—", 44 × 0.6 and 28 × 0.6), and 10.4, 12 and 12.8 (avatar initials
`.ch-av`, derived from the avatar). All are derived from a step, which THEME
allows. One-off tracking: the hero number uses `letter-spacing: -0.05em`
(components.css:745) instead of a `--tracking-*` token.

Android (all 111 dark dumps): 13/400 (855), 11/600 (776), 11/400 (518), 13/600
(240), 15/600 (192), 15/400 (189), 22/600 (129), 28/700 (87). These are the
same steps; the off-scale values are the same derived ones (58.08, 12, 12.8,
10.4).

---

### 1.1 Login popup: the heading, the link and the button have no clear order
- Status: VERIFIED · Kind: usability
- Where: live `$A/web/live/phone-dark-login.png/.json`, `phone-light-login`,
  `phone-dark-forgot`, `phone-dark-register` · components/account-modal.php:84-87
  (the title is a `.card__eyebrow`), :250-254 (the link inside `.account__hint`),
  :259 (the submit is a plain `.btn`) · assets/css/components.css:146-151,
  account.css:110-118 (`.account__link`), :167-175 (`.account__label`),
  :266-271, components.css:534-548 (`.btn`) · Android SignedOutPanel.kt:137-139
  (`PanelHead`), Overlays.kt:250-265 (`JStyle.Eyebrow`), SignedOutPanel.kt:188-193
  (`AccountLink`: Subhead SemiBold underlined), :212-215 (`FieldLabel`)
- Problem: measured on the live site:
  - Heading "Inloggen": 15/600, primary ink, 62×22.
  - "Wachtwoord vergeten?": 13/600, primary ink, underlined (ink .35), 146×19.
  - Field labels "GEBRUIKERSNAAM" and "WACHTWOORD": 11/600, caps, +0.66 px,
    `--text-muted` (.60; White .66).
  - Button "Inloggen": 13/600, in the neutral `.btn` glass.
  - The welcome screen's buttons behind it: 15/600.

  So the link is the heading's weight and colour, only 2 px smaller, and the
  underline gives it extra presence. The heading and the button say the same
  word. The button's label is smaller than the heading and the same size as the
  link, so the one action the dialog leads to is the least prominent element.

  On "Wachtwoord vergeten" the panel title stays "Inloggen" (15/600, y 263),
  and the step's own title "Wachtwoord vergeten" sits right under it at 17/600
  (y 306). The inner title is larger than the dialog title.
- Why it matters: a sign-in dialog should be read as title → fields → action,
  with recovery last. Here the eye meets two equal-weight bold lines before the
  action.
- Proposal (existing steps only):
  - Panel title: Headline 17/600 (THEME: "a dialog's name"), see 1.2.
  - Field labels: keep Caption caps.
  - Submit: the primary look (`.btn--primary` / `BtnLook.Primary`) with a
    Body 15/600 label, as `.glass--cta` already has (see 1.4).
  - "Wachtwoord vergeten?": Subhead 13/400 in `--text-secondary`, underlined.
    Android already has this quiet link (`QuietLink`, SignedOutPanel.kt:594-600:
    Subhead, muted, underlined). Using it on both apps as the recovery link is
    a reuse, not a new style. The web has no such class yet: **NEW CONVENTION**
    on the web unless the owner accepts the Android `QuietLink` as its
    definition.
  - On the forgot and verify steps, let the panel title name the step and drop
    the duplicate inner `.account__username` title. Alternatively, keep the
    inner title and set the panel title to "Account". Either way only one title
    shows.
- Affects: register (the title "Registreren" repeats the "Account aanmaken"
  flow), forgot, verify, link pages, the Google-username step (all
  account-modal.php steps); Android SignedOutPanel.kt:414, :489, :543.
- Complexity: S · Risk: the panel title is shared with the signed-in account
  view (1.2), so change both together.
- Priority: P1

### 1.2 Dialog and sheet titles: three sizes for one role, and the inner step outranks the panel
- Status: VERIFIED · Kind: defect (inconsistency)
- Where (web title → Android equivalent):

  | Dialog | Evidence | Title | Web source | Android source |
  |---|---|---|---|---|
  | Account, Vrienden | `pop-phone-dark/acc-main`, `acc-friends` | 15/600 | `.card__eyebrow`, account-modal.php:84 | `PanelHead`, Overlays.kt:262 |
  | Goal wizard "Nieuw doel" | `goalwiz-1` | 15/600 | `.card__eyebrow` | |
  | Challenge wizard "Nieuwe uitdaging" | `chwiz-1…review` | 15/600 | `.card__eyebrow`, challenges.js:613 | |
  | Code sheet "Code invoeren" | `ch-code` | 17/600 | `.ch-sheet__title` | `SheetHead` → `JStyle.Subtitle`, BottomSheet.kt:218-227 |
  | AI consent "OwnifyAI gebruiken?" | `ai-open` | 22/600 | `.ai-consent__title` | |

  Inside the 15/600 panels the content title is larger than the panel title:
  "dev_jij" is 17/600 (account.css:149-153), "Wat wil je bijhouden?" and the
  other wizard steps are 17/600 (`.wizard__title`, goals.css:713-717).
- Problem: one role, "a dialog's name" (THEME: Headline 17/600), is drawn at
  15, 17 or 22 depending on the component. In the panels the dialog's name is
  subordinate to its first line of content.
- Why it matters: Familiarity and consistency (apple-design §16). The same
  surface should name itself the same way.
- Proposal: every panel and sheet head at Headline 17/600. Concretely,
  `.account__head .card__eyebrow` and `.wizard__head .card__eyebrow` take
  `--fs-headline`, `--tracking-headline` and `--lh-headline`, and `PanelHead`
  uses `JStyle.Subtitle` like `SheetHead`. The wizard keeps its step question
  at 17/600, but the step counter above it already separates the two. If the
  owner prefers a small panel name with a large step question (the iOS sheet
  pattern), then document 15/600 as "panel name" in THEME.md and change the
  code sheet's `SheetHead` to match. Decide one; do not keep both.
- Affects: account (both views), the friends page, the goal wizard, the
  challenge wizard, the code sheet, the Android `SheetHead` users
  (ChallengeSheets.kt), settings-confirm.php dialogs (not captured, see Not
  checked).
- Complexity: S · Risk: the line-height and baseline alignment with the 34 px
  close button.
- Priority: P1

### 1.3 Card heads: two sizes for the same head, and a flat hierarchy inside the card
- Status: VERIFIED · Kind: defect (inconsistency) + usability
- Where: `$A/web/phone-dark/page-overview-1.png` and `page-community-0.png`,
  `detail-sleep-0`/`-1`, `detail-challenge-1-0` · components.css:146-151
  (`.card__eyebrow` 15/600), :1103 (`.cm-head .card__eyebrow`), challenges.css:34
  (`.cm-head__title` 17/600) · Android `JStyle.Eyebrow` Components.kt:129;
  `app-community.json` shows "Ranglijst" at 17/600 and `app-overview` shows
  "Primair doel" at 15/600.
- Problem:
  - Overzicht's "Nuttige inzichten" (tile + title + sub + chevron, 15/600) and
    Community's "Ranglijst" and "Uitdagingen" (the same anatomy, 17/600) differ
    by 2 px.
  - Inside "Nuttige inzichten" the item titles ("Training en slaap", web
    `.insights__title`; Slaap detail "Slaapduur") are also 15/600. The card's
    head and its items are set identically, and only the tile separates them.
  - On the Slaap detail, the card head "Slaapverloop" (15/600) is outranked by
    the date inside the card, "11 nov" (15/700, `.heart-chart__date`).
  - THEME.md's table says card titles are Title 22/600 and eyebrows are
    Caption caps. The code's `.card__eyebrow` and `JStyle.Eyebrow` are Body
    semibold, so the name and the documentation disagree with the code.
- Why it matters: apple-design §15 builds hierarchy from size, weight and
  leading as a set. A card head that matches its rows does not group them.
- Proposal: the card head at Headline 17/600, as Community already has, for
  `.card__eyebrow` and `JStyle.Eyebrow` wherever it heads a card. Item titles
  stay Body 15/600. Then fix THEME.md's table: add the card head (Headline),
  and keep "eyebrow" for the Caption caps (`.settings-eyebrow`). If the owner
  wants to keep 15/600, change Community to 15/600 and document it. Owner's
  choice.
- Affects: every `h2.card__eyebrow` (≈ 35 components: Verloop, Primair doel,
  Nuttige inzichten, Slaapverloop, Hartslag, Wie draagt wat bij, Iemand erbij
  halen, the goal-detail cards, the nutrition cards, the leaderboard,
  community-badges…), Android `JStyle.Eyebrow` users.
- Complexity: M (many places, one class) · Risk: heads that wrap at 320 px with
  a tile beside them.
- Priority: P2

### 1.4 Button labels: the primary action in two sizes, and a quiet action at 11 px
- Status: VERIFIED · Kind: defect (inconsistency)
- Where (measured label size/weight):

  | Element | Label | Web source | Android source |
  |---|---|---|---|
  | `.btn`, `.btn--primary`, `.account__submit`, `.settings-logout`, `.goals-empty__cta`, `.btn--ghost` | 13/600 | components.css:534-548 | `Btn`, Components.kt:536 |
  | `.glass--cta` (Volgende, Opslaan, Uitdaging maken) | 15/600 | | `GlassCta`, GlassControls.kt:261 |
  | `.welcome__button` | 15/600 | | |
  | `.ch-act__label` | 15/600 | | |
  | `.btn--link` | 11/600, `--text-secondary` | settings.css:677-690 | |

  Evidence: `ai-open` ("Toestaan en beginnen" `.btn--primary` at 13), `goalwiz-1`
  ("Volgende" at 15), live login (13), `detail-challenge-1-1` ("Uitdaging
  verlaten" at 11), `acc-friends` ("Verwijderen" at 11).
- Problem: THEME's "primary" look is `.btn--primary` (13) and `.glass--cta`
  (15), so the same look has two type sizes. A destructive action ("Uitdaging
  verlaten", challenges.css:679 with `.btn--link`) is the smallest text on its
  page. Android draws it at 13/600, so the apps differ too.
- Why it matters: the action a step leads to should be equally prominent
  everywhere. A leave or delete set as 11 px grey reads as fine print.
- Proposal: full-width and primary actions take Body 15/600 (the `GlassCta`
  size) on both apps: `.btn--primary`, `.account__submit`, `.account__logout`,
  `.settings-logout`, and `Btn` when full-width or `BtnLook.Primary`. Inline row
  buttons (Opslaan, Uploaden and Zoeken next to a field) may stay Subhead
  13/600. `.btn--link` takes Subhead 13/600 (as Android already does for Uitdaging
  verlaten). Whether it should be the gold `.btn--caution` instead is lens 3's
  call. The button text sizes are not written in THEME.md, so ask the owner
  before changing them.
- Affects: every `.btn` / `Btn`, `device__actions .btn--link` (Ontkoppelen),
  `friend-person.php:56`, `settings-integration.php:117,123`,
  challenge-detail.php:115.
- Complexity: M · Risk: rows where a 15 px label no longer fits next to a field
  at 320 px (Opslaan, Uploaden).
- Priority: P2

### 1.5 Web ↔ Android: the same text in a different weight or size
- Status: VERIFIED (both dumps and both sources) · Kind: defect (parity)
- Where / Problem:

  | Text | Web | Android |
  |---|---|---|
  | Pillar values "93/83/84" (`page-overview-0` vs `app-overview`) | 15/700, components.css:876-885 | 15/600, Components.kt:810-812 |
  | Nutrition grams "275 g" (`detail-nutrition-1` vs `app-nutrition-open`) | 15/700, nutrition.css:243 | 15/600, NutritionView.kt:551 |
  | Session duration "39 min" (`detail-training-1` vs `app-detail-training`) | 13/600, training.css:118 inheriting Subhead | 11/600, TrainingView.kt:204 |
  | Goal/challenge wizard option meta "Maximaal 2.400 mg" (`goalwiz-1` vs `app-wizard-1`, `app-challenge-create`) | 13/400, goals.css:1379-1386 | 11/400, GoalWizard.kt:570 |
  | "niet gelogd" (`detail-nutrition-2` vs `app-nutrition-open`) | 13/400, nutrition.css:379 | 11/400, NutritionView.kt:596 |
  | Neutral verdict bubble "Geen oordeel" | 11/600 (`.glass--chip`) | 11/400, NutritionView.kt:628-630 (`strong` only for in/high) |
  | "Uitdaging verlaten" | 11/600 | 13/600 |

- Why it matters: CLAUDE.md requires the same hierarchy on both apps. None of
  these is in PARITY.md.
- Proposal: follow the web (the source of truth) for all of these except
  "Uitdaging verlaten", which should move to 13/600 on the web (1.4). Numbers
  take 700 (THEME: "A number takes its step's size in 700").
- Affects: the rows above.
- Complexity: S · Risk: none beyond the text width.
- Priority: P2

### 1.6 Choice rows in the wizards: two label sizes
- Status: VERIFIED · Kind: defect (inconsistency)
- Where: `goalwiz-1`, `chwiz-1`, `chwiz-invite` (`.goal-option__label` 13/600 with
  meta 13/400) vs `goalwiz-2`, `chwiz-2` (`.goal-type__label` 15/600, hint 13/400
  secondary, example 13/400 muted) · goals.css:1370-1377 vs :1420-1422 ·
  Android GoalWizard.kt:569-570 (13/600 + 11/400).
- Problem: two consecutive steps of the same wizard list choices with labels
  of 13 and 15. In the 13/600 rows the name and its meta are the same size and
  differ only in weight and colour. THEME: "Body 15 — a row's label".
- Proposal: `.goal-option__label` at Body 15/600 (as `.goal-type__label`), with
  meta at Subhead 13/400 muted on both apps (Android meta 11 → 13, see 1.5).
- Affects: the goal wizard step 1, the challenge wizard step 1 and its invite list.
- Complexity: S · Risk: five rows in view become about four (the list scrolls).
- Priority: P2

### 1.7 Text that wraps or truncates badly
- Status: VERIFIED · Kind: usability
- Where / Problem:
  - Account › Profielfoto (`pop-phone-dark/acc-main`, `acc-photo-picked`;
    Android `app-account` lines=2): "Geen bestand gekozen" is 13/400 in a box
    68×35 px. It breaks to two lines and clamps to "Geen bestand…", and a picked
    name ("test-avatar.png") is also cut to 68 px. The status (13) is larger than
    the control that sets it, "Bestand kiezen" (11/600 chip), and the Uploaden
    button beside it takes 94 px. account.css:219-244, AccountPanel.kt:365-376.
  - Community action tiles (`page-community-0`): "Nieuwe uitdaging" and "Code
    invoeren" (`.ch-act__label`, 15/600) break into two lines in 94 px wide
    labels. On Android "Nieuwe uitdaging" wraps and "Code invoeren" does not, so
    the pair is uneven there.
  - Slaap detail small charts (`detail-sleep-1`, `app-detail-sleep-2`): "Tijd in
    bed + Regelmaat" (13/600) wraps to 2 lines, so its value "93" sits at y 644
    while its neighbour "96%" sits at y 627. The values of a pair do not line
    up.
  - The challenge name in the Community stack wraps to 2 lines beside the
    "SAMEN" chip (`page-community-0`, 170 px wide).
- Proposal: the file field shows a one-line name with a middle ellipsis inside
  the pill, at Subhead and `--text-muted`, and the chip at Subhead 13/600. The
  layout of the row is lens 3's. Give the small-chart titles one line with an
  ellipsis and the full name in the detail, or reserve two lines for both
  titles of a pair. The `.ch-act` labels need either one line (Subhead 13/600)
  or a deliberate two-line layout on both apps.
- Affects: as listed; English is longer ("No file chosen", "New challenge") —
  not measured (Not checked).
- Complexity: S–M · Risk: low.
- Priority: P2

### 1.8 Uppercase labels: consistent, with three misuses
- Status: VERIFIED · Kind: preference / defect
- Where: every caps label on web and Android is Caption 11/600, +0.06em
  (0.66 px), `--text-muted` (`.account__label`, `.wizard__label`,
  `.settings-eyebrow`, `.ch-eyebrow`, `dt` in the challenge review,
  `.goal-date__label`, `.goal-hero__category`). Chips are Caption caps in
  `--text-secondary`. That part is uniform and correct. Contrast measured
  (Dark, on the card): 11 px muted text reads 5.9–6.3:1.
- Problems:
  1. A whole sentence in caps: `chwiz-invite` "NOG NIEMAND GEKOZEN — JE KUNT OOK
     STRAKS DE CODE DELEN." (2 lines, 311×32) is rendered as a
     `.wizard__label` (challenges.js:827; Android ChallengeSheets.kt:630
     `FieldLabel`). Caps are for short labels; a sentence in caps is slow to read.
  2. `.settings-eyebrow` carries long strings on Licenties ("Android-app ·
     AndroidX (Jetpack …) · 3 pakketten", 763 caps instances in the set).
  3. The calendar weekday heads (`chwiz-3-calendar`, `li`) are the only caps at
     weight 400.
- Proposal: (1) render the sentence as `.wizard__hint` / Subhead muted, sentence
  case, and keep "TE KIEZEN" as the caps label. (2) Put the package count in the
  row and keep the eyebrow short. (3) Weekday heads at 600 like every other caps
  label, or not in caps.
- Complexity: S · Priority: P3

### 1.9 Numbers and units: tabular, but units are treated three ways
- Status: VERIFIED (sizes) / HYPOTHESIS (tabular on every value, see Not checked)
  · Kind: preference
- Where (measured):
  - Scores: "87" 58.08/700 + "van 100" 13/400 secondary; Gezondheid "93"
    28/700 + "/100" 13/400 muted; Slaap "6:58" 22/700 + "u" 13/400 muted (0.59 ×)
    + "geslapen" 11/400.
  - Small charts: "96%" and "40 ms" 17/700 with the unit inline at full size;
    "1,8 L" 17/700 + "van 1,8 L" 11/600 muted; metric tiles' unit 13/400 with
    line height 1.1.
  - Goal hero: % sign 17/400 beside 44/700.
  - Tabular figures are declared on `.score-ring__number`, `.pillar-ring__value`
    and `.nutrition-row__grams` (components.css:688-694, :876-885,
    nutrition.css:243) and on Android through `tabular = true`.
- Problem: a unit is sometimes reduced to Subhead in muted ink ("u", "/100"),
  sometimes left at the value's size ("ms", "%", "bpm" in the small charts), and
  sometimes a Caption line ("van 1,8 L"). "6:58 u" and "40 ms" sit on the same
  page with different unit treatments.
- Proposal: one rule, written into THEME.md's "a number takes its step's size in
  700": the unit follows the figure at Subhead 13/400 `--text-muted`, baseline
  aligned. That applies to `.area-chart__value` on both apps (AreaCharts.kt).
  % stays attached at the figure's size or is the derived sign as on the goal
  hero; the owner picks one.
- Complexity: S–M · Priority: P3

### 1.10 Weights outside the step table, and titles with body leading
- Status: VERIFIED · Kind: defect (system drift)
- Where / Problem:
  - Title at 700: `.card--hero .score-ring__label` (components.css:755-759,
    "Gezondheidsscore", the detail heroes' "Slaap/Voeding/Training", 22/700;
    Android `JStyle.HeroLabel` Components.kt:126), and the long challenge name
    `h1.page-intro__title.ch-dhead__name.is-long` (22/700). THEME: Title is 600.
  - Caption at 400 (11/400): about 5,100 instances. These are `.wizard__count`
    (goals.css:681-686), chart ticks, legends, milestones and `.cm-podium__pts`.
    THEME: Caption is 600.
  - Line height: THEME says "every rule with a size names its step's", but
    `.app-header__name` (22, 583×), `.goals-empty__title`, `.goal__name`,
    `.detail__title`, `.cm-head__title`, `.wizard__title`, `.account__username`
    and `.ch-sheet__title` inherit body's 1.45. Android's `OwnifyType.style()`
    gives the same sizes 1.2 and 1.25 (OwnifyTheme.kt:870-877), so a wrapped
    17 px title is taller on the web than in the app. Subhead is 1.45 in 8,773
    instances and 1.35 in 2,276.
- Proposal: either add the variants to THEME.md (Title Bold for a hero's name;
  Caption Regular for chart axes and counters) or bring the rules back to the
  step weights. Add `line-height: var(--lh-title|--lh-headline)` to the listed
  title rules. This is documentation plus small CSS changes, and it needs the
  owner's yes for the variants.
- Complexity: S · Risk: multi-line titles get a little tighter.
- Priority: P3

### 1.11 Muted-text contrast in Dark is not guarded
- Status: VERIFIED (documentation and test gap) · HYPOTHESIS (a real surface
  below 4.5) · Kind: defect (a11y safeguard)
- Where: docs/THEME.md "Contrast" table: Dark `--text-muted` 4.2:1 and
  `--attention` 3.6:1 at worst. tools/theme-test.php:308-313 checks White ≥ 4.5
  and White ≥ Dark only.
- Measured (Dark, sampled from `page-overview-1.png`: the mode background pixel
  under each text box, composited with the token):

  | Text | Size | Contrast | Background |
  |---|---|---|---|
  | `.insights__text` | 13 | 5.66:1 | well, rgb(51,51,51) |
  | `.card__meta` | 13 | 5.79–5.92:1 | |
  | `.tab__label` (inactive) | 11 | 5.88–6.25:1 | |
  | `.milestones__label` | 11 | 6.03–6.25:1 | |
  | `.insights__title` (secondary) | 15 | 7.7–8.7:1 | |

  Assumption: the most frequent pixel colour around the text box is the
  background. Real cards pass.
- Problem: the documented worst case (4.2:1) is below WCAG AA 4.5:1 for 11–13 px
  text, and THEME's statement "Every text tier reaches WCAG AA" is true only for
  White. The lightest Dark surfaces (glass chips, the inactive options of the
  range switch on the glass track, a well inside a thick panel) were not
  sampled.
- Proposal: extend `worst()` in theme-test.php (and `ColourSystemTest`) to
  require ≥ 4.5 in Dark for `--text-muted` too, and correct THEME.md. Only if a
  real surface fails, raise Dark `--text-muted` from .60 a step (a token change
  that needs the owner's approval).
- Complexity: S · Priority: P2

### 1.12 Smaller hierarchy points
- Status: VERIFIED · Kind: preference · Priority: P3
- **The Vrienden switch row** "Vriendverzoeken toestaan" has its label in
  `--text-secondary` (account.css:572-578; AccountPanel.kt:676). Every
  Instellingen row label is primary, so this switch reads dimmer than the
  friend names around it (`acc-friends`). Make it primary.
- **Slaap detail** (`detail-sleep-1`): "Bekijk onderzoek" (13/600 primary
  `.text-link`) sits between the chart and its hint "Tik of schuif over de
  fasen…". The hint belongs under the chart and the link after it.
- **The Overzicht goal card** shows the head "Primair doel" at 15/600 and the
  name inside it at 17/600 ("Nog geen doel ingesteld"). That is acceptable as
  label-over-content, but it is the same inversion as 1.3. Resolve both in one
  decision.
- **The wizard step counter** "Stap 1 van 3 · Meting" (11/400 muted) is the
  wizard's wayfinding. At Caption 400 it is the faintest line in the panel;
  Caption 600 (THEME) would do.

## Not checked / could not verify

- **The 320 px set (`$A/web/small-dark`) and English + imperial
  (`$A/web/en-phone-dark`)**: not analysed for wrapping and truncation (stopped
  by the coordinator). Needed: run the same text dump over those sets and
  compare `h` (line count) with the 393 px set per element.
- **White Mode contrast:** a full pixel-sampled pass over
  `phone-light`/`pop-phone-light` was started (script in the scratchpad,
  `lens1/contrast_all.py`) but its result was not read. The Dark thick-glass
  panels, glass chips and the range switch were not sampled either.
- **Confirm dialogs** (`components/settings-confirm.php`), the goal wizard's
  step 3, `wheel-clock` and the AI sheet's chat view: titles not measured.
- **Tabular figures** on `.area-chart__value`, `.sleep-night__value`,
  `.goal__percent`, the board points and `.ch-big__fig`: not confirmed (the
  metrics JSON has no `font-variant-numeric`). Grep the CSS rules.
- **Android:** only overview, community, health, Slaap detail, account,
  settings, nutrition-open and the wizard dumps were compared. The other ~100
  screens were covered only by the automatic same-string comparison (1.5). The
  Robolectric fonts may differ slightly from a device.
- **Live vs local:** the login hierarchy was measured on the live site. The local
  `out-login` differs (no "Wachtwoord vergeten?", Early observation 6) and was
  not compared.
