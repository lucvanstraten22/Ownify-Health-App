# Final audit — Phase 2 shared brief (every reviewer reads this first)

You are one of nine independent reviewers in the final audit of Ownify, a
Dutch/English health app with two clients: a PHP web app (the source of
truth) and a native Android app (Jetpack Compose). Your lens and its scope are
in your own task message. Stay inside it; other reviewers cover the rest.

## Hard rules (no exceptions)

- **Strictly read-only.** Do not edit, create, move or delete anything in
  the repository, except writing your own findings file in `docs/audit/`.
  No git commands that change state (no commit, checkout, stash, reset).
  No database writes of any kind (no INSERT/UPDATE/DELETE/ALTER, no logging
  in to the app — a login writes session rows). No `php` scripts that write.
  Do not start or stop servers. Do not run Gradle (it is slow and must
  never run twice at once). Reading files, grep, `git log`/`git show`,
  `mysql -uroot -e "SELECT …"` on the local test databases (`ownify_e2e`,
  `g7`) and viewing screenshots are fine.
- Never propose a new font. Prefer existing tokens/components
  (`docs/THEME.md` is the approved system). If a fix needs a genuinely new
  convention, say **NEW CONVENTION** — it will need the owner's approval.
- Desktop layouts are out of scope: at ≥ 768 px the site only shows the
  phone inside an iPhone frame (`components/phone-simulator.php`). Do not
  propose a desktop redesign; at most note a limitation.
- Do not propose changes to health calculations, data aggregation, auth,
  sync or API contracts. Suspected data problems: report them as
  **DATA-SUSPECT**, separately, without proposing a calculation change.
- Every finding needs evidence: a screenshot path (below), the exact
  page/component, and source references (`file:line`) for web and, where it
  applies, Android. Numbers come from the metrics JSON, not from eyeballing.
- Label each finding: **VERIFIED** (seen in the evidence and the code),
  **HYPOTHESIS** (likely, not confirmed — say what would confirm it), or
  **PREFERENCE** (a subjective design recommendation). Also its kind:
  objective defect / usability issue / design preference.
- Never claim to have tested something you only read about.

## Skills

Load the skills your task message names with the Skill tool
(`anthropic-skills:apple-design`, `anthropic-skills:mobile-native`,
`anthropic-skills:improve-animations`, `anthropic-skills:break-ui`,
`anthropic-skills:animation-vocabulary`, `dataviz`). `apple-design` is the
highest authority where skills disagree. `review-animations` and
`prototype` are not available to you (user-invocation only) — do not imitate
them. Skills that would write files (improve-animations' `plans/`, break-ui's
toggle and fixtures) are used **for their audit method only**: write nothing
but your findings file.

## Project context

- `CLAUDE.md` (rules: two apps kept consistent, two themes through tokens,
  NL/EN through `t()`/`tr()`, charts per `docs/CHARTS.md`, versioning).
- `docs/THEME.md` — the approved design system: one Liquid Glass, the quiet
  card, Well / Row / Note / Slot, green and gold glass actions, the pill
  field, two icon-tile sizes, the radius scale, the Randlicht ground, the
  `--fs-*` type steps (`Ownify.Fs*` on Android).
- `docs/*.md` per feature; `OwnifyAndroid/docs/PARITY.md` — where Android
  differs on purpose.
- Web: `index.php` (the shell), `pages/*.php`, `components/*.php`,
  `assets/css/*.css` (`theme.css` tokens, `components.css` shared parts),
  `assets/js/*.js`, `api/`, `includes/`, `lib/`, `config/` (copy + tuning),
  `lang/en/` (English, keyed by the Dutch).
- Android: `OwnifyAndroid/app/src/main/java/com/ownify/android/` —
  `ui/design/` (shared components: Glass, GlassControls, Components,
  Surfaces, Charts, HistoryChart, Wheel, OwnifyIcons, Motion…),
  `ui/app/` (shell, dock, header, sheets), `ui/screens/<area>/`,
  `ui/theme/OwnifyTheme.kt`, `data/` (parsing of `api/app/state.php`).

## The evidence set

`$A` = `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/audit`

Captured from the local harness with a test account (dev_jij: 120 days of
synced data, a name and a photo; dev_lisa: an empty account), at
2026-11-11, Dutch, metric, unless noted.

| Path | What |
|---|---|
| `$A/web/phone-dark/`, `$A/web/phone-light/` | 393×852 @2x. `page-<id>-<n>.png` (5 rail pages: overview, health, goals, community, settings) and `detail-<id>-<n>.png` (all 88 detail pages: health areas, area charts, training sessions, entry pages, goal, leaderboard, challenges, insights, settings-*, legal-*, research-*), `<n>` = scroll step (0 = top, each next ≈ 85 % of a screen further). `_index.json` lists all. |
| `$A/web/small-dark/` | 320×640, 20 key views |
| `$A/web/en-phone-dark/` | English + imperial, 17 key views |
| `$A/web/pop-phone-dark/`, `pop-phone-light/` | `out-welcome`, `out-login`, `out-register` (local: no mail/Google configured), `acc-main`, `acc-photo-picked`, `acc-photo-uploaded`, `acc-main-withphoto`, `acc-friends`, `acc-friends-add`, `devices`, `devices-pairing`, `ai-open`, `goalwiz-1`, `goalwiz-1-picked`, `goalwiz-2`, `goalwiz-2-picked`, `chwiz-1…3`, `chwiz-3-calendar`, `chwiz-review`, `chwiz-invite`, `ch-code`, `wheel-clock`, `empty-<page>` and `empty-detail-<id>` (dev_lisa) |
| `$A/web/live/` | the LIVE site signed out (reference for auth screens): `phone|desktop`-`dark|light`-`welcome|login|forgot|register` |
| `$A/web/a11y/` | `a11y.json` (`targets`: every interactive element per view with size and whether it has an accessible name; `focus`: keyboard order on Overzicht with outline/box-shadow), `focus-NN.png` crops, `reduced-motion-*.png`, `more-contrast-*.png` |
| `$A/web/desktop-*`, `tablet-dark`, `wide-dark` | the iPhone frame only (out of scope) |
| `$A/android/dark/`, `$A/android/light/` | 111 Android screens each, `app-<name>.png` + `app-<name>.json` (layout dump in dp), 412×915 dp @2.625, rendered with Robolectric native graphics from the same account's `api/app/state.php` (`$A/android/state-dev_jij.json`). Rendering is the real Compose UI but not a real device (fonts/system bars may differ slightly). |

Web metrics JSON per screenshot: `text[]` = every visible element with own
text: `el` (tag.classes), `t` (text), `fs` font-size, `fw` weight, `lh` line
height, `ls` letter-spacing, `c` colour, `tt` text-transform, `x y w h`
(CSS px, viewport). `blocks[]` = every visible element whose class matches
card|glass|well|note|slot|tile|row|sheet|panel|dialog|btn|cta|chip|field|input:
`el`, box, `pad`, `m` margin, `rad`, `bg`, `bd` border, `sh` shadow, `bf`
backdrop-filter, `gap`. Use these to measure gaps (next block's `y` − this
block's `y + h`) and to compare equivalent elements across screens.

Early observations from Phase 1 (verify, then widen): `docs/FINAL-AUDIT.md`
section "Early observations".

## Your output

Write `docs/audit/lens-<N>-<name>.md`:

1. A short summary (5–10 lines): the most important problems in your lens.
2. Findings, most important first, each:

```
### <N>.<k> Title
- Status: VERIFIED | HYPOTHESIS | PREFERENCE · Kind: defect | usability | preference
- Where: screen(s) + evidence paths · web file:line · Android file:line
- Problem: what is wrong, with measured numbers
- Why it matters
- Proposal: concrete fix with existing tokens/components (or NEW CONVENTION)
- Affects: every other place with the same pattern (be exhaustive)
- Complexity: S | M | L · Risk: what could break
- Priority: P1 high impact | P2 important polish | P3 minor
```

3. A section "Not checked / could not verify", and what would be needed.

Be thorough and critical; small things (a few px, a confusing label) count.
Do not repeat existing functionality as an idea; this is an audit.
Your final message back: the path of your file and a 10-line summary.
