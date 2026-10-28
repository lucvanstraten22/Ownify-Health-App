# Uitdagingen — challenges with friends

Since 18.0 Community is two cards: **Ranglijst** (the leaderboard, as it always
was, now on a page of its own) and **Uitdagingen**: challenges you do with the
people you choose. This is how they work, on the website and in the Android
app alike.

In code: `includes/challenges.php` (the rules and every sentence),
`config/challenges.php` (what can be counted, the lengths, the copy),
`api/challenges/` (the endpoints), `database/migrations/022-challenges.sql`
(the tables); `pages/community.php`, `pages/leaderboard.php`,
`pages/challenges.php`, `pages/challenge-detail.php`,
`components/challenge-sheet.php`, `lib/challenges-ui.php`,
`assets/css/challenges.css`, `assets/js/challenges.js` (the website);
`data/Challenges.kt`, `ui/screens/community/CommunityPage.kt`,
`Challenges.kt`, `ChallengeSheets.kt`, `ui/app/BottomSheet.kt` (the app).

---

## What a challenge is

| | |
|---|---|
| **Samen** | everybody adds up towards one target: "100 trainingen in oktober" |
| **Wedstrijd** | everybody for themselves; whoever does the most wins |
| **What it counts** | Trainingen, Stappen, Actieve minuten, Afstand, Nachten van 7 uur |
| **How long** | 1 week, 2 weken or 1 maand (to the day before the same date next month), from today or from next Monday (a week later when today is a Monday). The last day counts |
| **Name** | up to 60 characters, one line; left empty, the one it suggests ("Samen 100 trainingen in een week", "Wie zet de meeste stappen?") |
| **Code** | five digits, made when the challenge is made |

The Samen target is chosen with a stepper from a suggestion per length
(`per_week` × weeks in `config/challenges.php`), in what the reader sees —
km or mi — and stored in km.

## The rules

These are the product's rules, as decided for 18.0.

- **Anybody with the code can join.** Nobody has to be friends. A code shows
  the challenge first — what it is, who made it, who is in it, what the others
  would see — and only **Meedoen** joins.
- **A code works for the whole challenge**, its last day included: somebody
  can join on the last day. After that it says the challenge is over.
- **Nobody is ever joined for anybody.** Friends can be invited; an
  invitation is something to look at, accepted (Meedoen) or turned down
  (Afwijzen) only by the person invited. A turned-down invitation is not sent
  again; the code still works should they change their mind.
- **What counts, counts from the day you join** — or from the start, when you
  joined before it.
- **Only synced data counts**: Health Connect, Polar. A workout or a night
  typed in by hand (source `manual`) never does. Steps are the day total the
  Training card shows, with two apps that counted the same walk counted once.
  A night counts when it is the main sleep of at least 7 hours, filed under
  the morning it ended.
- **What participants see of each other**: the username, the picture as the
  leaderboards show it (Instellingen › Privacy), and the total for this
  challenge. Nothing else — no profiles (yet).
- **A tie goes on.** A Wedstrijd tied at the top on its last day goes into
  **Verlenging**: the tied people go on, a day at a time, and the first day
  that *ends* with one of them ahead decides it ("lotte won na verlenging").
  Only the tied people are in it; the others' totals stay as they were on the
  last day. Joining is closed during it.
- **Leaving** takes you out, and what you contributed with you. The code lets
  you back in while it runs, counting from that day. Nobody can remove anybody
  else.
- **Challenges have nothing to do with points** or the leaderboard.

Limits: 50 people in a challenge, and 20 challenges a person is in at the same
time (`CHALLENGE_MEMBERS_MAX`, `CHALLENGE_RUNNING_MAX`). Wrong codes are
slowed down as wrong passwords are: five in fifteen minutes
(`includes/auth-throttle.php`, action `challenge_code`), so codes cannot be
tried one after the other — joining with a code is checked and counted the
same way, so it is no way around it.

## Where everybody stands

Worked out when shown (`challenge_standings()`), from each participant's own
synced data for this challenge's metric and dates only. Nothing about anybody's
health is stored in the challenge tables.

**Samen** — the total against the target; the bar is everybody's piece side
by side (you in the colour, the others in four inks, 2 px between them), then
what is still to go; past the target the pieces are scaled to fill it. The
pace counts only the days already behind you: day one is "Net begonnen",
never behind; then "9 trainingen achter op schema", "Precies op schema",
"Doel gehaald". After the last day: Gehaald or Niet gehaald.

**Wedstrijd** — places by total (equal totals share a place), your total
against the leader's, and the gap to the one directly ahead ("Nog 5.000
stappen tot daan"), or your lead ("5.000 stappen voor op lotte", "Gelijk met
lotte"). After the last day: who won.

Every sentence is written on the server, in the reader's language and units
(`challenge_view()`), so the website and the app say the same.

## The screens

**Community** — two cards, each a way in and a picture of what is behind it
(23.0, "Twee vensters"). Ranglijst: your friends' top three this month on a
podium — second, first, third, the steps 64, 84 and 50 high, you ringed —
and the line about the one ahead of you; a place nobody holds yet is an open
spot that adds a friend, and with nobody to compare to, Vriend toevoegen. Its
head opens the full board. Uitdagingen: how many are on and how many
invitations wait (or "Nog niets bezig"), the invitations with Bekijken, the
challenge that ends first on top of a stack of up to two more (each opening
its own page) — since 30.0 in the green Note (`--health-wash`, `--health-edge`;
`Modifier.note()` on Android), the cards behind it showing only their foot —
or the empty target; then Nieuwe uitdaging and Code invoeren
as two glass tiles side by side, each with the same solid neutral mark (a
plus, a hash) since 29.2. The cards' marks are solid white on the neutral
grey, as Overzicht's: the podium bars for Ranglijst, an archery target for
Uitdagingen. On the desktop (docs/DESKTOP.md) the Ranglijst card shows
Vrienden and Nederland side by side, each the six rows around your place
with "#2 van 5", instead of the podium.

**Ranglijst** — the leaderboard, unchanged: Vrienden / Nederland, Maand / Jaar
/ All-time, your sticky row.

**Uitdagingen** — the same two tiles; then Uitnodigingen, Samen (on now, the
bar in everybody's pieces and who brought what), Wedstrijd (on now, the top
three with their bars) — each soonest to end first — and Afgelopen (the last
ten). With nothing at all, the empty target and three ways to begin
(`page.ideas` in config/challenges.php), each opening Nieuwe uitdaging with
its kind and what it counts already chosen.

**A challenge** — what it is, where it stands, the people (and who is
invited), the code to copy or share and Vrienden uitnodigen while it runs, what
counts and who made it, Uitdaging verlaten. Opened from Uitdagingen it goes
back there; opened from the stack on Community, back to Community.

**The sheet** — one bottom sheet for Nieuwe uitdaging (a live preview at the
top as it is filled in, then its code), Code invoeren (five boxes; the fifth
digit looks it up and the found challenge appears in the same sheet), Bekijken,
Vrienden uitnodigen and Uitdaging verlaten. Dragged down by its grip, or a tap
outside, closes it; with reduced motion it only fades.

## The endpoints

All take the website's session and CSRF token, or the app's account token
(`api_require_account_user()`), and a form or JSON body.

| Endpoint | Does |
|---|---|
| `api/challenges/create.php` | `kind`, `metric`, `target` + `target_unit` (Samen), `length`, `from`, `name`, `invite[]` → `{ id, code, name, invited, share_text }` |
| `api/challenges/lookup.php` | `code` → `found` with its `review`, or `member` (you are in it), or `none` / `ended` / `wait` (429) with an `error` |
| `api/challenges/join.php` | `challenge_id` (an invitation you have) or `code` → `joined` or `member` |
| `api/challenges/respond.php` | `challenge_id`, `action` `decline` or `leave` |
| `api/challenges/invite.php` | `challenge_id`, `invite[]` (your friends) |

The pages read everything else from `challenges` in the page data — the same
in `api/app/state.php` ([APP-STATE.md](APP-STATE.md)).

## Tests

- `php tools/challenges-test.php` — making one, the dates, a target in miles,
  codes (found by anybody, until the last day, wrong ones slowed down),
  joining only by one's own tap, synced data only and from the day you join,
  Samen's bar and pace, Wedstrijd's places and gap, a tie going on until a day
  ends with one ahead, leaving, what another person sees, the endpoints with
  the app's token.
- The app's `ChallengesParseTest` and `ChallengesFlowTest` (the server's own
  answers as fixtures: `challenges-state.json`, `challenge-lookup.json`).
