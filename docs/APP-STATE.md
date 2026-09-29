# What the Ownify app reads

The website renders every page on the server (`index.php`). The Android app
lays the same pages out natively and reads them from one endpoint. Both go
through the same pipeline, `app_page_data()` in `lib/app-data.php`, so the
app cannot show a score, a point, a percentage, a chart or a sentence the
website would not — nothing is worked out on the phone.

Signing in is described in [APP-AUTH.md](APP-AUTH.md).

## `api/app/state.php`

`POST`, `Authorization: Bearer <account token>`, no body.

| Status | When |
| --- | --- |
| 200 | `{ ok, version: 1, data }` |
| 401 | the token is unknown, revoked or lapsed, or its account is gone |
| 403 | a pairing (sync) token — it may upload, not read the account |
| 419 | no token and no CSRF token (the website's rule, below) |
| 405 | not a POST |
| 503 | no database |

Without a bearer token it answers the website's session, with its CSRF
token, like every endpoint behind `api_require_account_user()`.

`data` is what the templates read: `config/dashboard.php` filled for the
account — `app`, `header`, `overview`, `scores`, `goal` (the Overzicht goal
card), `insights`, `patterns`, `recommendation`, `leaderboard`,
`navigation`, `ai`, `disclaimers`, `disclaimer`, `auth`, `health`,
`community`, `goals`, `settings` — plus:

- `today` — `today_parts()`, the date the Overzicht header prints;
- the values the templates work out while they render, worked out by the
  same functions (`app_state_rendered()`):
  - `health.areas.*.highlights` and `health.areas.*.groups.*.metrics` —
    each metric merged with its registry definition (`health_metric`), and
    each group's `locked` (`health_group_locked`);
  - `health.trend.charts.{area}.{week|month}` — the SVG geometry of every
    trend line in the templates' 300 × 120 view box (`health_chart`), with
    `has_data`; `health.trend.width` / `height`;
  - `goals.all.*.days.*.day` / `title` — the day calendar's numbers and
    titles ("12 sep · gehaald");
  - `goals.wizard_sources` — the goal wizard's source list
    (`goal_wizard_sources`);
  - `settings.pages.*.blocks.*.fields.*.state` / `value` / `blank` /
    `input` — each profile field as the settings page shows it
    (`settings_field_*`).

Left out, because only a browser session has or needs them: the CSRF token,
the one-time message, a Google sign-in waiting for a username, and the
account's own id. An identity is its `provider` and `email`. Dates are text:
`Y-m-d` for a day, `Y-m-d H:i:s` for a moment.

Reading it has the website's side effect: each goal's progress is recomputed
and stored on the way (`hydrate_goals`), exactly as a page load does.

`version` goes up only when `data` changes in a way an older app cannot
read.

## The writes

The endpoints the pages write to take `api_require_account_user()`: the
app's account token, or the website's session and CSRF token exactly as
before. A sync token is `403`, an unknown one `401`. They take a form body
(`application/x-www-form-urlencoded`, `multipart/form-data` for the
picture), as the website sends them; `api/goals/update.php` takes JSON too.

| Page | Endpoints |
| --- | --- |
| Doelen | `goals/create`, `goals/update`, `goals/progress`, `goals/delete` |
| Voeding | `health/rating` |
| Instellingen, account | `profile/update`, `profile/onboarding`, `profile/username`, `profile/avatar`, `profile/delete` |
| Vrienden | `friends/search`, `friends/request`, `friends/settings` |
| Apparaten & Gezondheid | `integrations/pairing-code`, `integrations/device-revoke`, `integrations/disconnect` |

After a write the app reads `state.php` again, as the website reloads or
re-fetches the page.

Two answers differ for the app:

- `profile/delete` with a bearer token answers `{ redirect: null, message,
  link }`: the website's step through Google keeps its state in a browser
  session, which the app does not have, so the app shows the sentence the
  website shows when that step is not possible, with the link to do it by
  hand. The token goes with the account.
- `integrations/disconnect` of `google_health_connect` revokes every phone
  on it — the app's own account token included, since the app is listed
  under Health Connect. Its next request is `401`.

## Testing

```bash
DB_NAME=<dev database with 013> DB_USER=root php tools/app-state-test.php
```

Starts the app on PHP's built-in server and checks the read and every write
above with an account token, a sync token, an unknown token and none; that
the session and the token read the same state and the page renders the same
numbers; and deleting an account from the app, with and without Google. It
removes its accounts afterwards. Never point it at a live database.
