# Lens 3 — Components, actions and icons

Reviewer 3 of 9 · read-only · skill loaded: `anthropic-skills:apple-design` (highest authority).
`$A` = the evidence set (docs/audit/BRIEF.md). Measurements are CSS px from the web metrics JSON unless noted.
The review was stopped early on the coordinator's instruction. What was not reached is listed at the end.

## Summary

1. **Destructive actions have five different first-step treatments.** There is a gold glass button, a full-width neutral `.btn` with a trash icon, an 11 px caption text button, a muted underlined link and a sheet. The confirm containers come in five shapes too. The most destructive action, *Account verwijderen*, is the quietest, while *Gegevens wissen* is the loudest (3.1, 3.2).
2. **Je gegevens (example 5):** *Downloaden* is a flat `.btn` (fill .08, no shadow), while *Wissen* is the raised gold Liquid Glass (shadow 0 8 20 .26, blur). The row labels use the dimmed ink of the disabled switches (.74). The buttons are 36 tall on the web and 44 on Android (3.2).
3. **Photo upload (example 1):** this is a pill inside a pill with three type sizes (11/13/13). The field is 53 tall beside a 44 button, the empty-state text is cut to "Geen bestand…", and *Uploaden* stays enabled with no file, sending an empty upload (3.3).
4. **The account popup has no primary action.** Every button is the same neutral `.btn`, including *Inloggen* and *Account aanmaken*. `.btn--ghost` is used six times but defined nowhere, so the intended quiet look never renders (3.5).
5. **Vrienden row (example 2):** it is the only Row among pill fields. Its eyebrow repeats its own text, and its badge is a one-off green pill (3.4).
6. **Profile header (example 3):** the only header that shows the photo, the username and the real name is Instellingen → Account's `.settings-hero`. Its three items are repeated about 100 px lower in the Profiel rows. The "thick elements" are most likely the empty card face on either side of the centred content (HYPOTHESIS) (3.6).
7. **Privacy (example 4):** the OwnifyAI block names Gemini four times and says the consent twice. Account → Lichaamsmaten says the same sentence twice. The *Later instelbaar* switches are drawn "on" and contradict live features in the account popup (3.7, 3.8).
8. **Icons:** the Android set is generated from `icons.php`, so the two apps match (48 outline and 10 solid icons). But many shapes carry two or more meanings:
   - moon: Slaap and Thema
   - chart: the Doelen tab and every Verloop heading
   - pulse: sleep insights, heart rate, active minutes and Scorekompas
   - lock: empty, private and locked
   - info: a note, an error, Over de app and Voorwaarden
   - copy: copying a code and Licenties

   The icons are also drawn at 11 different sizes (3.12, 3.13).
9. **Same action, different looks:** *Vriend toevoegen* has four treatments and two icons (plus and user-plus). *Uitloggen* has two looks. The wheel-backed fields are drawn as flat fields and as floating glass on the same form (3.9–3.11).

---

## Findings

### 3.1 Destructive actions: five first-step treatments, five confirm containers
- **Status:** VERIFIED · **Kind:** usability (consistency)
- **Where:**
  - *Gegevens wissen* and *OwnifyAI-gesprekken wissen*: `.btn.btn--caution`, gold glass (pages/settings-detail.php:400; `$A/web/phone-dark/detail-settings-privacy-1.png`).
  - *Doel verwijderen* and *Training verwijderen*: a full-width, left-aligned neutral `.btn` with a trash icon, `goal-manage__button--danger` = text-secondary only (pages/goal-detail.php:520-524, pages/training-session.php:84-86, assets/css/goals.css:534-545). Its look is identical to *Pauzeren* above it.
  - *Vriend verwijderen*, *Uitdaging verlaten* and device *Ontkoppelen*: `.btn--link`, 11/600, secondary ink, no fill (components/friend-person.php:56, pages/challenge-detail.php:115, components/settings-integration.php:117-123, assets/css/settings.css:677-690; `$A/web/pop-phone-dark/acc-friends.png`).
  - *Account verwijderen*: `.settings-delete`, 13 px, muted and underlined (pages/settings.php:83, settings.css:212-224; `$A/web/phone-dark/page-settings-1.png`).
  - The confirm step has five containers:
    - `.settings-action__confirm`: a hairline on top (settings.css:1069)
    - `.goal-confirm`: an attention Note (goals.css:583)
    - `.friend__confirm`: a neutral Well (account.css:530)
    - `.confirm__panel`: a sheet (components/settings-confirm.php)
    - the challenge-leave sheet (assets/js/challenges.js:1091)
  - Android mirrors the same set: `BtnLook.Final` in SettingsDetail.kt:1083 and DeleteLink in SettingsPage.kt:224-241.
- **Problem:** how loud an action looks does not follow its consequence:
  - Wiping health data (the account stays) is the loudest: a raised gold glass button.
  - Deleting the whole account is the quietest: a muted underline.
  - Removing a friend or leaving a challenge is 11 px text.
  - Deleting a goal looks exactly like pausing it.

  THEME.md's *cautious* row says every destructive step, the last one included, is the gold glass, and only Instellingen' actions follow that.
- **Why it matters:** Apple's Familiarity principle says things that look the same must behave the same. People learn which treatment means "this destroys". Five treatments teach nothing, and the inverted Account / Wissen pair misstates the stakes.
- **Proposal:** use one rule with existing parts (owner decision, because it changes THEME.md's wording, so it is a **NEW CONVENTION** of the rule, not a new style):
  - **Trigger:** every destructive trigger becomes the same neutral `.btn` with the trash icon, as the goal's *Verwijderen* is today. *Verwijderen*, *Verlaten*, *Ontkoppelen*, *Wissen* and *Account verwijderen* all take it. It only opens the question, so it is not destructive itself.
  - **Commit:** the gold `.btn--caution` is kept for the irreversible yes, as every confirm already does.
  - **Container:** one confirm container for in-place questions, the attention Note (`.goal-confirm`).
  - **Alternative:** if the owner prefers THEME.md as written, make every trigger gold. That includes *Account verwijderen*, the friend row and challenge leave.

  Either way, *Account verwijderen* must not be quieter than *Gegevens wissen*.
- **Affects:** every destructive action listed above, on both apps, and also the AI history delete (`.ai-history__delete`, not reviewed).
- **Complexity:** M · **Risk:** low; markup and class changes only. The friend row's grid needs a wider trailing column for a 44 `.btn`.
- **Priority:** P1

### 3.2 Example 5 — Je gegevens: Downloaden vs Wissen
- **Status:** VERIFIED · **Kind:** usability
- **Where:**
  - Privacy → Je gegevens: `$A/web/phone-dark/detail-settings-privacy-1.png`, `$A/android/dark/app-settings-privacy-2.png`.
  - Web: pages/settings-detail.php:380-412, config/settings.php:284-315, assets/css/settings.css:468-478 and 1061-1065, assets/css/components.css:534-589.
  - Android: SettingsDetail.kt:1083, 1152 and 1161.
- **Problem:**
  - **Construction.** Measured from detail-settings-privacy-1.json:
    - *Downloaden* `button.btn.settings-action__go` is 113×36: background `rgba(255,255,255,.08)`, border 1px .13, no shadow, no backdrop.
    - Each *Wissen* is 79×36: background pane-body .62 with the gold mix, no border, shadow `0 8px 20px rgba(0,0,0,.26)` plus the glint, and backdrop `blur(10px) saturate(1.9) brightness(1.08)`.

    So the safe action is the flat, older secondary, and the destructive one is the floating material. On the screen the gold buttons read as the primary actions of the card. This is the owner's observation, confirmed.
  - **Labels.** The row labels ("Gegevens downloaden", "Gegevens wissen") use `.settings-toggle__label`, which is `--text-secondary` (.74), the same ink as the disabled *Later instelbaar* switches directly above them. Live switches get `--text-primary` (settings.css:478). Working actions look disabled. Android does the same: `TextSecondary` at SettingsDetail.kt:1152.
  - **Height.** On the web `.settings-action__go` sets `min-height: 36px` (settings.css:1063) and the confirm's buttons 38 (settings.css:1090), against THEME's 44 (`--hit-min`). Android's `Btn` is 44 (Components.kt:525). The two apps differ.
- **Why it matters:** a destructive action should never be the most inviting thing in its group (Apple: the "Agency" and "Responsibility" principles). The safe action should read at least as clearly as the destructive one.
- **Proposal:**
  1. Draw the three triggers alike (see 3.1): neutral `.btn`, 44 tall. Remove the `min-height: 36px` override and the 38 override. Use gold only on the confirm's *Wissen*.
  2. Give the action rows' labels `--text-primary`, as `.settings-toggle--live` has (web settings.css:468-478; Android SettingsDetail.kt:1152).
  3. Keep *Downloaden* as the first row: safe before destructive is already right.
- **Affects:** every `actions` block (only Privacy today), and 3.1.
- **Complexity:** S · **Risk:** none.
- **Priority:** P1

### 3.3 Example 1 — Account popup: the profile-photo field
- **Status:** VERIFIED · **Kind:** defect and usability
- **Where:**
  - Screenshots: `$A/web/pop-phone-dark/acc-main.png`, `acc-photo-picked.png`, `acc-photo-uploaded.png`, `$A/android/dark/app-account.png`.
  - Web: components/account-modal.php:146-162, assets/css/account.css:183-249, assets/js/account.js:194-204 and 247-293.
  - Android: AccountPanel.kt:218-246 (row), 262-266 (empty upload) and 340-376 (FileField).
- **Problem:** measured from acc-main.json:
  - **Pill in a pill.** The field (`label.account__file`, pill, border .08, fill .05) holds a second pill, the 24-tall "Bestand kiezen" chip (11/600, fill .08, its own border). This is a nested control, and it imitates the browser's native file button.
  - **Three type sizes in one row.** The chip is 11/600, the file name 13/400 at .74, and *Uploaden* 13/600. The username field above types at 16.
  - **Height mismatch.** The row is 53 tall (419→472), because the name may take two lines, while *Uploaden* is 44 (424→468). The field's top and bottom do not line up with the button's.
  - **Columns do not line up.** The username row's button *Opslaan* is 86 wide at x 266, and *Uploaden* is 94 wide at x 258. The two fields end 8 px apart (217 vs 209 px wide).
  - **The empty state is cut off.** "Geen bestand gekozen" gets 68 px and is clamped to "Geen bestand…". A picked name breaks mid-word ("test-/avatar.png").
  - **Uploaden is always enabled.** With nothing picked it posts an empty file. The web form is `novalidate` (account.js:247-250). Android sends `ByteArray(0)` on purpose (AccountPanel.kt:257-266), "and the server says what it says". The button looks ready when it cannot do anything.
  - **Focus on open.** Opening the panel focuses the username field (account.js:44-50; Android `rememberFocusOnOpen`, AccountPanel.kt:141). In every capture the field wears the green focus halo, so it looks like the selected field. On a phone this may raise the keyboard (HYPOTHESIS for iOS; for lens 5).
- **Why it matters:** this is the most visibly unfinished control in the app. It carries a desktop-file-picker metaphor on a phone, and its states are wrong.
- **Proposal:** with existing parts.
  - **(A) PREFERENCE.**
    - Drop the file field and its name.
    - The 52 avatar in `.account__identity` becomes the control (`.press`), with a `.text-link` "Foto wijzigen" under "Lid sinds".
    - Picking a file shows it in the avatar at once and uploads it straight away with the same endpoint and the same `account__error` for a refusal. The hint "JPG, PNG of WebP, maximaal 3 MB." stays as `.account__hint`.
    - There is then no *Uploaden*, so no disabled state is needed.
  - **(B) Minimal.**
    - Replace the chip-in-field with one `.btn` "Foto kiezen", the same 44 height as *Uploaden*.
    - Show the chosen name as an `.account__hint` line under the row, never in it.
    - Keep *Uploaden* `disabled` until a file is picked (`.btn:disabled`).
    - Give both rows the same button width, so the columns align.

  Do the same on Android.
- **Affects:** the same FileField on Android. Also the `Profielfoto` row in Instellingen → Account, which opens this popup (config/settings.php:172-175).
- **Complexity:** S (B) / M (A) · **Risk:** with (A), an accidental pick uploads straight away. The avatar shows it at once and can be changed again.
- **Priority:** P1

### 3.4 Example 2 — Account popup: the Vrienden row
- **Status:** VERIFIED · **Kind:** usability and consistency
- **Where:**
  - Screenshots: `$A/web/pop-phone-dark/acc-main.png`, `acc-friends.png`, `acc-friends-add.png`, `$A/android/dark/app-account.png`.
  - Web: components/account-modal.php:164-176, account.css:364-412.
  - Android: AccountPanel.kt:233-240 and 379-412.
- **Problem:**
  - **Different shape from its neighbours.** `.account__nav` is THEME's Row: radius 14, `--inset-fill`, no border, at least 52 tall. Everything else in the popup is pill-shaped with a hairline: the two fields, *Opslaan*, *Uploaden* and *Uitloggen*. The one navigational element is therefore the only flat rounded rectangle, and it reads like a disabled field rather than a way onward.
  - **Repeated label.** The eyebrow "VRIENDEN" repeats the row's own text, "2 vrienden".
  - **One-off badge.** The requests badge `.account__badge` is a one-off pill: health at 42% for the border and 22% for the fill (account.css:399-412). It is not `.chip` / `.chip--accent` (Android `AccentChip`), and not the red notice dot that THEME.md assigns to "this waits for you".
  - **Hard-coded Dutch.** The count is built without `tn()`: `' vriend' : ' vrienden'`, `' verzoek'` (account-modal.php:169 and 171). This is for lens 6 / i18n. Android uses `trn` correctly.
- **Why it matters:** wayfinding. A way onward should look like every other way onward.
- **Proposal:** use the settings row pattern that Ownify already uses for "label, value, ›".
  - Drop the eyebrow.
  - The row becomes label "Vrienden" (Body), value "2" (Subhead, secondary) and the 16 px chevron, as `.settings-field` / `.settings-row` draw it.
  - Keep the Row shape: THEME says a choice to tap is a Row.
  - Waiting requests get the red notice dot (`notice_dot`, `NoticeDot.kt`) beside the label, instead of the green pill.
  - Order the panel as: identity, the two fields, Vrienden ›, Uitloggen. That puts navigation after the form and before the exit.
- **Affects:** the Vrienden page's `.account__toggle` (same Row, fine). `.friend__done` is another one-off green pill 42 tall for "Verzoek verstuurd" (account.css:512-525), a *state* drawn like a button. Use `.status` (dot and word) as THEME.md prescribes for "what is the case".
- **Complexity:** S · **Risk:** none.
- **Priority:** P2

### 3.5 Account popup: no primary action, and an undefined `.btn--ghost`
- **Status:** VERIFIED · **Kind:** defect and consistency
- **Where:**
  - Screenshots: `$A/web/pop-phone-dark/acc-main.png`, `$A/web/live/phone-dark-login.png`.
  - Web: components/account-modal.php:142, 159, 179, 207, 211, 259, 297-303, 324, 327; components/goal-progress.php:69.
- **Problem:**
  - **Undefined class.** `btn--ghost` is used six times and defined in no stylesheet (grep over assets/css: 0 rules). It renders as a plain `.btn`, so *Uitloggen* (311×44, fill .08) looks exactly like *Opslaan*, and on the verification step *Stuur opnieuw* and *Ander adres gebruiken* look like *Ik heb bevestigd*.
  - **No green primary.** Every step's one way forward is a neutral `.btn`, not the green `.btn--primary` that THEME.md prescribes for "the one action a step leads to": *Inloggen* (:259), *Account aanmaken* (:207), *Stuur link* (:324), *Ik heb bevestigd* (:297), *Wachtwoord opslaan* (:366). The Doelen empty state's *Doel aanmaken* is also neutral (`button.btn.goals-empty__cta`, 187×44, fill .08: goalwiz-2-picked.json; pages/goals.php near line 70).
  - **Uitloggen twice, two looks.** In the popup it is a full-width `.btn` without an icon (:179; Android AccountPanel.kt:243-248). In Instellingen it is a full-width `.btn` with the logout icon (pages/settings.php:69-71).
- **Why it matters:** hierarchy. On a form whose only job is to sign in, the sign-in button must be the most obvious thing (Apple: Simplicity, "the most important thing is the most obvious").
- **Proposal:**
  - Use `.btn--primary` (`BtnLook.Primary`) for every step's forward action above.
  - Delete `btn--ghost` from the markup. Secondary steps stay `.btn`; no new "ghost" style.
  - Give *Uitloggen* one look in both places (with the logout icon, or without; owner's pick).
- **Affects:** every signed-out step and goal-progress.php:69.
- **Complexity:** S · **Risk:** none.
- **Priority:** P1

### 3.6 Example 3 — The profile header (Instellingen → Account)
- **Status:** HYPOTHESIS for which element the owner means · VERIFIED for the repetition · **Kind:** usability and preference
- **Where:**
  - Screenshots: `$A/web/phone-dark/detail-settings-account-0.png`, `phone-light/…-0.png`, `small-dark/…-0.png`, `$A/android/dark|light/app-settings-account.png`.
  - Web: pages/settings-detail.php:72-88, settings.css:337-360.
  - Android: SettingsDetail.kt:152-163.
- **Problem:**
  - **Where it is.** The only header in either app that shows the photo, the username and the real name is this `.card.settings-hero`. Grep confirms that `first_name` is rendered only in settings-detail.php:86. The account popup shows no real name.
  - **What the "thick elements" probably are.** No decorative element is drawn beside it in any capture, contrast-stretched included. The most likely reading:
    - The full-width quiet card is 362×185, but its content is a 68 avatar and two centred lines, 64 px (name) and 24 px ("Dev") wide.
    - About 85% of the card's face is therefore empty on either side, two thick, featureless slabs in the card's gradient and rim around the centred content.
    - A second candidate was the pressed Profiel rows scaling to 97%, which showed the card at both ends as "light bars on White, dark ones on Dark". The web fixed that in 41.0 (settings.css:1094-1098).
  - **Repetition.** Each of its three items is repeated about 100 px lower in the Profiel group: *Profielfoto* with its own 34 avatar, *Gebruikersnaam* "dev_jij" and *Voor- en achternaam* "Dev". All three are shown twice on one screen.
- **Why it matters:** this is decoration without purpose (Apple: Simplicity; every element earns its place). It costs a full card of height, and the content sits off-balance in a box much wider than it.
- **Proposal:** two options, both with existing patterns.
  - **(A)** Drop the card and let the header stand on the ground like `.card--hero` does (components.css:98-108): avatar, name and real name centred on the page with no surface, so there are no wings.
  - **(B)** Remove the hero and use the Instellingen page's own `.settings-identity` row (avatar 48 left, name Headline, real name Subhead, left-aligned) as the first row of the Profiel group, in place of the separate Profielfoto row.

  Confirm with the owner using a screenshot of the "thick elements" from their device.
- **Affects:** Android IdentityHero, and the same.
- **Complexity:** S · **Risk:** none.
- **Priority:** P2

### 3.7 Example 4 — Privacy: the OwnifyAI block says the same thing three or four times; Account repeats itself
- **Status:** VERIFIED · **Kind:** usability (copy and structure)
- **Where:**
  - Screenshots: `$A/web/phone-dark/detail-settings-privacy-0.png`, `$A/android/dark/app-settings-privacy.png`, `$A/web/phone-dark/detail-settings-account-1.png`.
  - Config: config/settings.php:255-269 (lede 258, toggle 260-264, info note 267-268); config/settings.php:202 and 214-215.
- **Problem:**
  - **Gemini, four times.** The lede (57 px, 3 lines) says "het is gemaakt met Google Gemini. Aanzetten is toestemming om je gegevens daarvoor te gebruiken". The switch label is "Gegevens verwerken met Google Gemini". The off note is "…er gaat niets naar Gemini". Then a 147-px info Well, "Ownify gebruikt de gratis Gemini API…". The consent ("Aanzetten is toestemming") is said by the lede and by the switch itself.
  - **A usage tip in a privacy section.** "OwnifyAI open je door omhoog te vegen" is not privacy information.
  - **Account repeats one fact.** The *Lichaamsmaten* lede says "Elke wijziging wordt bewaard, zodat je het verloop terugziet". The lock note at the bottom says "Lengte en gewicht bewaren hun verloop: elke wijziging komt erbij, de vorige verdwijnt niet." This is one fact, twice, on one screen.
- **Why it matters:** repeated text dilutes the one sentence that matters, the disclosure that Google may use and read the data.
- **Proposal:** PREFERENCE. The copy is consent text, so the owner must word it.
  - **OwnifyAI block.** Replace the lede with the disclosure: "OwnifyAI is gemaakt met Google Gemini, via de gratis Gemini API: Google kan wat binnenkomt gebruiken om zijn producten te verbeteren, en medewerkers van Google kunnen het lezen." Then show the switch and its state note. Drop the separate info Well.
  - **Retention sentence.** "Je gesprekken worden… bewaard, tot je ze wist" already has its home in *OwnifyAI-gesprekken wissen*'s note.
  - **Account.** Cut the second sentence of the Account lock note.
  - **Consistency.** Keep components/ai-consent.php consistent with the new wording.
- **Affects:** the en strings in lang/en.
- **Complexity:** S · **Risk:** legal wording; owner approval.
- **Priority:** P2

### 3.8 Privacy "Later instelbaar": dimmed switches drawn on, and in the wrong place
- **Status:** VERIFIED · **Kind:** usability
- **Where:**
  - Screenshots: `$A/web/phone-dark/detail-settings-privacy-0.png` / `-1.png`, `$A/web/pop-phone-dark/acc-friends.png`.
  - Web: config/settings.php:270-277, pages/settings-detail.php:358-372, settings.css:511-522.
- **Problem:**
  - **Drawn on.** *Profiel vindbaar* and *Meedoen aan ranglijsten* are disabled switches drawn **on**: a green track with the knob right, at opacity .55, under "Deze keuzes komen er zodra er iets te delen valt".
  - **Already live elsewhere.** Being findable by username already works: friend search in the account popup's Vrienden page. Its live counterpart *Vriendverzoeken toestaan* sits in the popup, not in Privacy.
  - **Split across sections.** *Meedoen aan ranglijsten* sits two sections away from the live *Profielfoto op de ranglijst*.
- **Why it matters:** wayfinding. Related settings are split across a sheet and a page, and a disabled-on switch claims a state the user cannot change.
- **Proposal:**
  - Move *Vriendverzoeken toestaan* into Privacy next to *Profielfoto op de ranglijst*, under one eyebrow "Community". The Vrienden page can keep a link to it.
  - Remove *Profiel vindbaar* until it exists.
  - Either remove *Meedoen aan ranglijsten* or say plainly that it is always on: a `rows` value "Altijd", not a dead switch.
- **Affects:** Android TogglesBlock (same config).
- **Complexity:** S–M · **Risk:** the switch's endpoint stays the same; only where it is shown changes.
- **Priority:** P2

### 3.9 Two places for username and photo; two Uitloggen
- **Status:** VERIFIED · **Kind:** usability
- **Where:** config/settings.php:172-175: the *Profielfoto* and *Gebruikersnaam* rows `'opens' => 'account'`, so they open the popup. The popup also carries *Uitloggen*, which Instellingen has again (pages/settings.php:69).
- **Problem:**
  - The same two edits live in a settings detail (as rows) and in the popup (as a form).
  - Tapping the row opens a different surface from every other row on that page, where the other rows open the inline editor (`settings-editor.php`).
- **Proposal:**
  - Let the rows open the same field editor (`settings-editor.php`), as *Voor- en achternaam* does.
  - Make the popup a summary (identity, Vrienden ›, Uitloggen) or the reverse. That is an owner decision; pick one home per edit.
- **Complexity:** M · **Risk:** the endpoints stay.
- **Priority:** P2

### 3.10 Inputs: wheel fields drawn two ways; date fields two ways; five copies of the pill field
- **Status:** VERIFIED · **Kind:** consistency
- **Where:**
  - Screenshots: `$A/web/phone-dark/detail-entry-training-0.png` (and .json), `$A/web/pop-phone-dark/chwiz-3.png`.
  - Web: pages/manual-entry.php:83, components.css:2063-2087; goals.css:741, 1260; settings.css:949; account.css:183; challenges.css:1101-1115.
- **Problem:**
  - **Two looks on one form.** In *Training invoeren* all five fields open the same wheel (THEME.md, "The wheel"):
    - Datum, Begintijd, Duur and Calorieën are flat pill fields (`wizard__input`: background .05, border .08, no shadow, 312×44).
    - *Activiteit* is a floating Liquid Glass pill (`glass--select`: pane .62, shadow 0 8 20 .26, backdrop blur) with a chevron.

    One interaction has two looks on one form.
  - **Date drawn two ways.** The same date is a pill field here, but a glass button at radius 14 (`button.glass.goal-date`, 141×54) in both wizards. THEME.md documents only the wizard's version.
  - **Five copies of one recipe.** The pill field is written five times (`account__input`, `wizard__input`, `goal-search__input`, `field-editor__input`, `ch-input`) with the same values. One drifts: `.ch-input::placeholder` is `--text-faint`, the others `--text-muted` (challenges.css:1114).
- **Proposal:**
  - Draw every wheel-backed field as the pill field. A list field adds only the 16 px chevron-down at the right; drop the glass.
  - Decide whether a date is a field or a glass button and use that everywhere.
  - Merge the five field classes into one shared `.field` in components.css, with no new look.
- **Affects:** the wizard's unit/period selects, Instellingen' editors, and Android (`GlassSelect` vs `WheelField`, Wheel.kt; not re-checked).
- **Complexity:** M · **Risk:** the wheel's anchoring to the field.
- **Priority:** P2

### 3.11 "Vriend toevoegen": four treatments, two icons
- **Status:** VERIFIED (code; leaderboard row and popup in screenshots) · **Kind:** consistency
- **Where:**
  - A round `+` icon button (pages/community.php:78).
  - `user-plus` with text (community.php:87).
  - A board Row with a `user-plus` "avatar", "Vrienden toevoegen" (components/leaderboard-board.php:44; `$A/web/phone-dark/detail-leaderboard-0.png`).
  - A full-width `.btn` "+ Vriend toevoegen" (components/account-friends.php:38; `acc-friends.png`, which also shows this button with the double focus ring on open).
- **Problem:** one action has four shapes and two different icons (`plus` = generic add, `user-plus` = add person).
- **Proposal:** use `user-plus` for every "add a friend", and `plus` only for creating things (goal, challenge, chat, device, entry). Use one control, the full-width `.btn` with its icon, wherever it is a primary block of its own.
- **Complexity:** S · **Priority:** P2

### 3.12 Icon meaning map and conflicts
- **Status:** VERIFIED (code) · **Kind:** usability
- **Where:** components/icons.php (48 outline icons, 10 solid in `icon_solid`). On Android, OwnifyIcons.kt is generated from it by tools/gen_icons.py, with the same 48 and 10, no Material icons, and only `R.drawable.ownify_logo` besides. **Parity: complete.** One family: a 24 grid, 1.6 stroke, round caps. The solids appear only on coloured `.icon-tile--solid` tiles.

| Icon | Meanings (where) | Conflict |
|---|---|---|
| `moon` | Slaap (health, goals, setup, challenges); **Thema & uiterlijk** (config/settings.php:93, 374) | ⚠ a category icon reused for an app setting |
| `chart` | **Doelen tab** (dashboard.php:233); every Verloop / history heading (health-trend, health-history, compass-history, goal-progress, goal-detail:207, goals.php:66); setup "Gewicht" | ⚠ the tab's icon heads cards on other tabs |
| `pulse` | Slaap insights and correlations (includes/insights/render.php:29, correlations/render.php:170); heart rate (heart-chart, training-session); Scorekompas (score-compass:98); Inzichten; challenges *Actieve minuten*, *Actieve calorieën* | ⚠ four meanings; sleep is `moon` elsewhere |
| `bolt` | Activiteit goals, Stappen challenge, Energie focus, training insights | ⚠ Training is `dumbbell` elsewhere |
| `lock` | an empty state (card__hint on health, goal, score); a locked field (date of birth); a privacy note (4 settings notes); a blocked source; a locked insight badge | ⚠ "no data yet" reads as "forbidden" |
| `info` | a note (insights, research); **an error** (pull-sync alert, integration error: settings-integration.php:145); Over de app; **Voorwaarden** (settings.php:533); Hulp | ⚠ an error shown with "info" |
| `copy` | copy a challenge code; **Licenties** (settings.php:534, legal.php:223) | ⚠ |
| `arrow-up` | score up; **send message** (ai-composer); "more is better" (wizard); *Verdiepingen* challenge | ⚠ |
| `sparkle` | Aanbeveling; nutrition tile; calibration fact; **custom goal** (goal-wizard:144); insights default | ⚠ "custom" ≠ "recommendation" |
| `user` | account and avatar placeholder; **Handmatig** goal source (goals.php:562) | ⚠ |
| `heart` | Gezondheid tab; Herstel & balans goals | minor |
| `ruler` | Eenheden; Lichaam goals | minor |
| `sliders` | Instellingen tab; **Voorkeuren** row inside Instellingen; goal-detail manage tile; setup "later" | minor (tab repeated) |
| `rings` | Overzicht tab; Scorekompas tile; "Alles" focus | consistent |
| `chevron-down` | **close** (account, wizard, challenge sheet, AI sheet); **open / select** (glass-select, integration expand); pull-sync arrow | ⚠ in the wizard the close button and a select both carry chevron-down |
| `calendar` | first day of week; *Dagen met…* challenge; goal periods; late-join line | ok |
| `check` | done, chosen, confirmed, sync ok; **nutrition-rating tile** (nutrition-rating.php:18: a rating to give, not done) | minor |
| `plus` / `user-plus` | create / add a friend, mixed (3.11) | ⚠ |
| `target` | Uitdagingen | ok |
| `award` | Afgerond goals, a completed goal card | ok |
| `ranking` | Ranglijst | ok |
| `shield` | Privacy, Privacyverklaring, challenge privacy line | ok |
| `trash` | delete a goal, a training, an AI chat, the account (confirm mark) | ok |
| `device`, `bell`, `globe`, `accessibility`, `logout`, `sync`, `hash`, `share`, `search`, `close`, `eye` / `eye-off`, `pause` / `play`, `flag`, `dumbbell`, `utensils`, `community`, `chevron*`, `dash`, `arrow-down` | one meaning each | ok |
| `chat` | **unused** on both apps (no `icon('chat')` and no `OwnifyIcons.chat` use) | dead (lens 8 / 9) |

- **Proposal:**
  - Give each category's insights its own category icon (Slaap `moon`, Training `dumbbell`) and keep `pulse` for heart rate.
  - Use `sliders` for Thema & uiterlijk… no: use an existing icon that is not a category, or ask the owner. A **new icon is a NEW CONVENTION**, and `moon` → a "sun/moon" glyph needs approval.
  - Use `info` never for errors. The error notes already have attention colour; pair them with no icon, or a new alert glyph (NEW CONVENTION).
  - Use `copy` only for copying, and `info` for Licenties, as Voorwaarden has.
  - Use `chevron-down` only for opening or selecting. Close stays chevron-down only where it dismisses a sheet downwards (account, wizard, AI) and the selects inside those sheets lose the chevron. Alternatively, close becomes `close` (owner choice).
  - Use `bolt` for Activiteit only.
- **Complexity:** S per icon · **Risk:** none.
- **Priority:** P1 for moon, chart, pulse, info as error; P2 for the rest.

### 3.13 Icon sizes: 11 sizes; the same tile holds different glyph sizes
- **Status:** VERIFIED (CSS) · **Kind:** consistency (craft)
- **Where:**
  - assets/css, sizes declared on `.icon`: 11, 13, 14, 15, 16, 17, 18, 20, 22, 24 and 28 px (49 rules).
  - In a 36 tile: 18 by default (components.css:987), but 17 in the health card (health.css:51), the compass category head (compass.css:276), the calibration start (dashboard.css:59) and a completed goal card (goals.css:253).
  - In a 30 tile: 16 (settings.css:97) and 17 (devices.css:75, settings.css:580), but 15 for `ch-icon--sm` (challenges.css:439).
- **Problem:** THEME.md has two tile sizes but no glyph size per tile. Identical tiles carry 15, 16, 17 or 18 px glyphs.
- **Proposal:** two glyph sizes bound to the two tiles (36 → 18, 30 → 16) as tokens in theme.css and `Ownify.*`. This needs owner approval as a token, a **NEW CONVENTION**, small. Inline marks get a reduced set (14 inline-with-Subhead, 16 inline-with-Body, 20 standalone).
- **Complexity:** S · **Priority:** P3

### 3.14 State and size drift in buttons and badges (small)
- **Status:** VERIFIED (CSS) · **Kind:** consistency
- **Disabled opacity.** It varies: `.btn` .45, primary and caution .5, a dead switch .55, a live toggle .7, `.account__toggle` .7, `.settings-delete` .45 (components.css:550-592, settings.css:228, 522, 541; account.css:568).
- **`.btn` heights.** 44 by default, 36 in settings actions, 38 in their confirm, 42 for `.friend__done`.
- **Avatar sizes.** Five sizes: 68 (hero), 52 (popup), 48 (identity card), 40 (friend), 34 (row).
- **Glass chips.** `goal-bubble` and `goal-idea` are 25 tall (chwiz-3.json, goalwiz-2-picked.json), below `--hit-min`. That is for lens 5.
- **Proposal:** one disabled value per family (`.btn` family .45; live rows dim only while saving). All `.btn` 44. Avatars as three sizes (68 / 48 / 34).
- **Priority:** P3

### Handed to other lenses (seen in passing)
- **Lens 5, focus on open.** The username field takes focus when the account popup opens (3.3). "Vriend toevoegen" opens with a double focus ring (acc-friends.png). The wizard step heading shows a clipped focus ring ("Wat is je doel?", "Hoeveel en hoe lang?": goalwiz-2-picked.png, chwiz-3.png).
- **Lens 6 / i18n.**
  - account-modal.php:169 and 171 build "vriend(en)" and "verzoek(en)" without `tn()`.
  - The web Instellingen page shows "Geen verbonden" and "Thema: Donker", while Android shows "1 verbonden" and "Systeem" for the same account.
  - Android's account popup shows a placeholder, not the photo.
- **Lens 8 (unused code).** `btn--ghost` is never defined. `components/leaderboard.php` and `community-badges.php` appear never included. The `chat` icon is unused.

---

## Not checked / could not verify
- **The owner's example 3 element itself.** It needs the owner's screenshot (3.6 is a hypothesis).
- **Android box sizes.** The layout dumps (`app-*.json`) hold only text, no box sizes. Android button and field heights were read from code (`Btn` 44, Components.kt:525), not measured.
- **White Mode** was checked only for the account popup and the Account hero. The weight of the caution glass vs `.btn` in White, and each glass and shadow in White, were not compared.
- **Not reviewed component by component:**
  - the OwnifyAI sheet (composer, history delete, suggestion chips)
  - the challenge sheet and its cards (`ch-act`, `ch-idea`, ticket)
  - the devices popup and pairing
  - setup / first run
  - research and legal pages
  - toasts
  - the segmented control and range switch
  - the wheel popup
  - the slider
  - tooltips and readings
  - status vs chip use across Gezondheid
  - Wells, Notes and Slots on the health and insight pages (radii, borders, glow, nesting)
  - Android FriendsView
- **States.** Hover, focus and pressed were checked only from CSS. Nothing was tested on a device.
- **Not cross-checked:** `$A/web/a11y/a11y.json` touch targets, against the action table.
- **English and 320 views** for components (`en-phone-dark`, `small-dark`), except the Account detail at 320.
