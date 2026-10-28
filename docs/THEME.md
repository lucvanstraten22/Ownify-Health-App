# Dark Mode and White Mode

Ownify has two themes. **Dark Mode** is the original design. **White Mode**
is the same design in a light environment: the same layout, components,
glass, accents, type, spacing, motion and interactions — only the colours and
the light on the glass change. Nothing is redesigned per theme.

Which one shows is set in Instellingen → Thema & uiterlijk, on the website and
in the Android app alike, and takes effect at once:

| Choice | Device light | Device dark |
| --- | --- | --- |
| **Systeem** — the default | White | Dark |
| Donker | Dark | Dark |
| Licht | White | White |

On Systeem the app follows the device, also when the device changes while it
is open. Only a choice made there is ever stored — never what the device
happened to show — so a device that never chose keeps following its own, and
a choice of Donker or Licht stays whatever the device does, until it is
changed. Donker and Licht kept before Systeem existed stay as they were.

## Where it lives

| | Website | Android app |
| --- | --- | --- |
| Tokens | `assets/css/theme.css`: `:root` is Dark, `:root[data-theme="light"]` (at the end of the file) is White | `ui/theme/OwnifyTheme.kt`: `OwnifyPalette.Dark` and `OwnifyPalette.Light`, read through `Ownify` |
| Which one | `<html data-theme="dark\|light">`, written by the server (`lib/theme.php`); on Systeem the first script in `<head>` (`components/document-head.php`) puts the device's in place before anything is painted (`prefers-color-scheme`) and follows it | `Ownify.palette`, Compose state: everything drawn with it follows a change; on Systeem `OwnifyThemeStore.restore()` reads the phone's night mode, and Android starts the activity again when it changes |
| Choosing | `useTheme()` in `assets/js/settings.js` | `ChoiceBlock` in `SettingsDetail.kt` → `OwnifyThemeStore.choose()` |
| Kept | the cookie `ownify_theme`: `system`, `dark` or `light`; none is Systeem | `shared_prefs/ownify_theme.xml` (`OwnifyThemeStore`): the same three words; none is Systeem |
| The browser's / system's own parts | `theme-color`, `color-scheme`, the iPhone status-bar style (`components/document-head.php`) | the window theme (`Theme.Ownify` / `Theme.Ownify.Light`, and `Theme.Ownify.System`, which follows the phone through `values-night`, before the first frame), the system bars, the splash, the date picker (`MainActivity`, `OwnifyThemeStore`) |

## Roles

A component never writes a white, a black or the ground's grey of its own.
It names what the colour is *for*, and the theme decides what that is. In
Dark Mode every role is exactly the literal it replaced, which is why Dark
Mode is pixel-for-pixel what it was before White Mode existed.

| Role | For | Website | Android | Dark | White |
| --- | --- | --- | --- | --- | --- |
| ink | marks in the text colour: tracks, grips, dots, dashes, grid lines, underlines, focus rings | `rgba(var(--ink), a)` | `Ownify.ink(a)` | white | `#1D1A1C` |
| fill | wells and controls inside a surface: rows, chips, fields, tiles, a meter's track, a switch | `rgba(var(--fill), calc(a * var(--fill-k)))` | `Ownify.fill(a)` | white | `rgb(36 28 32)` |
| lift | a faint panel on the page itself, not in a surface: the board's rows, a framed note | `rgba(var(--lift), calc(a * var(--lift-k)))` | `Ownify.lift(a)` | white × 1 | white × 16 |
| glint | white light on glass: lit edges, reflections, sheens, the orb | `rgba(255, 255, 255, calc(a * var(--glint-k)))` | `Ownify.glint(a)` | × 1 | × 3 |
| shade | shadows | `rgba(var(--shade), calc(a * var(--shade-k)))` | `Ownify.shade(a)` | black × 1 | `rgb(62 46 54)` × 0.38 |
| scrim | what dims the page behind a panel | `rgba(var(--scrim), calc(a * var(--scrim-k)))` | `Ownify.scrim(a)` | black × 1 | `rgb(38 30 34)` × 0.5 |
| ground | the page's own colour as a veil: the scrolled header, the round scroll-to-top | `rgba(var(--ground), a)` | `Ownify.ground(a)` | `rgb(48 45 47)` | `rgb(251 250 250)` |
| pane-body | the floating glass's tint under its sheen: tab bar, header buttons, opening-screen buttons | `rgba(var(--pane-body), a)` | `Ownify.paneBody(a)` | `rgb(30 28 29)` | `rgb(214 210 212)` |
| tint | text in an accent: chips, connected statuses, a board monogram | `color-mix(in srgb, var(--accent) calc(100% - b% * var(--tint-keep)), var(--tint-base))` | `Ownify.tint(accent, share)` | the accent lightened with white | the accent deepened with the text colour, by 40 % as much |

The few surfaces that are near-solid rather than glass have tokens of their
own: `--tip-surface` (a value over a bar), `--tip-surface-solid` (the chart's
reading), `--board-you-surface` (your row pinned to the board) and
`--switch-knob`. White text on a solid accent tile, a switch's knob while on
and the Google logo stay what they are in both themes.

## What changes, and what does not

**Changes:** the ground (`--bg-*`: a blush off-white instead of the deep ink,
under the same lights at about half strength), the glass tokens
(`--glass*`, `--surface-gradient*`, `--pane-rim`, `--shadow-*`, the pane's
saturate and brightness), the text tiers, the roles above, and three accents.

**Unchanged in both themes:** `--sleep`, `--nutrition`, `--training` and
their `-light` shades, `--score-high`, `--health`, `--neutral`, `--miss`, the
backdrop washes. A category keeps its colour; a score band keeps its meaning,
its thresholds and its dot.

**Three accents have a White Mode value.** As they are, they fall below 3:1
on white, so each is the same hue, deeper — the smallest change that reads:

| Token | Dark | White | Why |
| --- | --- | --- | --- |
| `--score-mid` | `#AECA0F` | `#7D910B` | 1.9:1 on white → 3.5:1 (a graphic: the score dot) |
| `--score-low` | `#C99A45` | `#AC8032` | 2.6:1 on white → 3.6:1 (a graphic) |
| `--attention` | `#BFA863` | `#7A6731` | 2.3:1 on white → 5.5:1; it is also small text (a warning, an error note), so 4.5:1 on every light ground. Its washes and borders keep the original gold |
| `--error` | `#FF8B8B` | `#B03C35` | the wizard's, the editor's and the rating's red: 2.3:1 on white → 5.8:1 on a card |

**The Gezondheidsscore ring on Overzicht and the Scorekompas** (since 18.1, `assets/js/hero-ring.js`
and `HeroRing.kt`) is painted from `--ring-from`, `--ring-mid`, `--ring-peak`
(faint → the green → light at 100), `--ring-head` and `--ring-tail` (its
arrival and the light at its tip), all mixes of `--health`. On Dark it runs
from dim to bright; on White from pale to its deepest green, so its end
always stands out most from the ground. `OwnifyTheme.kt` mirrors them as
`Ownify.RingFrom` … (`lerp` is the same OKLab mix).

## The ground: Randlicht (25.0, Horizon 28.1)

Behind every page (`.app__backdrop`, Android's `Ground.App`) is a deep ink —
`--bg-main` #28222C at the top (a plum), `--bg-mid` #1E1C1E in the middle
where you read, `--bg-deep` #251D1A at the foot (an ember); 28.1 made these
.05 lighter in OKLab with the same tint (they were #1C1620, #131113,
#19120F) — lit from beyond the screen's edges by the three categories
(`--backdrop-lights`):

- Slaap's indigo and a plum just above the top edge, Training's coral and an
  ember just below the foot, each at .45 of its 25.0 strength, so the violet
  stays in the top band and the warmth in the foot;
- Voeding's green twice, at 25.0's full strength (.187): high on the right
  (28 % down) and low on the left (74 % down), each wide (86 % × 64 %) and
  centred far beyond the edge, so it reads as light coming in from the side,
  never as a glow with a centre.

Each light fades over its whole radius on a smootherstep curve in nine stops,
so it has no rim, and its colour is mixed 35 % toward grey so the cards keep
the colour. White Mode: #F6F1F6 → #FAF8F8 → #F7EFEA with the same lights
× .85 (28.2; .52 before). A fine grain lies over it on the website against banding. White
Mode's `--text-secondary` (.75), `--attention` and `--error` are one step
deeper than Dark's, to stay at least as legible.

**Slaap, Voeding and Training (28.1, Twee randen)** have their own ground
(`.detail--area`, `Ground.areaDetail(accent)`): the area's accent as a wide
wash from the top centre (16 %) and the ground's green low and even at both
middle edges (.07, White .06; the accent 22 % in White), on the same fade. Every other detail page
keeps `.detail` / `Ground.detail(accent)`: the accent from the top right.

## The surfaces (25.0)

Since 25.0 Ownify has one glass and a small fixed set of shapes. Both apps
draw them from the same tokens; a new component picks one of these, never a
recipe of its own.

**One glass — Liquid Glass** (`--lg-*` in `theme.css`, `Panes` and
`Modifier.liquidGlass(…)` in `Surfaces.kt`). Everything that floats is this
glass: the tab bar, the header's buttons, capsules and round buttons,
selectable chips, the segmented control, panels, sheets, toasts and tooltips.
The same 177° face over the pane's tint, the same rim (`--pane-rim`), the same
blur, saturate and brightness (`--lg-filter`). Two thicknesses only:

| Thickness | Token | For |
| --- | --- | --- |
| control | `--lg-tint` .62 (`Ownify.LgTint`) | the tab bar, buttons, capsules, chips, the segmented track |
| thick | `--lg-tint-thick` .84 (`Ownify.LgTintThick`) | panels, sheets, toasts, tooltips — where text sits on it |

The chosen piece inside the glass — the tab bar's lozenge, a segment — is
`--lg-chosen` / `--lg-chosen-edge` (`drawChosenGlass`). White Mode follows
through `--glint-k`, `--pane-body` and `--shade`; nothing is written twice.

**Actions.** The secondary button in a card stays what it was (`.btn`, `Btn`
with the default `BtnLook`: `--glass` with the glass border, a pill). What
floats or leads is the glass, in three looks:

| Look | Website | Android | For |
| --- | --- | --- | --- |
| glass | `.glass`, `.pill`, `.ch-act` | `Modifier.glass(…)`, `Pill` | back and close, round buttons, the actions of Uitdagingen |
| primary | `.btn--primary`, `.glass--cta` (`--lg-green`, `--lg-green-rim`) | `BtnLook.Primary`, `GlassCta` | the one action a step leads to: Volgende, Opslaan, Doel aanmaken, Akkoord |
| cautious | `.btn--caution` (`--lg-gold`, `--lg-gold-rim`) | `BtnLook.Caution` (`Final`) | deleting, leaving, ending — every destructive step, the last one included |

The send button of OwnifyAI stays solid green: it is the composer's own mark.
Controls are 44 tall (`--hit-min`); round ones 44, the small round one 34.

**The card** is the quiet one (`.card`, `CardStyle.Default`): a faint
gradient, an inset light line and a soft shadow, at `--radius-card` 28. There
is no other card.

**Inside a card, four shapes** — all on `--inset-fill`:

| Shape | Radius | Edge | For |
| --- | --- | --- | --- |
| Well | `--radius-md` 20 | a hairline | a block: a legend, a note of settings, a metric tile (`well()`) |
| Row | `--radius-sm` 14 | none; a chosen one an inset ring | a list row, a choice to tap, at least `--row-min` 52 tall (`insetRow()`) |
| Note | `--radius-md` 20 | wash and edge of one tone: health, attention or the category | a message (`note(tone)`) |
| Slot | `--radius-md` 20 | dashed `--slot-edge` | an empty place: an invitation, an idea, a ticket |

**Fields** are all the pill of "Zoek een doel": `--radius-pill`, 44 tall, the
faint fill and hairline, and on focus the health edge with its halo
(`JInput`). A date button in the wizard is a glass button, not a field, and
keeps `--radius-sm`.

**Chips**: a label chip is a 24 high pill (`.chip`, `Chip`); a chip to choose
is the glass chip (`.glass--chip`). A status bubble (27.0, Voeding's
verdicts) is the same glass chip, never pressed: the chosen green glass
inside a range, the gold glass with `--attention` text above it, plain
otherwise.

**Research evidence** (29.0, docs/RESEARCH.md) is made of what is here, no
new style: the label (Zeer betrouwbaar, Betrouwbaar, Beperkt onderbouwd) is
the plain glass chip — tappable beside an insight, a status in its detail —
and "Bekijk onderzoek" the quiet link (`.btn--link`, Android
`LinkButton`), its text 16 from the chip: its padding is pulled back on the
left instead of the right, so that wrapped under the chip on a narrow phone
its text starts on the chip's edge. The detail is Instellingen'
page: the page intro, eyebrows, the settings card's rows and notes, `.btn`
(the green glass for Lees de pdf); a PDF read inside it sits in a Well. The
paper of a PDF's pages is the document's own white, in either theme — it is
the publication, not Ownify's surface.

**The slider** (27.0, Voeding's cijfer): the pill's rail (8 tall,
`--fill` at .08, a hairline), `--health-edge` → `--health` up to a 28 round
glass thumb (`.glass`), on a 44 tall track. The only slider.

**Icon tiles**, two sizes: 36 at radius 12 and 30 at radius 10
(`Ownify.TileRadius`, `TileSmall`, `TileRadiusSmall`). A challenge's 48
artwork is an illustration, not a tile.

**Radii**: 28 (a card), 20 (a well, a note, a slot, a small card), 14 (a row,
a date button, a tooltip on a chart), pill. `--radius-inner` is gone.

**The red dot** (26.0, docs/NOTICES.md): `--notice` (`Ownify.Notice`), flat,
cut out of a tile, a picture or a round button by a ring of the ground, or
beside a bare line icon with a soft halo of its own colour. The only red that
means "this waits for you"; `--error` stays the colour of a failed entry.

## Contrast

White Mode, the worst of a card, a well inside a card and the ground
(`tools/theme-test.php` and `ColourSystemTest` compute these from the tokens):

| | White | Dark, for comparison |
| --- | --- | --- |
| `--text-primary` | 14.8:1 | 7.7:1 |
| `--text-secondary` | 6.8:1 | 5.4:1 |
| `--text-muted` | 5.2:1 | 4.2:1 |
| `--attention` | 4.5:1 | 3.6:1 |
| `--error`, on a card | 4.95:1 | 4.27:1 |
| graphics on a card: score high / mid / low, the green, `--text-faint` | 3.2 / 3.5 / 3.5 / 3.3 / 3.1:1 | |

Every text tier reaches WCAG AA, and none is lower than Dark Mode gives it.

**More contrast.** When the device asks for it — `prefers-contrast: more` in
the browser; on Android a raised contrast level (Android 14+) or "High
contrast text" (`ui/theme/Contrast.kt`) — the second and third text tiers and
the glass edges step up in both themes (`--text-secondary` .86, `--text-muted`
.76 / .78 in White, `--text-faint` .60 / .62, borders and hairlines about
double). The values are one block at the end of theme.css and
`OwnifyPalette.more`; `TokenParityTest` compares them. **Reduced
transparency** (`prefers-reduced-transparency: reduce`, web only — Android
has no such setting) makes the floating glass near-solid (`--pane-solid`)
and drops the panels' frost (`--panel-frost`, `--panel-frost-closed`), and
makes the header's veil solid at its top (`--veil-top`, `--veil-mid`; a
detail's bar with its title in it: `--veil-deep-top`, `--veil-deep-mid`).

**Toegankelijkheid on the account** (19.0, migration 024). Instellingen ›
Voorkeuren has three switches that hold on every device, on top of what the
device says — never instead of it:

| Switch | Website | Android |
| --- | --- | --- |
| Grotere tekst | `:root[data-text="large"]`: `font-size: 112.5%`, so every rem grows | `AccountTextSize`: the phone's text size × 1.125 |
| Extra contrast | `:root[data-contrast="more"]`: the more-contrast block above, as if the device asked | `Ownify.useMoreContrast(phone ∨ account)` |
| Animaties uit | `:root[data-motion="reduce"]`: every `prefers-reduced-motion` rule's twin; and `matchMedia('(prefers-reduced-motion: reduce)')` answers yes, so the scripts hear it too | `LocalReducedMotion` = phone ∨ account |

The attributes are put on `<html>` before anything is painted
(`components/document-head.php`), and switched in place when a switch moves
(`window.ownifyAccess.apply`). A stylesheet that adds a
`prefers-reduced-motion` or `prefers-contrast` block adds its
`[data-motion]` / `[data-contrast]` twin beside it.

**Status.** What is the case — Voorkeuren's Nu actief, a source's
connection, the header's quick look — is drawn as `.status` (components.css;
Android `StatusBadge`): a 6 px dot and a word in the secondary ink, no border
and no fill, so it is never taken for a button or a choice. The colour is
only in the dot: `--health` with its halo for on or connected, `--attention`
when something needs a look, `--text-faint` otherwise.

## Kept on the device

The theme is a choice of a device, like a phone's own appearance setting, and
not of an account: the opening screen — where nobody is signed in — comes up
in it, and signing in or out changes nothing about it. The website and the
app each remember their own.

- **Website.** `settings.js` writes `ownify_theme=light|dark` (path `/`,
  `SameSite=Lax`, `Secure` on HTTPS, a year) and switches the page in place.
  Every page then sends the cookie back from the server for another year —
  Safari keeps a cookie a script set for seven days at most. The server reads
  it before it writes a byte, so a page arrives in its theme and is never
  painted in the other one first. Anything but `light` is Dark.
- **Android.** `OwnifyThemeStore` keeps one word in
  `shared_prefs/ownify_theme.xml`. `MainActivity` reads it before its first
  frame and sets the window theme, the palette and the system bars (dark
  icons in White Mode); a change in Instellingen redraws everything at once,
  and the bars, the window behind the app and — on Android 13 and later — the
  next start's splash follow it.

## Adding something

- Use a token or a role. Never write `rgba(255, 255, 255, …)`, `#000` or the
  ground's greys in a component: `tools/theme-test.php` fails on it.
- A new token goes in both blocks of `theme.css` when it differs, and in both
  `OwnifyPalette`s; `ColourSystemTest` compares them token for token.
- Look at it in both themes before shipping.

## Tests

- `php tools/theme-test.php` — the cookie, the head, the settings, the
  tokens, contrast, and the front door served in each theme.
- `ColourSystemTest` — both palettes against `theme.css`, the roles, the
  unchanged categories and score bands, contrast.
- `ThemeTest`, `ThemeSwitchTest` — Systeem following the phone (nothing
  stored), Donker and Licht staying whatever the phone does, the stored choice
  and opening in it, the bars and the window following a switch, the Health
  Connect rationale.
- `OwnifyAppFlowTest` — Licht in Instellingen, kept through signing out, back
  to Donker, and Systeem following the phone again. `AppDataParseTest` — the
  theme choice from the server.

## Limits

- Android 12 and older draw the system's starting window before the app runs,
  from the manifest's theme, which follows the phone (`Theme.Ownify.System`):
  with Donker or Licht chosen against the phone's appearance, a cold start can
  show the other ground for a moment. From Android 13 the splash follows the
  choice.
- The keyboard and the system's share and permission screens are the
  phone's own and follow the phone's theme, not Ownify's.
