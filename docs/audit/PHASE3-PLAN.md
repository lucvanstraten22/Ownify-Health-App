# Ownify final audit — Phase 3: the improvement plan

This plan turns the audit (`CONSOLIDATED.md`) and the owner's decisions (`OWNER-DECISIONS.md`) into one plan, grouped by
shared pattern rather than by page. Nothing in the repository's app code, the databases or the servers was changed.

## What Phase 3 produced

- **This plan**, with the three lists at the end:
  - **(a)** fixes I would apply without asking;
  - **(b)** visual and design-system changes for approval, one by one;
  - **(c)** things I recommend leaving alone.
- **A before/after prototype of the web app.** Two scratch copies of the app ran side by side on the test account
  (scratchpad `proto3/before` and `proto3/after`, outside the repository). The approved changes were applied to the
  "after" copy only: 22 files, 45 diff hunks.
  - Real screenshots were taken of 19 scenes, before and after, in Dark and White, phone size, at rest (reduced
    motion): 76 images.
  - They are shown in the Phase 3 approval page.
  - The "after" diff is the draft of the Phase 4 web implementation, kept as `PHASE3-WEB-PROTOTYPE.diff`. Phase 4 adds
    the English strings, the Android side, tests and the remaining items.
- **The account-popup picker** (prototype skill): three directions on the app's real stylesheets, published privately
  as [Account Popup Variants](https://claude.ai/artifact/JvF48zNv71nyK2PGg5PPo9).
  - The skill's picker spec (`PICKER.md`) is not shipped in this session, so the picker follows the skill's own
    description: neutral chrome, instant switch, keyboard flip.
- **The Phase 3 approval page**, published privately as
  [Ownify Phase 3 Approvals](https://claude.ai/artifact/6CW7XvVX9SiA4Wneh6giA1), where the owner approves (b) item by item, objects to anything in (a) or (c), picks the
  popup variant and the two new icons, and exports.

The Android app is not prototyped. It follows the web, the source of truth, item for item in Phase 4, with its own
before/after screenshots.

## Interpretations to confirm (found while prototyping)

1. **"Activiteit" has two meanings.** Movement during the day becomes "Beweging", as decided: the goals category,
   challenge category and Google Health / Health Connect data categories. But in "Training invoeren" the field
   "Activiteit" is the *kind of workout* (Hardlopen, Fietsen…). There I propose **"Soort training"** instead of
   "Beweging".
2. **The score line.** "Je score bundelt slaap, voeding en beweging" names the three pillars, and the third pillar is
   called Training. I propose **"slaap, voeding en training"**.
3. **Decision 2's note vs decision 3's "never 11 px".**
   - "Vriend verwijderen" keeps its small 11 px link and "Account verwijderen" its quiet underlined link, as the
     owner's note asks. That is one exception to "never 11".
   - Their confirm steps still move to the one gold-edged Note, except the account deletion, which keeps its own
     panel because it asks for the password.
4. **Bin icon only where something is deleted.** "Uitdaging verlaten" gets the leave icon (the logout arrow), not a
   bin. "Ontkoppelen" becomes a normal button with no icon.
5. **The Overzicht arrival** (the owner's note: 2.5 s instead of 1.5 s). The whole arrival ends by about 2.5 s:
   - the ring travels about 1.8 s with a 0.7 s finish;
   - the score counts with the ring;
   - the pillar rings start together with it and are done by about 1.7 s.

   Today it takes about 5.2 s.
6. **A side effect of 15 px buttons.** "Toestaan en beginnen" no longer fits half the width in the OwnifyAI consent. The
   two buttons are stacked, "yes" on top, both full width, both equally easy to reach.
7. **A side effect of the 24 px card padding.** On Community and Uitdagingen, long challenge names now wrap to two
   lines ("Samen 415 liter water in een week"). That is the documented rule; the cost is a little height.
8. **Photo editing lives on Instellingen › Account.** The popup is a summary, so tapping your photo there does not edit
   it. On the Account page the header photo becomes the control ("Foto wijzigen" under it, using the existing quiet
   link), and the separate "Profielfoto" row goes.
9. **Phase 4 ships as one big update, 50.0.** The version moves to Beta 50.0 (Android `versionName` 50.0,
   `versionCode` 114) in the first Phase 4 commit, and every Phase 4 commit title starts with "50.0". A push that
   lands on 50.0 builds the APK. Later small updates (Phase 5 and 6) are 50.1, 50.2 and so on. The reset in Phase 7
   changes no app code, so it has no version.

## The plan, by pattern

Each item names its decision (D1–D14 in `OWNER-DECISIONS.md`) or finding (C… in `CONSOLIDATED.md`), what changes on
both apps, and its list: **(a)**, **(b)** or **(c)**. "Proto" means it is in the before/after shots.

### 1. Titles, buttons and links (D1, D3, C1, C2, N1)

| Item | Web | Android | List | Proto |
|---|---|---|---|---|
| Popup, sheet and card titles at Headline 17/600, with headline leading | `.card__eyebrow`, `.ai-consent__title` | `PanelHead`, `SheetHead`, `JStyle.Eyebrow` → Subtitle | (b) B1 | yes |
| One title per sign-in step (the panel names the step; the inner duplicate goes) | `account-modal.php`, `account.js` | `SignedOutPanel.kt` | (a) A1 | — |
| Each step's forward action is the green primary: Inloggen, Account aanmaken, Stuur link, Ik heb bevestigd, Wachtwoord opslaan, Doel aanmaken | `account-modal.php`, `goals.php` | `BtnLook.Primary` | (b) B2 | yes |
| `btn--ghost` removed (it was never defined) | markup only | — | (a) A2 | yes |
| The quiet link (N1): "Wachtwoord vergeten?" and other side paths, Subhead in secondary ink, underlined | new `.quiet-link` | existing `QuietLink` | (b) B2 | yes |
| Button text: 15 for full-width and primary, 13 beside a field, never 11 (one exception, item 3 above); the consent buttons stacked | `components.css`, `settings.css`, `ai.css` | `Btn`, `GlassCta` | (b) B3 | yes |

### 2. Destructive actions (D2, N2)

| Item | List | Proto |
|---|---|---|
| The trigger is a neutral button: bin icon for delete and wipe, leave icon for "Uitdaging verlaten", none for "Ontkoppelen"; all 44 px tall | (b) B4 | yes |
| The irreversible yes is the gold glass; the question appears in the one gold-edged Note, as the goal's confirm does today | (b) B4 | yes |
| "Je gegevens": the action labels in normal ink, not the dimmed switches' ink | (b) B4 | yes |
| Exceptions (owner's note): "Vriend verwijderen" and "Account verwijderen" keep their look | (b) B4 | yes |
| THEME.md's *cautious* row reworded to this rule (N2) | (b) B4 | — |

### 3. Account surfaces (D4, D5, C9, C10, C12, C14)

| Item | List | Proto |
|---|---|---|
| The popup becomes a summary: you (photo, username, real name), Vrienden ›, Uitloggen with its icon. Three directions are in the picker: Centered, List, Tiles. | (b) B5 | picker |
| Instellingen › Account header without its card (D5 A) | (b) B6 | yes |
| Photo: tap the header photo on the Account page to pick a new one, which uploads at once; the "Profielfoto" row goes | (b) B6 | — |
| Username: the row opens the same inline editor as every other row (not the popup) | (a) A3 | — |
| Vrienden: a normal Row with the count and, for waiting requests, the red notice dot; "Verzoek verstuurd" shown as a status (dot and word) | (b) B5 | picker |
| "2 vrienden" with proper plurals in both languages | (a) A4 | — |

### 4. Privacy and consent (D6, C13, C15)

| Item | List | Proto |
|---|---|---|
| A "Community" block: "Vriendverzoeken toestaan" (moved here; the Vrienden page links to it), "Meedoen aan ranglijsten: Altijd", "Profielfoto op de ranglijst". "Profiel vindbaar" removed. | (b) B7 | yes |
| The OwnifyAI wording, from the approved draft (Dutch below, English in Phase 4); the info block removed; the retention line moved to "OwnifyAI-gesprekken wissen"; the swipe tip leaves Privacy | (b) B7 | yes |
| Lichaamsmaten: the repeated sentence removed | (a) A5 | — |
| Server: `api/profile/privacy.php` accepts the friend-request switch, reusing the friends setting | (a) A6 | — |

The draft:

- **OwnifyAI lede:** "OwnifyAI is gemaakt met Google Gemini, via de gratis Gemini API. Google kan wat binnenkomt
  gebruiken om zijn producten te verbeteren, en medewerkers van Google kunnen het lezen."
- **"OwnifyAI-gesprekken wissen" note:** "Je gesprekken blijven in je Ownify-account bewaard tot je ze hier wist, op al
  je apparaten. Andere gegevens blijven staan."

### 5. Icons (D7, N3, N4)

Re-mapping with existing icons (both apps at once: `OwnifyIcons.kt` is generated from `icons.php`):

| Where | Today | Proposed | List |
|---|---|---|---|
| Sleep insights and sleep correlations | pulse | moon | (b) B8 |
| Training insights and correlations | bolt | dumbbell | (b) B8 |
| A category page's Verloop head | chart (grey) | the category's solid icon in its colour (moon, utensils, dumbbell) | (b) B8 |
| Gezondheid's Verloop, Gezondheidsscore's history | chart (grey) | rings (grey), as the Gezondheidsscore page head already has | (b) B8 |
| "Nuttige inzichten" card head | pulse | sparkle (the insights' own icon) | (b) B8 |
| Gezondheidsscore page, "Vergeleken met jezelf" head | pulse | rings, as the page's other heads (it is about your score) | (b) B8 |
| Licenties | copy | info, as Voorwaarden | (b) B8 |
| Every "Vriend toevoegen" | plus or user-plus | user-plus; plus only creates something | (b) B8 |
| Sheet and wizard close buttons | chevron-down | × (close); chevron-down only opens a list | (b) B8 |
| "No data yet" hints | lock | device (connect a source) or calendar (wait N days), by message | (b) B8 |
| Custom goal (wizard) | sparkle | flag | (b) B8 |
| Energie focus (setup) | bolt | stays bolt (bolt keeps Beweging and energy); owner may object | (b) B8 |
| Errors (pull-sync, a connection error) | info | the new warning icon (N3) | (b) B9 |
| Thema & uiterlijk | moon | the new theme icon (N3) | (b) B9 |
| Glyph size per tile (N4): 36 px tile → 18, 30 px tile → 16 | 15–18 mixed | tokens in theme.css and `Ownify.*` | (b) B10 |

The two new icons are drawn in three options each on the approval page: the 24 grid, 1.6 line, round ends.

### 6. Charts (D8; N5–N9, N11, N12 approved; N10 rejected)

All on both apps, written into `docs/CHARTS.md` and its tests first. No calculation changes, only drawing.

| Item | List | Proto |
|---|---|---|
| 1. Dates never collide: the area charts' side margin on every chart, plus thinning of 1-year month labels (N5) | (a) A7 | — |
| 2. Heart-rate hours: every 3 h where they fit, else 6 h, the same rule on both apps | (a) A7 | — |
| 3. The line key as a dot, named where it helps | (b) B11 | yes |
| 4. The reading: the full date, never "Vandaag", solid where it covers a control | (a) A7 | — |
| 5. An empty chart shows the same past week as its neighbours (N6) | (a) A7 | — |
| 6. A finished goal's chart covers the goal's own period | (a) A7 | — |
| 7. One dot rule, size and side margin (N7) | (a) A7 | — |
| 8. Overlapping dots nudged 2 px apart (N8) | (a) A7 | — |
| 9. A second, different measure only in the reading and head (N9) | (a) A7 | — |
| 10. Lines named at their end: **rejected (N10)**, not done | — | — |
| N11. Bars at 75 % instead of 58 % | (b) B11 | yes |
| 11. A minimum vertical range per measure (N12) | (a) A7 | — |

### 7. Navigation, focus and motion (D9, D10, C27–C37)

| Item | List |
|---|---|
| Web: each open layer adds a history step; the phone's back closes the top layer (N13) | (a) A8 |
| Android: back on another tab goes to Overzicht first | (a) A8 |
| Focus on open: the detail's heading, not the back pill (the "double ring") | (a) A9 |
| One focus ring for keyboard users, on glass too (N17); the global rule no longer changes a control's corner radius | (a) A9 |
| "Animaties uit" reaches the web's script-driven motion at once, and Android's chart draw-ons | (a) A10 |
| The Overzicht arrival at about 2.5 s, pillars with the ring (interpretation 5) | (b) B12 |
| Rail and detail settle physics; the swipe-up hint; dots before their line | (c) |

Motion changes get a final pass with `review-animations` in Phase 4. That skill can only be started by the owner.

### 8. Words (D11, C17–C21)

| Item | List |
|---|---|
| Movement during the day: "Activiteit" → "Beweging" in the goals and challenge categories and the data categories (interpretation 1: the workout-type field becomes "Soort training") | (b) B13 |
| Score line → "slaap, voeding en training" (interpretation 2); the training insight placeholder titled "Training" | (b) B13 |
| "dagscore" → "Gezondheidsscore"; the research back pill names the page it returns to | (a) A11 |
| Hard-coded Dutch through `t()` / `tn()` ("Account", "vriend(en)", "verzoek(en)") | (a) A11 |
| The challenge wizard's repeated "· Uit jullie gekoppelde bronnen" said once, above the list | (a) A11 |
| A sentence in caps → a hint in sentence case; long caps eyebrows in Licenties shortened; weekday heads at 600 | (a) A11 |

### 9. Spacing and layout (D12, C38–C44, C51–C52)

| Item | List | Proto |
|---|---|---|
| "Vandaag" on the page-title line (no jump between tabs) | (a) A12 | yes |
| Full-width cards pad 24 (Community, Uitdagingen); interpretation 7 | (a) A12 | yes |
| 12 px between cards of one kind (Uitdagingen 8 → 12) | (b) B14 | yes |
| Units small and grey after the number; % stays (D12). The server sends value and unit apart. | (b) B11 | yes |
| `.settings-rows` cards: no extra bottom padding | (a) A12 | yes |
| Doelen: intro → switch 16 px; a label above a card 8 px | (a) A12 | yes |
| A text link as a card's last element: the existing correction applied to all | (a) A12 | — |
| Small chart cards: the empty band under the axis and the second axis row collapse | (a) A12 | — |
| Device descriptions may wrap to two lines instead of being cut; the 320 px wraps fixed where they cut text | (a) A12 | — |

### 10. Empty states and feedback (C22–C24, C75)

| Item | List | Proto |
|---|---|---|
| Doelen with only ended goals: "Geen actieve doelen" · "Je afgeronde doelen staan onder Afgerond, met hun eindstand." · green "Nieuw doel instellen" (same on Community when invitations wait) | (b) B15 | yes |
| Overzicht goal card: the same truth; its "Doel instellen" opens the wizard directly | (b) B15 | — |
| A new account: one sentence about connecting a source, with a link that opens Apparaten | (b) B15 | — |
| The web confirms a saved setting with the existing toast, as Android does | (a) A13 | — |

### 11. Accessibility (C28–C33, C106, N16)

All are **(a) A14**:

- the Dark muted-text contrast guard in the theme tests (raise the token only if a real surface fails: N16);
- the focusable span gets a role;
- segmented controls as tabs or radio groups;
- the AI grip under More contrast;
- `user-select: none` on controls.

### 12. Web ↔ Android parity (C68–C74)

**(a) A15:** follow the web for:

- the 7 type differences;
- the wizard row labels;
- the Overzicht goal chevron;
- the insight tier label;
- the dashed 0 % bar.

PARITY.md is updated. The unparsed state keys are checked in Phase 6.

### 13. The evidence harness (C-E1)

**(a) A16:**

- the server URL with a trailing slash;
- real goal ids;
- a fixture with time in bed, total energy and a score backfill;
- re-capture both apps before the Phase 4 sign-off.

### 14. Later phases, as decided

- **Phase 5, performance:**
  - the score engine once per request;
  - cached lookups;
  - auto codes;
  - closed detail pages not drawn (N14);
  - indexes;
  - compression and caching (D14);
  - Android refresh at most once a minute (D14);
  - the build step (N15, below).

  Measured before and after on a throwaway database copy.
- **Phase 6, cleanup:**
  - dead endpoints, functions, CSS and JS (after a live log check for the status endpoint, D13);
  - the badges card;
  - "suggest a target";
  - template Android tests;
  - migration 032 (backup tables, Gemini leftovers, redundant indexes, `SET NAMES`);
  - `g7` brought up to date (D13).
- **Phase 7, the reset:** one phpMyAdmin script per database, tested locally first, including the backup tables and
  the challenges that outlive their members; the avatar files removed by SFTP.

## N15: what a build step involves

What happens today:

- 17 stylesheets and 33 scripts are served as written, each with a `?v=` version mark.
- The deploy uploads files over SFTP; Hestia has no shell, so nothing can be built on the server.
- WAMP mirrors `main` with robocopy on a Windows runner.

With gzip alone (D14), the web downloads about 86 KB of CSS and 137 KB of JS. Shrinking and joining the files would
bring that to about 42 KB and 99 KB, and 50 requests down to about 4.

| | |
|---|---|
| **The design** | A small build script joins and shrinks the files in the GitHub Action before the upload. The result goes to `assets/dist/`, which is not in git. `asset()` serves the joined files when they exist and the separate source files otherwise, so local development needs no build. |
| **What it needs** | Node in the deploy workflow (the CI runner has it). The Windows runner needs Node too; without it, WAMP keeps serving the source files (still correct, just not shrunk). |
| **Risks** | A new way for a deploy to fail. Debugging the shrunk files (the CI can produce maps). Making sure every page links the joined files. |
| **Recommendation** | Do compression and caching first (config only), measure in Phase 5, then add the build step with the fallback above. It is approved, so it stays in Phase 5; if the measurement shows little gain, I will say so before adding it. |

## The three lists

**(a) I would apply these without asking.** They are low risk, follow an existing rule or an approved decision, and
add no new convention.

| # | Item | Plan section |
|---|---|---|
| A1 | One title per sign-in step | 1 |
| A2 | `btn--ghost` removed | 1 |
| A3 | The username row opens the inline editor | 3 |
| A4 | Proper plurals for "2 vrienden" | 3 |
| A5 | Lichaamsmaten: the repeated sentence removed | 4 |
| A6 | The privacy endpoint accepts the friend-request switch | 4 |
| A7 | Chart items 1, 2, 4–9 and 11 (approved) | 6 |
| A8 | Back on both apps (approved) | 7 |
| A9 | Focus on open; one focus ring (approved) | 7 |
| A10 | "Animaties uit" everywhere | 7 |
| A11 | Words: "dagscore", research back pill, hard-coded Dutch, the repeated suffix, caps | 8 |
| A12 | Spacing and layout fixes | 9 |
| A13 | The settings toast on the web | 10 |
| A14 | Accessibility | 11 |
| A15 | Parity | 12 |
| A16 | The evidence harness | 13 |

**(b) Changes for approval, one by one** (before/after on the approval page):

| # | Item |
|---|---|
| B1 | Titles at 17 |
| B2 | Green primary and the quiet link |
| B3 | Button text sizes, with the stacked consent buttons |
| B4 | Destructive actions, with the exceptions and icons |
| B5 | The account popup (pick in the picker) |
| B6 | The Account page header and photo |
| B7 | The Privacy page and wording |
| B8 | Icon re-mapping |
| B9 | The two new icons |
| B10 | Icon size per tile |
| B11 | Chart key dot, stronger bars, small units |
| B12 | The Overzicht arrival at 2.5 s |
| B13 | Words for movement and the score line |
| B14 | 12 px between cards |
| B15 | Empty states copy |
| B16 | Versioning: Phase 4 as 50.0 |

**(c) I recommend leaving these alone:**

| Item | Reason |
|---|---|
| Rail and detail settle physics (C35) | Already interruptible; a feel change needs a real device. |
| The swipe-up hint repeating (C36) | A documented decision. |
| The floating "+" and grip over content mid-scroll (C45) | Normal floating glass; the end of every page is clear. |
| Five avatar sizes (C50) | Each has a role; merging them gains little. |
| Dots appearing before their line (C37) | Only visible during the 0.9 s draw. |
| The heart-rate week range and the goal-only wash (C67) | Consistent as they are. |
| The phone simulator rendering twice (C87) | Desktop is out of scope. |
| The goal vocabulary tables mirroring config (9.8) | They keep the foreign keys honest. |
