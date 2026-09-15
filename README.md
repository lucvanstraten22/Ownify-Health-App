# Health App

A mobile-first health app in plain PHP, HTML, CSS and JavaScript — no
framework, no build step, no dependencies. Two screens live in one document:

```
  HOME / OVERVIEW   ← swipe →   AI ASSISTANT
```

The dashboard is the Home / Overview screen; the assistant layer is the room
the future ChatGPT-based assistant will live in. Only the screen and the
gesture that opens it exist today.

## Run it

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> — best viewed at phone width.

## Structure

```
index.php                     app shell: holds both screens, loads assets
pages/
    overview.php              screen 1 — the dashboard
    ai.php                    screen 2 — the assistant layer
config/dashboard.php          ALL copy, data and settings (single source of truth)
lib/render.php                escaping, page/component include, score formatting
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
    bottom-navigation.php     five-item tab bar (overview screen only)
    edge-handle.php           right-edge affordance towards the assistant
    ai-empty-state.php        glass orb + name + status
    ai-composer.php           reserved space for the future input interface
assets/css/
    theme.css                 tokens, reset, typography, screen deck
    components.css            the UI kit
    dashboard.css             overview layout, focus states, breakpoints
    ai.css                    the assistant layer (tokens only, no new values)
assets/js/
    dashboard.js              data attributes -> rings, meters, counters
    interactions.js           reveal, header condense, floating control, tabs
    swipe-navigation.js       the gesture between the two screens
```

## The two screens

`index.php` renders both screens into one `.deck`. Each screen is a
viewport-sized layer with its own vertical scroller, which is what lets a whole
screen be translated as a unit — the header stays sticky, the tab bar stays
pinned, and neither jumps during the transition.

`swipe-navigation.js` keeps a single number: `progress`, 0 for the overview and
1 for the assistant. A gesture writes it straight to two transforms and one
opacity, so dragging never touches layout.

| Behaviour | How |
| --------- | --- |
| Open | swipe right-to-left, or tap the right-edge handle |
| Return | swipe left-to-right, tap "Overzicht", or press Escape |
| Follows the finger | `progress = start - dx / screenWidth`, painted on rAF |
| Completes | past 28% of the width, or a flick over 0.4 px/ms |
| Cancels | snaps back to where the gesture started |
| Never fights scrolling | `touch-action: pan-y pinch-zoom` — a vertical pan scrolls natively and cancels the gesture; no touch event is ever `preventDefault`ed |

The offscreen screen is `inert` and `aria-hidden`, in the markup as well as at
runtime, so it is unreachable by tab, pointer or screen reader.

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
