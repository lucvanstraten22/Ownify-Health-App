# Health App — Home / Overview

First version of the mobile Home / Overview dashboard: a dark, Liquid-Glass
health overview built with plain PHP, HTML, CSS and JavaScript. No framework,
no build step, no dependencies.

## Run it

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> — best viewed at phone width.

## Structure

```
index.php                     page composition only
config/dashboard.php          ALL copy, data and settings (single source of truth)
lib/render.php                escaping, component include, score formatting
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
    bottom-navigation.php     fixed five-item tab bar
assets/css/
    theme.css                 tokens, reset, typography, page shell
    components.css            the UI kit
    dashboard.css             page layout, focus states, breakpoints
assets/js/
    dashboard.js              data attributes -> rings, meters, counters
    interactions.js           reveal, header condense, floating control, tabs
```

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

## Not in this version

AI chatbot, swipe interfaces, other pages, database, Apple Health / wearable
integrations, authentication, real leaderboard data, real medical analysis and
real personal recommendations. The data layer, focus system and component
boundaries are prepared for them; none of them are implemented.
