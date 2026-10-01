# Ownify

Ownify is a mobile-first health app: a website in plain PHP, HTML, CSS and
JavaScript — no framework, no build step, no dependencies — and a native
Android app (`OwnifyAndroid/`, see its README). Two screens live in one
document:

```
  Gezondheid ↔ Doelen ↔ Overzicht ↔ Community ↔ Instellingen   ← horizontal
                              ↕                                  vertical
                        AI ASSISTANT
```

Horizontal moves between the five pages. Vertical pulls the assistant up over
whichever page you are on: **Ownify AI**, a health assistant that answers from
the person's own data with Google Gemini on its free tier — see
[docs/AI.md](docs/AI.md).

## Run it

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> — best viewed at phone width.

For accounts, import `database/schema.sql` through phpMyAdmin and check
`config/database.php`. Without a database the app still runs: signed out, on
placeholder data. See [docs/DATABASE.md](docs/DATABASE.md).

To see what a machine is actually configured with — which database credentials
are in force, whether they connect, whether `OWNIFY_APP_KEY` is set and where it
came from — without printing any of it:

```bash
php tools/check-config.php
```

## Structure

```
index.php                     front door: the opening screen when signed out, else the
                              app shell — the rail, the dock, details and the sheet
database/
    schema.sql                the MySQL schema — repeatable, import and go
    seed-dev.sql              fake development data, never production
includes/                     the data layer: db, session, auth, repositories
    ai/                       Ownify AI: config and consent, the Gemini client, what goes
                              along with a question, tools, prompt, the conversation store
api/                          JSON endpoints for sign-in and profile edits
    ai/                       the assistant: state, chat, consent, action, delete
tools/
    check-config.php          what this machine is configured with; prints no secrets
    health-connect-test.sh    the whole phone-sync flow over curl, no phone needed
    hc-verify.php             that test's batch, and what each record must become
    ai-test.php               Ownify AI end to end, against a stand-in for Gemini
    ai-fake-gemini.php        that stand-in (PHP's built-in server only)
pages/
    welcome.php               the opening screen, for everyone not signed in
    overview.php              the dashboard
    health.php                Gezondheid — three scores and a trend
    health-detail.php         one health area in full, ×3
    goals.php                 Doelen — one primary goal and up to four others
    goal-detail.php           one goal in full, one per goal
    community.php             the leaderboard
    settings.php              Instellingen — categories, not settings
    settings-detail.php       one settings screen, built from its blocks
    ai.php                    the assistant sheet
config/dashboard.php          copy, data and settings for the app
config/health.php             the health areas, their metrics and their trends
config/community.php          leaderboard scopes, periods and copy
config/goals.php              goal vocabulary, copy and the example goals
config/settings.php           the settings tree, its screens and their copy
config/ai.php                 Ownify AI: model, limits, consent version — no key
config/ai-prompt.php          what the assistant is told about itself
lib/render.php                escaping, page/component include, score formatting
lib/health.php                demo handling, shared metrics, chart geometry
lib/community.php             board assembly, formatting, the demo roster
lib/goals.php                 goal expansion, dates and priority ordering
lib/settings.php              integration status, profile values, row summaries
components/
    document-head.php         the <head> the app and the opening screen share
    icons.php                 one icon family (24px grid, 1.6 stroke)
    header.php                devices · app name · account — one, shared by the five pages
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
    ai-consent.php            the question before anything goes to Gemini
    ai-empty-state.php        glass orb + name + suggestions, and the conversation
    ai-composer.php           the question field, send, today's count
    health-card.php           one of the three pillars, and the control that opens it
    health-trend.php          week / month chart, one or three series
    metric-tiles.php          level 2 — the few numbers that explain a score
    metric-group.php          level 3 — the long tail, grouped
    sleep-timeline.php        the night as one bar of stages
    segmented.php             the two-or-three-way switch, shared
    leaderboard-board.php     one scope × period board
    leaderboard-row.php       position · avatar · name · points
    community-badges.php      reserved space for badges and milestones
    goal-card.php             one goal: name · percentage · bar · target · deadline
    goal-wizard.php           the five-step create-a-goal flow
    settings-group.php        one label, one card, hairline-separated rows
    settings-row.php          icon · label · current value · chevron
    settings-field.php        a profile field: editable, locked or derived
    settings-integration.php  a health source, expandable in place
    settings-confirm.php      the two-step delete-account confirmation
    account-modal.php         sign-in when signed out, account when signed in
    devices-popup.php         what is linked, behind the header's devices button
assets/css/
    theme.css                 tokens, reset, typography, screen deck
    components.css            the UI kit
    dashboard.css             overview layout, focus states, breakpoints
    ai.css                    the assistant layer (tokens only, no new values)
    health.css                Gezondheid and its detail pages (tokens only)
    community.css             the leaderboard (tokens only)
    goals.css                 Doelen, goal details and the wizard (tokens only)
    settings.css              Instellingen and its ten screens (tokens only)
    account.css               the account panel
    devices.css               the devices quick look (tokens only)
    welcome.css               the opening screen (tokens only)
assets/js/
    dashboard.js              data attributes -> rings, meters, counters
    interactions.js           reveal, header condense, floating control
    navigation-core.js        one gesture pipeline, routed by axis
    page-navigation.js        horizontal: the five-page rail
    ai-sheet.js               vertical: the assistant sheet
    ai-chat.js                Ownify AI inside it: consent, conversation, history
    detail-layer.js           drilling into an item, and swiping back
    health-trend.js           week / month switch and the line draw-on
    community.js              scope and period switching
    goals.js                  view switching, priority, pause and delete
    goal-wizard.js            the five-step create-a-goal flow
    settings.js               choices, integrations, sign-out, deleting the account
    account.js                the account panel
    devices.js                the devices quick look, and its way to the devices screen
```

## The opening screen

Whether somebody is signed in decides which screen they get, and only the
session decides it: `index.php` asks `app_auth()` — the session, checked
against the database — before anything else.

- **Not signed in** (a first visit, a browser that signed out, an account that
  was deleted): the opening screen, `pages/welcome.php`. Nothing of the app is
  built or sent. Any address that reaches `index.php` gets it, and a page or
  component template requested on its own is sent back to the front door
  (`pages/.htaccess`, `components/.htaccess`).
- **Signed in**: the app, on Overzicht. That includes coming back after
  closing the browser or after a long time away — see *Staying signed in*.

The opening screen shows the app's name, one line under it
(`config/dashboard.php` → `welcome.subtitle`, the only place it is written),
and two buttons where the tab bar will be. **Inloggen** and **Registreren**
open the same account panel as the app's account button, with the same forms
and endpoints, each on its own flow only: logging in shows username and
password, registering adds the e-mail address, and neither offers the other.
Google stays under both. Signing in, registering, or finishing a
Google sign-in reloads the page, which the server now renders as the app.
Signing out — from the account panel or from Instellingen — reloads onto the
opening screen.

The page is sent `Cache-Control: no-store`, so no cache hands out a screen
meant for a session that has since changed. A page that may be showing the
wrong one anyway — restored from the back/forward cache, looked at again after
it was signed out in another tab, or an app whose request is refused for want
of a session (401/419) — asks `api/auth/session.php`, which answers only yes
or no, and re-renders if the answer changed.

### Staying signed in

Signing in once lasts until you sign out. The PHP session alone could not do
that: its cookie is gone when the browser closes, and the server throws the
session away after a short idle spell (24 minutes by default), and either one
used to send somebody back to the opening screen. So every sign-in — password,
registration, Google — also gets a second cookie, `ownify_login`
(`__Host-ownify_login` on https), and a row in `user_login_tokens`. When the
session is gone, `includes/persistent-login.php` puts it back from that
cookie before anything else runs, so the person lands on Overzicht as if they
had never left, and an app left open in a tab keeps working.

- The cookie is `selector.validator`, HttpOnly (no script can read it — it is
  not in localStorage or anywhere else a page can reach), Secure on https,
  SameSite=Lax, and lasts a year from the last time it was used.
- The database keeps only the SHA-256 of the validator, so a copy of the table
  signs nobody in.
- The validator is replaced every time it puts a session back. A copy that
  turns up after the real browser has moved on revokes that sign-in. Tabs that
  reopen together, and an answer lost on the way back, are allowed for, so
  none of this ever signs anybody out by mistake.
- The session gets back the form (CSRF) token it always had, so a page that
  stayed open while its session expired can still save.
- **Uitloggen** — from the account panel or from Instellingen — deletes this
  browser's row and expires the cookie; other browsers stay signed in.
  Deleting the account deletes every row it had.

An existing database needs `database/migrations/008-persistent-login.sql`
(`schema.sql` already has the table). Until it is imported, signing in works
as it always did — for as long as the session lasts — and the server log says
why; `php tools/check-config.php` reports it too.

## Navigation

Five primary destinations, defined once in the `navigation` array of
`config/dashboard.php` — label, icon, order, destination and active state all
live there, and `components/bottom-navigation.php` only renders it.

```
Gezondheid  ──  slaap · voeding · sport, grouped into one section
Doelen      ──  persoonlijke doelen en voortgang
Overzicht   ──  het dagelijkse dashboard
Community   ──  ranglijst + toekomstige sociale functies
Instellingen──  account, koppelingen, privacy en voorkeuren
```

Every entry names a `destination` and has a page of its own. The assistant
layer is deliberately not a sixth item — it is reached by the swipe.

## Two gestures, two axes

`index.php` builds three things: a **rail** holding the five pages side by
side, a **dock** pinned above it, and the assistant **sheet** above that. Each
page is a viewport-sized layer with its own scroller, which is what preserves
its scroll position when you leave it — sideways or under the sheet.

The five pages share **one header**, laid over the rail rather than inside
any page: the pages slide underneath it and the app name and both buttons
never move. Each page keeps the header's height free at its top, so content
starts where it always did and scrolls up under it; the header condenses for
whichever page is showing. Detail pages keep a header of their own and cover
the shared one.

`navigation-core.js` owns the input, decides which axis a gesture is on after
8px of travel (the clearly larger direction wins), and hands it to the
controller registered for that axis. A gesture is routed once and never
re-routed, so a page swipe cannot become a sheet drag halfway through. Touch is
read as touch events and mouse or pen as pointer events.

| Axis | Controller | Gesture | Effect |
| ---- | ---------- | ------- | ------ |
| horizontal | `page-navigation.js` | swipe left / right, anywhere on a page | previous / next of the five pages |
| horizontal | `detail-layer.js` | swipe **right, while a detail is open** | back to the page behind it |
| vertical | `ai-sheet.js` | swipe **up from the dock** | open the assistant |
| vertical | `ai-sheet.js` | swipe **down from the sheet's header** | close it |

All of them follow the finger while it is down, so a drag can be held
anywhere in between. Once it lifts, one rule (`AppNav.resolve`) decides for
every layer, and the layer always lands on a real position — one page, fully
open, fully closed, never in between:

- a flick (0.3 px/ms or faster at the lift) completes in its own direction;
- a quick swipe (under 300 ms, at least 32 px) completes, however short;
- anything slower completes past 25% of the screen;
- a drag held still before letting go goes to whichever end is nearer.

A swipe moves at most one page. Nothing waits for an animation: a finger can
catch a layer mid-way and carry on from where it is, and a tab can redirect the
rail mid-way. A gesture interrupted by the browser or the system (a cancelled
touch, switching apps, the page being frozen) returns what it was moving to
where it belongs, and a gesture whose end never arrives is abandoned the moment
the next one starts. Taps and the keyboard do the same work: the tab bar
navigates, the dock handle opens, "Sluiten" and Escape close.

**Why scrolling still works.** Vertical movement belongs to the browser
everywhere (`touch-action: pan-y pinch-zoom`) except two places that opt out
with `touch-action: none`: the dock, and the sheet's header. A touch is only
ever `preventDefault`ed once it is a gesture of ours — a swipe between pages,
or the sheet being dragged — which stops the browser from starting to scroll
halfway through it and a click from firing when it ends. So an upward drag in
the page body scrolls the page and never opens the assistant, and a downward
drag in the middle of the sheet is left free for the conversation to scroll.

**Why you always come back where you were.** The sheet sits *above* the rail
and never touches it, so the page underneath keeps its state and scroll
position and is simply revealed again.

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
because the rail stands down while a detail is in front of it. Doelen uses the
same layer and the same controller (`detail-layer.js`, opened by anything
carrying `data-detail-open`), because drilling in is the same movement on both
pages.

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

**The Health Score.** Slaap, Voeding and Training each get their own score,
0-100, over the last 90 days — rolling, not a calendar week or month — and
only once a category has 3 distinct days of data; before that the card says
how many days are still missing, and Gezondheid's intro says how many more
unlock a score. The score on Overzicht is the average of the ones that
exist, and there is no score at all rather than a `0`. Every weight and curve
is in `config/scoring.php`; the formulas are in
[docs/DATABASE.md](docs/DATABASE.md#health-score-and-points). Scores never
earn leaderboard points.

**The day's cijfer.** The Voeding page has one input: how you ate today, 1 to
10. It is the nutrition score's data for now, it replaces itself when saved
again the same day, and saving it answers with what it earned ("+40 punten —
Voeding beoordeeld") and updates the scores and the leaderboard in place.

**Seeing the design populated.** Every value ships as null. `config/health.php`
has a `demo` flag: turn it on and `health_prepare()` copies review-only numbers
into the charts and tiles so the design can be looked at with data, without a
single invented value ever reaching the shipped page. It is false by default.

## Doelen

One question, answered in one screen: **what am I working toward, and how far
am I?** One primary goal, up to four secondary ones, their progress, and when
each one ends. Everything deeper is one tap away.

```
[ Actief ] [ Behaald ]

PRIMAIR DOEL
┌──────────────────────────────────┐
│ KRACHT                           │
│ Bench press 100 kg               │
│ 72%                       100 kg │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
│ Nog 18 dagen · t/m 4 okt      ›  │
└──────────────────────────────────┘

OVERIGE DOELEN   ×4
```

**Five goals, one primary.** The limit is one number, `limits` → `active` in
`config/goals.php`; it is enforced in one place (`GOAL_MAX_ACTIVE`,
`includes/goals.php`, read from that number) and shown in two: the `+`
disables itself and the line under the board says why. A paused
goal keeps its slot; only completing or deleting one frees it. There is always
exactly one primary goal — promoting a secondary demotes the current primary in
the same move, and deleting the primary hands the flag to the next goal, so the
headline slot can never end up empty.

**Priority is an ordering, not a second kind of goal.** `goal-card.php` renders
the primary, the secondaries and the completed ones; `--primary` is more room,
a stronger surface and a three-pixel accent edge, and nothing else. That is
what lets a promotion be a class change rather than a different card.

**Not every goal is a number.** A goal carries a `type` — `value`, `habit`,
`streak` or `milestone` — and its current and target readings are *text*, so
"86 kg / 100 kg" and "19 van 30 dagen / 30 dagen" render through the same
component without the UI knowing the difference. The create flow asks a
different question at step 3 for each type rather than forcing one number
field on all of them.

**Creating a goal is five steps, not one form:** category → definition →
target → duration (week / maand / half jaar / jaar) → confirmation. It lives
outside the deck, next to the account panel, so the deck's pointer pipeline
never sees it and nothing in it can be mistaken for a swipe. Every step is in
the document from the start and switched with a class, so stepping back still
has your answers. The button to continue stays disabled until the step has an
answer — that is the whole of the validation.

**Progress is designed to arrive on its own.** Each goal names the health data
that would keep it current (`sleep`, `nutrition`, `training`, `activity`,
`body`, `manual`), and the detail page lists them under *Wat telt mee*. Goals
that no sensor can see get a once-a-day confirmation instead, deliberately
low-friction. None of it is wired: this version is the design and the shape the
data has to arrive in.

**Placeholder contract.** `config/goals.php` has the same `demo` flag as health
and community — with one difference: it ships **true**, because a goal board is
its progress and an empty one cannot be judged. The example goals belong to
nobody, the page says so above the first card, and priority, pause and delete
change them for one page view only, which is also stated on screen. Set `demo`
to false and fill `goals` when real ones arrive; the page needs no change.

**No gamification.** No badges, streak counters, XP, points, leaderboards or
challenges live here. Progress toward the thing you chose is the motivation.

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

**Friends.** Friends are managed from the account button, under **Vrienden**:
*Vriend toevoegen* looks up exactly one username when you press Zoeken (never
while you type) and shows that account's picture, name and where the two of you
stand, with a request button when one can be sent; *Vriendverzoeken* lists
requests waiting for you (Accepteren, Weigeren) and the ones you sent;
*Vriendverzoeken toestaan* switches new requests off; *Vrienden (n)* lists your
friends, each with Verwijderen, which asks first. While a request is waiting,
a small green dot sits on the account button, on every page. The Friends board is you and
your friends — accepting a request puts the friend on it, with the points they
have in that period, and removing one takes them off, both without a reload.
Everything is stored in MySQL (`friendships`, and
`user_profiles.allow_friend_requests` from
`database/migrations/011-friend-requests-setting.sql`) and changed only
through `api/friends/`, which takes who is asking from the session; a lookup
returns a username and a picture and nothing else.

**Pictures on the boards.** Every board shows each person's profile picture
beside their name, or their initial when there is none. Instellingen → Privacy
→ *Profielfoto op de ranglijst* switches your own picture off the boards:
everybody, you included, then sees your initial. It is stored in
`user_profiles.leaderboard_avatar` (`database/migrations/014-leaderboard-avatar-setting.sql`)
and saved through `api/profile/privacy.php`; until the migration is imported,
every picture shows and the switch says it cannot be saved yet.

**Points are for what you did.** A night's sleep, a rated day, a workout, a
step count, three workouts in a week — each earns points once, by the rules in
`config/points.php`, and a phone that sends the same workout three times has
done one workout. The Health Score is a separate thing and never pays out.
The month and year boards count points by when the activity happened. The
rules, and how a repeated sync is kept from paying twice, are in
[docs/DATABASE.md](docs/DATABASE.md#points). An existing database needs
`database/migrations/010-health-score-and-points.sql`; until it is imported
the scores work and nothing earns points, and `php tools/check-config.php`
says so. After importing it, `php tools/points-backfill.php` awards the points
for what was recorded before — once, however often it runs.

**Placeholder contract.** Shipped, both boards are empty and no name appears:
`config/community.php` has the same `demo` flag as health. Turn it on and
`community_prepare()` builds a deterministic roster — defined in
`lib/community.php`, belonging to nobody — so the design and the floating
behaviour can be reviewed with a full board. It is false by default.

## Instellingen

Categories, not settings. Nine rows over five groups, each opening a screen of
its own, so the overview stays a short scan.

```
[ avatar ]  Username                         >

GEZONDHEID   Apparaten & Gezondheid          >
             Geen verbonden
PRIVACY      Privacy · Gezondheidsdata privé >
APP          Meldingen · Thema · Taal ·
             Eenheden · Eerste dag · Toegankelijkheid
OVER         Over de app · Versie Beta 1.2.1

             [ Uitloggen ]
               Account verwijderen
```

**A group is a card; a setting is a row inside it.** Forty glass panels would
be noise. The current value sits *under* the label rather than beside it,
which is what lets "Apparaten & Gezondheid" and "Eerste dag van de week" keep
their full names on a 320px phone.

**One template, ten screens.** `pages/settings-detail.php` renders a screen
from a list of blocks and knows ten kinds — `identity`, `fields`, `signin`,
`integrations`, `choice`, `states`, `toggles`, `actions`, `rows`, `note`. Adding a
settings screen is config, not another file. They use the same detail layer
Gezondheid and Doelen use, so back is the same swipe everywhere.

**Three kinds of value, never mixed up.** A *fact* is how the app genuinely
behaves — the theme is dark, the interface is Dutch, measurements are metric,
health data never leaves the owner's account. *Not set* is exactly that:
nothing is connected and no profile data is entered, so the row says so rather
than showing a number. A *preference* is a choice you will make later —
selectable now so the design can be judged, with every such screen saying at
its foot that it is not yet saved.

**Profile fields carry their own behaviour.** `edit` is `true`, `'locked'` or
`'derived'`, and the row shows which without needing a legend: an editable
field gets a chevron, a field set once at onboarding gets a lock, and a
calculated one says where it comes from. Gender and date of birth are locked.
Age is derived, not editable — the schema stores a date of birth and computes
age from it, so an age you could type would be a second, contradictory fact
about the same person.

**Only what can really save is live.** Profielfoto and Gebruikersnaam open the
account panel, which has a working endpoint behind it. Every other field
carries the same affordance and is plainly disabled, rather than moving and
quietly discarding what you typed. Uitloggen really signs you out. Account
verwijderen really deletes: two confirmations — what goes, then "Weet je het
zeker?" — and then every row of the account, its picture, its paired phones
and its Google link are gone. An account that had Google also asks Google to
forget Ownify.

**Health sources expand in place.** The app has one detail layer, so a
source's settings — status, last sync, permissions, categories, connect and
disconnect — open inside its card rather than pushing a third screen onto a
stack that does not exist. `config/settings.php` has the same `demo` flag as
the rest of the app: it fills in a connected state so that design can be
reviewed, and ships false, because nothing is connected.

**The header's device button is not this.** That button is a status glance;
this is where the configuration lives. They are deliberately not the same
thing. It opens a small popup listing what is linked right now — each paired
phone by its own name, each connected source without one by the source's —
read from the same resolved integrations as this screen, so the two always
agree, or "Geen apparaten gekoppeld". Its one button, "Apparaat koppelen",
opens this screen.

## Accounts and data

Sign-in, profiles, health data, goals, friendships and leaderboards have a real
MySQL schema behind them — see **[docs/DATABASE.md](docs/DATABASE.md)** for the
tables, the privacy model and where the future integrations plug in.

What works today: email/password sign-in, Google sign-in once it is
configured, changing a username, uploading a profile picture, and Ownify AI
once its Gemini key is set ([docs/AI.md](docs/AI.md)). Failed sign-ins are
limited to 5 per 15 minutes per name and network address.

The Ownify app can sign in as an account too — its own token, never a cookie —
through `api/auth/app-login.php`, `app-register.php`, `app-google.php` and
`app-logout.php`; see **[docs/APP-AUTH.md](docs/APP-AUTH.md)**. Needs
`database/migrations/013-app-tokens.sql`.

The pages still render placeholder data. The database is the foundation under
them, not yet their source.

**Privacy in one line:** every health query takes the authenticated user id as
its first argument and filters on it, no endpoint accepts a user id from the
request, and anything rendering another person goes through
`user_public_profile()`, which returns a username and an avatar and nothing
else.

## Placeholder contract

No device integration exists yet and nothing reads health data from the
database, so every metric in `config/dashboard.php` is `null` and the UI
renders an honest empty state. Nothing on the page is an invented user
measurement.

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

**Ownify AI** lives in the sheet, on the website and in the Android app alike:
a personal health assistant that answers from the person's own sleep,
nutrition, training, activity, measurements, scores and goals, with Google
Gemini on the free tier of its API. The whole setup — the migration, where to
get a Gemini API key, where the key goes, the limits, consent and the tools —
is in **[docs/AI.md](docs/AI.md)**.

In short: both apps talk to the same endpoints (`api/ai/`), and only the
server talks to Gemini, with a key that never leaves it. Nothing is sent
before the person says yes, and they can say no again, or wipe their
conversations, in Instellingen → Privacy. Everybody gets 10 questions a day,
counted on the server. The assistant explains; it does not diagnose, and it
can prepare a goal that is only added when the person confirms it.

Screens: the consent question, *Ownify AI staat uit*, the empty state (the
orb, the name, three suggestions), the conversation, the history, and a notice
for every way it can fail — never Gemini's own text. `ai.css` only arranges
tokens from `theme.css`, as before.

## Not in this version

Live literature search and links to studies (the assistant answers research
questions from general knowledge and says so), speech or images in the
assistant, persistent goal storage, automatic goal progress, stored settings,
Apple Health / Health Connect integrations, notifications, a light theme,
English, imperial units, real medical analysis and real personal
recommendations.
The data layer, focus system, screen deck and component boundaries are
prepared for them; none of them are implemented.
