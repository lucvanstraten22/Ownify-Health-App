# The red dots

Since 26.0 a small red dot shows what waits for the person to do it, on the
website and in the Android app alike. No push, no e-mail, no system
notification: only these dots, inside the app. Meldingen buiten de app are
still to come.

## What gets a dot

| | Appears when | Clears when |
| --- | --- | --- |
| Voeding | today's cijfer is not given yet, by someone who gives one (at least once before), from the account's hour on (20:00 unless chosen otherwise) | the cijfer is saved |
| Doel | a running goal kept by hand that counts days (`Zelf bijhouden`), with today not ticked | today is ticked off (taking it back brings the dot back) |
| Vriendverzoek | someone asked to be your friend | you accept or decline |
| Uitnodiging | you are invited to a challenge | you join or decline |
| Bron | a source stopped working (status `error` or `revoked`) | it works again |

Nothing informational gets a dot: no goal reached, no new score, no
leaderboard change, no insight. A Mijlpaal or an Optellen goal and a goal
per week get none either: they need input only when there is something to
enter.

## Where it shows

On the icon it belongs to, never beside a word, and on each step of the way
to the action only:

| | The tab or button | The page | The action |
| --- | --- | --- | --- |
| Voeding | tab Gezondheid | the Voeding tile's icon | the check icon of "Hoe at je vandaag?" |
| Doel | tab Doelen | the goal card's icon | the check icon of "Zelf bijhouden" |
| Vriendverzoek | the account button (the sidebar's account on desktop) | — | the requester's picture under Vriendverzoeken |
| Uitnodiging | tab Community | — | the invitation's picture (or tile) |
| Bron | tab Instellingen | the Apparaten & Gezondheid row's icon | the source's icon |

The Vrienden row says "1 verzoek" already and the Uitdagingen card lists the
invitation itself, so neither gets a dot of its own. A parent's dot stays
while anything under it waits.

## How it looks

One dot, `--notice` (Granaat: `#D4564E` Dark, `#B9382F` White;
`Ownify.Notice`), flat — no shine, no shadow:

- **on a tile, a picture or a round button**: 8 across, inside the top-right
  corner and peeking a little past the edge, cut out of what it sits on by a
  1.5 ring of the page's ground (`rgb(var(--ground))`, `Ownify.BgMain`). The
  ring is drawn concentric with the dot by construction (a spread shadow on
  the web, one circle behind the other in the app). On a tile it sits 0.5 in;
  on a circle 14.64 % of the diameter in, less 3.3 — where the circle's own
  edge is — so every size of picture shows the same small peek.
- **beside a bare line icon** (the tab bar, the desktop sidebar), where a dot
  in the corner would cover the icon: 7 across, 3 above and 6 past the
  icon's corner (4 in the sidebar), with a 2.5 halo of its own colour at 18 %.

It grows out of its own centre as it arrives (220 ms) and back as it goes
(160 ms), ease-out; with less motion it simply is there or not. On the tab
you are on it sits under the tab bar's moving glass, as the icon does, so it
is quieter there; everywhere else it is plain.

Website: `.notice-dot--corner` / `--beside` (`assets/css/components.css`),
`notice_dot()` in the templates. App: `Modifier.notice(key, NoticePlace)`
(`ui/design/NoticeDot.kt`), and `IconTile(…, notice = key)`.

## Where it comes from

The server works out one summary for both apps (`lib/notices.php`,
`notices_for()`), from what the page is drawn from anyway — nothing new is
stored, so a refresh, a restart or the other app always agrees:

```json
"notices": { "rating": { "waiting": true, "hour": 20, "due": true },
             "goals": ["655"], "friends": [261], "invites": [73],
             "sources": ["polar"] }
```

Each dot has a key — `rating`, `goal:<id>`, `friend:<user id>`,
`invite:<id>`, `source:<provider>`, and for the parents `goals`, `sources`,
`account`, `tab:health|goals|community|settings` — read by `notice_on()` on
the server, `OwnifyNotices.on()` in `assets/js/notices.js` and
`Notices.on()` in the app, with the same rules.

`due` is the server's clock, for the first paint only: both apps decide the
Voeding dot on the device's own clock, and look again at the hour, so it
turns up at 20:00 on a screen left open.

The website clears a dot the moment its action succeeds
(`OwnifyNotices.done()`), then takes the server's word from the next page it
fetches (`OwnifyNotices.read()`). The app reads its state again after every
change, as it always does.

## Meldingen › Stip bij Voeding vanaf

The hour the Voeding dot appears: 16:00, 18:00, 19:00, 20:00 (the default),
21:00 or 22:00. Kept on the account (`user_profiles.rating_notice_hour`,
`database/migrations/026-notice-time.sql`; NULL is 20:00), saved through
`api/profile/preferences.php` (`rating_notice`), so the website and the app
agree. Until the migration is imported every account has 20:00 and the
choice says the database is not updated yet.

## Tests

`tools/notices-test.php` (the rules, the keys, the element) and the app's
`NoticesTest`; the hour as a saved choice in `tools/theme-test.php`.
