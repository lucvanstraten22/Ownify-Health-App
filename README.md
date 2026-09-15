# Health App

A mobile-first health app in plain PHP, HTML, CSS and JavaScript — no
framework, no build step, no dependencies. Two screens live in one document:

```
  Gezondheid ↔ Doelen ↔ Overzicht ↔ Community ↔ Instellingen   ← horizontal
                              ↕                                  vertical
                        AI ASSISTANT
```

Horizontal moves between the five pages. Vertical pulls the assistant up over
whichever page you are on. Overzicht, Gezondheid and Community are built;
Doelen and Instellingen are placeholders, and the assistant is the room the
future ChatGPT-based assistant will live in — only its screen and gestures
exist today.

## Run it

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> — best viewed at phone width.

For accounts, import `database/schema.sql` through phpMyAdmin and check
`config/database.php`. Without a database the app still runs: signed out, on
placeholder data. See [docs/DATABASE.md](docs/DATABASE.md).

## Structure

```
index.php                     app shell: the rail, the dock, details and the sheet
database/
    schema.sql                the MySQL schema — repeatable, import and go
    seed-dev.sql              fake development data, never production
includes/                     the data layer: db, session, auth, repositories
api/                          JSON endpoints for sign-in and profile edits
pages/
    overview.php              the dashboard
    health.php                Gezondheid — three scores and a trend
    health-detail.php         one health area in full, ×3
    community.php             the leaderboard
    section.php               a page that is not built yet, ×2
    ai.php                    the assistant sheet
config/dashboard.php          copy, data and settings for the app
config/health.php             the health areas, their metrics and their trends
config/community.php          leaderboard scopes, periods and copy
lib/render.php                escaping, page/component include, score formatting
lib/health.php                demo handling, shared metrics, chart geometry
lib/community.php             board assembly, formatting, the demo roster
components/
    icons.php                 one icon family (24px grid, 1.6 stroke)
    header.php                devices · app name · account
    health-score.php          primary score ring + composition legend
    secondary-scores.php      Slaap and Voeding & Sport (one card system)
    goal-progress.php         personal goal progress
    insights.php              useful insights
    patterns.php              patterns + research placeholder
    recommendation.php        one small suggestion placeholder
    leaderboard.php           compact social layer
    scroll-top.php            floating glass control
    bottom-navigation.php     the five primary destinations (overview screen)
    app-dock.php              pull-up handle + tab bar, pinned above the rail
    ai-empty-state.php        glass orb + name + status
    ai-composer.php           reserved space for the future input interface
    health-card.php           one of the three pillars, and the control that opens it
    health-trend.php          week / month chart, one or three series
    metric-tiles.php          level 2 — the few numbers that explain a score
    metric-group.php          level 3 — the long tail, grouped
    sleep-timeline.php        the night as one bar of stages
    segmented.php             the two-or-three-way switch, shared
    leaderboard-board.php     one scope × period board
    leaderboard-row.php       position · avatar · name · points
    community-badges.php      reserved space for badges and milestones
    account-modal.php         sign-in when signed out, account when signed in
assets/css/
    theme.css                 tokens, reset, typography, screen deck
    components.css            the UI kit
    dashboard.css             overview layout, focus states, breakpoints
    ai.css                    the assistant layer (tokens only, no new values)
    health.css                Gezondheid and its detail pages (tokens only)
    community.css             the leaderboard (tokens only)
assets/js/
    dashboard.js              data attributes -> rings, meters, counters
    interactions.js           reveal, header condense, floating control
    navigation-core.js        one pointer pipeline, routed by axis
    page-navigation.js        horizontal: the five-page rail
    ai-sheet.js               vertical: the assistant sheet
    health-detail.js          drilling into a health area, and swiping back
    health-trend.js           week / month switch and the line draw-on
    community.js              scope and period switching
    account.js                the account panel
```

## Navigation

Five primary destinations, defined once in the `navigation` array of
`config/dashboard.php` — label, icon, order, destination and active state all
live there, and `components/bottom-navigation.php` only renders it.

```
Gezondheid  ──  slaap · voeding · sport, grouped into one section
Doelen      ──  persoonlijke doelen en voortgang
Overzicht   ──  het dagelijkse dashboard          ← the only one built
Community   ──  ranglijst + toekomstige sociale functies
Instellingen──  app- en gebruikersinstellingen
```

Only `overview` has a `destination`; the other four are `null`, so they render
as inert buttons rather than links to pages that do not exist yet. The
assistant layer is deliberately not a sixth item — it is reached by the swipe.

## Two gestures, two axes

`index.php` builds three things: a **rail** holding the five pages side by
side, a **dock** pinned above it, and the assistant **sheet** above that. Each
page is a viewport-sized layer with its own scroller, which is what preserves
its scroll position when you leave it — sideways or under the sheet.

`navigation-core.js` owns the pointer events, decides which axis a gesture is
on after 10px of travel, and hands it to the controller registered for that
axis. A gesture is routed once and never re-routed, so a page swipe cannot
become a sheet drag halfway through.

| Axis | Controller | Gesture | Effect |
| ---- | ---------- | ------- | ------ |
| horizontal | `page-navigation.js` | swipe left / right, anywhere on a page | previous / next of the five pages |
| vertical | `ai-sheet.js` | swipe **up from the dock** | open the assistant |
| vertical | `ai-sheet.js` | swipe **down from the sheet's header** | close it |

Both follow the finger, complete past 25% of the screen or on a flick, and
snap back otherwise. Taps and the keyboard do the same work: the tab bar
navigates, the dock handle opens, "Sluiten" and Escape close.

**Why scrolling still works.** Vertical movement belongs to the browser
everywhere (`touch-action: pan-y pinch-zoom`) except two places that opt out
with `touch-action: none`: the dock, and the sheet's header. No touch event is
ever `preventDefault`ed. So an upward drag in the page body scrolls the page
and never opens the assistant, and a downward drag in the middle of the sheet
is left free for a future conversation to scroll.

**Why you always come back where you were.** The sheet sits *above* the rail
and never touches it, so the page underneath keeps its state and scroll
position and is simply revealed again. `ai-sheet.js` also records
`AppNav.state.returnTo` on open and restores that page on close, in case
anything ever moves the rail while the sheet is up.

Offscreen pages and the closed sheet are `inert` and `aria-hidden`, in the
markup as well as at runtime, so nothing offscreen is reachable by tab,
pointer or screen reader. The rail is parked on its starting page server-side,
so it never animates into place on load and lands correctly without
JavaScript.

## Gezondheid

Overview first, detail on demand. The page itself is three scores and one
trend; everything else lives behind a card.

```
Gezondheid ──┬── Slaap      duur · timing · fasen · onderbrekingen · nachtwaarden
             ├── Voeding    macro's · hydratatie · eigen invoer
             └── Training   activiteit · trainingen · conditie en herstel
```

A detail page is a layer above the rail and below the dock, so the tab bar and
the assistant stay reachable from inside one. It opens on a tap and closes
with a rightward swipe, the back pill or Escape — safe to use that direction
because the rail stands down while a detail is in front of it.

Each detail page runs three levels deep: the score, the handful of numbers
that explain it, then the long tail in groups. Which metrics exist is entirely
`config/health.php` — adding one later is a line of config, not a template
change.

**Shared metrics.** A metric is defined once in the `metrics` registry and
referenced by key, so HRV means the same thing and looks the same in Slaap and
in Training without being defined twice.

**Missing data.** Different devices expose different measurements, so a metric
declares its `availability`. A group whose metrics all need a wearable is
marked with a lock and a line saying so — never hidden, never filled with
invented numbers.

**Seeing the design populated.** Every value ships as null. `config/health.php`
has a `demo` flag: turn it on and `health_prepare()` copies review-only numbers
into the charts and tiles so the design can be looked at with data, without a
single invented value ever reaching the shipped page. It is false by default.

## Community

Leaderboard-first: two controls and a board, nothing above it competing for
attention.

```
Vrienden | Nederland          scope  — who you are ranked against
Maand | Jaar | All-time       period — over what window
```

All six boards are rendered and one is shown, so switching is a class toggle
and each board keeps its own scroll position. Both boards cap at 50 entries.

**Always knowing where you are.** Your own row is `position: sticky` with
*both* a top and a bottom offset. It sits in its own place while it is on
screen and docks to whichever edge it would otherwise leave — floating at the
bottom while you are above it, at the top once you scroll past it. That is one
element, never a duplicate, and it needs no JavaScript. When you are outside
the top 50 your row is appended after #50 behind a "Buiten de top 50" divider,
so scrolling to the end shows it in place.

One thing worth knowing if you touch the offsets: Chromium insets the sticky
rectangle by the scroll container's own padding, so `.board__scroll`'s
bottom padding already clears the tab bar and the row's `bottom` only needs to
be the small gap on top of it.

**Points are not decided.** `points` is just a number carried on an entry,
kept out of the UI entirely, so a future scoring engine can produce the
ranking without the leaderboard changing. Scope and period are likewise plain
keys.

**Placeholder contract.** Shipped, both boards are empty and no name appears:
`config/community.php` has the same `demo` flag as health. Turn it on and
`community_prepare()` builds a deterministic roster — defined in
`lib/community.php`, belonging to nobody — so the design and the floating
behaviour can be reviewed with a full board. It is false by default.

## Accounts and data

Sign-in, profiles, health data, goals, friendships and leaderboards have a real
MySQL schema behind them — see **[docs/DATABASE.md](docs/DATABASE.md)** for the
tables, the privacy model and where the future integrations plug in.

What works today: email/password sign-in, changing a username, uploading a
profile picture. **Apple and Google are not implemented and are not faked** —
their buttons render disabled and the endpoint answers 501.

The pages still render placeholder data. The database is the foundation under
them, not yet their source.

**Privacy in one line:** every health query takes the authenticated user id as
its first argument and filters on it, no endpoint accepts a user id from the
request, and anything rendering another person goes through
`user_public_profile()`, which returns a username and an avatar and nothing
else.

## Placeholder contract

There is no database, no authentication and no device integration yet, so every
metric in `config/dashboard.php` is `null` and the UI renders an honest empty
state. Nothing on the page is an invented user measurement.

| Value in config | What the UI renders                            |
| --------------- | ---------------------------------------------- |
| `null`          | `—`, dotted ring / track, empty-state caption   |
| `int`           | number, filled ring or bar, animated count-up   |

To connect real data, replace the arrays in `config/dashboard.php` with the
output of a repository or service that returns the same shape. The markup, CSS
and JavaScript do not change: components read `has_value()` / `score_ratio()`
and the browser gets the target through `data-progress` / `data-count-to`.

## Onboarding focus

`config/dashboard.php` has a `focus` key (`general`, `sleep`, `nutrition`,
`mobility`, `mental`). `general` shows the broad overview. A specific focus
promotes that category card to full width, swaps in its extra detail rows
(sleep: duration, bedtime, wake time) and tightens the primary ring — all
through `[data-focus]` and `.is-focused` in `dashboard.css`, without a second
layout.

## The assistant layer

Intentionally empty, and honest about it. There is **no model, no API, no
conversation, no message history and no input field** — the screen is the room,
not the assistant. It introduces no colours, radii, spacing or type of its own:
`ai.css` only arranges tokens from `theme.css`, and the dotted ring around the
orb is the same "no data yet" idiom the dashboard uses.

Reserved for the next stage: `.ai-main` (where the conversation will render)
and `components/ai-composer.php` (where the input interface will go — currently
an outline and a caption, deliberately nothing that could be mistaken for a
working field).

## Not in this version

A working chatbot of any kind, ChatGPT or other API calls, AI responses,
message history, an input field, prompt suggestions, other pages, a database,
Apple Health / wearable integrations, authentication, real leaderboard data,
real medical analysis and real personal recommendations. The data layer, focus
system, screen deck and component boundaries are prepared for them; none of
them are implemented.
