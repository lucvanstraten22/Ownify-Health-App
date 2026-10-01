# Dark Mode and White Mode

Ownify has two themes. **Dark Mode** is the original design and the default.
**White Mode** is the same design in a light environment: the same layout,
components, glass, accents, type, spacing, motion and interactions — only the
colours and the light on the glass change. Nothing is redesigned per theme.

It is chosen in Instellingen → Thema & uiterlijk (Donker / Licht), on the
website and in the Android app alike, and takes effect at once.

## Where it lives

| | Website | Android app |
| --- | --- | --- |
| Tokens | `assets/css/theme.css`: `:root` is Dark, `:root[data-theme="light"]` (at the end of the file) is White | `ui/theme/OwnifyTheme.kt`: `OwnifyPalette.Dark` and `OwnifyPalette.Light`, read through `Ownify` |
| Which one | `<html data-theme="dark\|light">`, written by the server (`lib/theme.php`) | `Ownify.palette`, Compose state: everything drawn with it follows a change |
| Choosing | `useTheme()` in `assets/js/settings.js` | `ChoiceBlock` in `SettingsDetail.kt` → `OwnifyThemeStore.choose()` |
| Kept | the cookie `ownify_theme` | `shared_prefs/ownify_theme.xml` (`OwnifyThemeStore`) |
| The browser's / system's own parts | `theme-color`, `color-scheme`, the iPhone status-bar style (`components/document-head.php`) | the window theme (`Theme.Ownify` / `Theme.Ownify.Light` in `res/values/themes.xml`), the system bars, the splash, the date picker (`MainActivity`, `OwnifyThemeStore`) |

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

**Changes:** the ground (`--bg-*`: warm off-white, still lightest at the top
and still under the same green, gold and olive washes), the glass tokens
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
| `--attention` | `#BFA863` | `#7D6A33` | 2.3:1 on white → 5.3:1; it is also small text (a warning, an error note), so 4.5:1 on every light ground. Its washes and borders keep the original gold |
| `--error` | `#FF8B8B` | `#C2453E` | the wizard's, the editor's and the rating's red: 2.3:1 on white → 5.0:1 (4.95:1 on a card) |

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
- `ThemeTest`, `ThemeSwitchTest` — the stored choice, opening in it, the
  bars and the window following a switch, the Health Connect rationale.
- `OwnifyAppFlowTest` — Licht in Instellingen, kept through signing out, and
  back to Donker. `AppDataParseTest` — the theme choice from the server.

## Limits

- Android 12 and older draw the system's starting window from the manifest's
  theme before the app runs, so a cold start in White Mode can show the dark
  ground for a moment. From Android 13 the splash follows the choice.
- The keyboard and the system's share and permission screens are the
  phone's own and follow the phone's theme, not Ownify's.
