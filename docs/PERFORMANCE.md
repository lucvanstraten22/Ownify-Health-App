# Performance

How a signed-in page and the app's state are built without asking the
database the same thing hundreds of times, how they travel, and what was
measured (50.0, Phase 5 of the final audit). Nothing here changes what a page
or the app shows: the measured answers before and after are identical, value
for value.

## The render's memo

Building one page (`app_page_data()`, lib/app-data.php) — the website's and
the app's state alike (api/app/state.php) — asked the same questions over
and over: a metric's day totals 94 times for one account (the score's week,
the goals' month, the charts' quarter and year, today), the account 40 times,
the score as it stands three times over (Overzicht, the trend, the
Scorekompas history), every research claim and source one by one.

`db_memo_scope()` (includes/db.php) runs the build with a memo on.
Inside it, `db_memo($key, $read)` reads once per key; outside it — every
endpoint that writes, every test, the crons — it simply reads, as before.
When the outermost scope ends the memo is gone, so nothing outlives the
request.

What may be remembered is what the build itself never writes:

| Remembered | Key | Why it holds |
| --- | --- | --- |
| a metric's day totals | `health_metric_totals\|user\|code` | the build writes no readings. A range asks only for the days no earlier range of that metric has read (`health_totals_gaps()`); a day's total does not depend on the range it was read in |
| the account | `user_account\|id` | the build writes only `last_seen_at`, which is not in it |
| the score now | `health_score_now\|user` (`health_score_now_shared()`) | worked out from the readings and the profile, which the build does not change; the trend and the Scorekompas history start from it instead of working it out again |
| friends' ids | `friend_ids\|user` | friendships are not written while a page is built |
| research claims and sources | loaded whole, once | fixed reference rows |

What is **not** remembered: goals and their progress, insights, the scores
kept per day — the build writes those on a first render of the day, and a
later read must see the write. Anything new that reads inside the build goes
through `db_memo()` only when the build cannot change the answer.

Outside the memo, for any request: the metric catalogue (`health_metric_types`,
a dozen fixed rows) is read once per process (`health_metric_type()`,
includes/health-data.php), and a chart's first day is
`MIN(recorded_at)` on the metric's own index rather than a join on its code.

## How the state travels

`api_json()` (api/bootstrap.php) gzips an answer of 16 KB or more for a
client that asks for it (`Accept-Encoding: gzip`, not `q=0`), unless the
server already compresses (`zlib.output_compression`); it then says
`Content-Encoding: gzip` and `Vary: Accept-Encoding`. Android's
HttpURLConnection asks for gzip and unpacks it by itself (OwnifyApi.kt sets
no Accept-Encoding of its own, and its 4 MB limit counts the unpacked
bytes); a browser's fetch does the same. A client that does not ask gets the
plain JSON. A web server in front that compresses as well leaves an answer
that is already encoded alone.

## In the browser

- interactions.js `dockGutter`: the scroll gutter is written to `<html>`
  only when it changes (a custom property on the root restyles the whole
  document), and text changing inside an element — the score counting up —
  no longer asks for a layout; what text does to a page's height the
  ResizeObserver already sees.
- chart-axis.js: the axes are looked for again only when elements arrive,
  not on every change of text.

## Measured

On throwaway copies of the development database: `ownify_perf` (a mysqldump
of the e2e database) and `ownify_perf_dense`, the same with a year of Health
Connect-like interval readings added for one account (93,696 rows). PHP's
built-in server with opcache, the code before (50.0 as committed, `69dd5b2`)
and after side by side on the same copy at the same time, the median of five
warm requests after one left out, five accounts. Statements are MariaDB's
`Com_stmt_execute` per request.

| Request | Before | After |
| --- | --- | --- |
| Overzicht page, the account with the most data | 201 ms, 708 statements | 137–142 ms, 304 statements |
| Overzicht page, a second account | 114 ms, 653 statements | 61 ms, 266 statements |
| App state, five accounts | 107–210 ms, 643–710 statements | 56–146 ms, 260–306 statements |
| Overzicht page, a year of interval readings | 412 ms | 264 ms |
| App state, a year of interval readings | 406 ms | 243 ms |
| First render of the day, the account with the most data (every kept total, score, goal progress and insight removed) | 603 ms, 1,958 statements, 1,019 writes | 495 ms, 1,502 statements, the same 1,019 writes |
| First render of the day, the other four | 121–125 ms, 742–754 statements | 63–82 ms, 308–316 statements |
| App state on the wire, the account with the most data | 597 KB | 107 KB (gzip) |

Every answer was compared value for value: the app's state of all five
accounts (10,475–28,228 values each) and both signed-in pages, byte for byte
(apart from the CSRF token, the nonce and the assets' `?v=` stamps), warm,
on the year of readings and on the first render of the day — identical. On
the first render, what each wrote is identical too: the day totals, the
scores, the insights (apart from the time they were made).

The score engine ran three times per render and now runs once. In Chromium,
on the account with the most data: script 246 → 90 ms; `interactions.js`
update 104 → 3 ms; the axis scan 81 → 1.8 ms.

**Once, after this ships:** a kept day total's fingerprint includes
`includes/health-totals.php` itself (`health_totals_rule()`), and the file
changed, so every account's kept totals are worked out again on its first
page — as after any change to that file. The totals come out the same.

Left as it is, on purpose:

- **The kept day totals' fingerprints** (some 118 ms on a year of interval
  readings): every read checks that a kept day still matches its readings,
  which is what lets nothing that writes readings have to know the table
  exists (includes/health-totals.php).
- **The Overzicht arrival** (about 1.1 s of main-thread work in the first
  2.5 s: the ring's glow, the halo, the count-up; 13 layouts with reduced
  motion, 162 without): it is the approved motion design (B12), not waste.
- **The page's size** (2.1 MB of HTML, 198 KB gzipped; a third of it
  indentation, 336 KB inline icons): compressed by the web server, and
  changing how it is written touches every template.
