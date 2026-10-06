# De eerste dagen

A new account starts with a short setup, **Hoe moet Ownify voor jou
werken?**, and then Overzicht opens with a card that follows its first days:
the baseline being built, the first score, and the starting point. It works
the same way on the website and in the Android app, and the server works it
out once for both.

It is not a tutorial. It asks only what Ownify needs to work for this
person. Every step after the first can be skipped, and nothing it shows is
made up: too little data is said plainly, never hidden behind a number.

| | |
| --- | --- |
| Stored | `user_profiles.focus`, `setup_state`, `setup_done_at` (`database/migrations/016-setup-and-focus.sql`) |
| Logic | `includes/setup.php`: storage, plus the pure parts (the focus order, the first days, the suggested goal), which use no database and no clock |
| Words | `config/setup.php`, every sentence of the setup and of the card |
| Page data | `lib/hydrate-setup.php` → `$data['setup']`, `$data['calibration']`, `$data['focus']` in `app_page_data()`, so `index.php` and `api/app/state.php` show the same thing |
| Endpoint | `api/setup/finish.php`; every answer is saved by the endpoint that always saves it (below) |
| Website | `pages/setup.php`, `assets/css/setup.css`, `assets/js/setup.js`; `components/calibration.php`, and `components/compass-category.php` (the Scorekompas's category, now shared) |
| App | `ui/screens/setup/SetupScreen.kt`, `ui/screens/overview/CalibrationCard.kt`, `data/AppData.kt` (`Setup`, `Calibration`) |

## Who sees it, once

**Only new accounts.** Registering writes `setup_state = 'pending'` in the
same transaction that creates the profile row. All four ways to register go
through it: e-mail on the website (`auth_register_email()`), e-mail in the
app (which calls the same function), and a first Google sign-in on either
(`google_signin_create_account()`). Every account that already existed keeps
`NULL` and never sees the setup or the card.

**The database decides, so it never comes back.** While the setup is
pending, `index.php` serves `pages/setup.php` instead of the app, and the
app's `ShellOrSetup` shows `SetupScreen` instead of the shell. These all read
the same row, so they agree:

- a reload;
- a browser closed and opened again (the lasting sign-in cookie brings the
  session back);
- another browser;
- the phone;
- signing out and in again.

**Uitloggen** at the top of the setup is the normal sign-out. Signing in
again goes back to the setup, past the focus once it has been chosen.

**Finishing** is `POST api/setup/finish.php`: the website sends its session
and CSRF token, the app its account token, and the answer is `{ ok, pending:
false }`. It sets `done` and `setup_done_at = NOW()`, but only on a pending
setup, so a second call changes nothing, not even the day it finished. After
that the website reloads and the app reads its state again, and the server,
not the page, decides it is the app now. The answer is 503 until migration
016 is imported.

Deleting an account removes its profile row, and these columns with it, as
before.

## The four steps

| Step | Asks | Saved by | |
| --- | --- | --- | --- |
| 1 Focus | *Wat wil je het liefst begrijpen?* Slaap, Energie, Fitheid, Gewicht or Alles | `api/profile/update.php` (`focus`) | one answer; **Verder** |
| 2 Gegevens | *Gebruik wat je al meet*: Health Connect | — | **Doorgaan zonder koppelen** |
| 3 Over jou | Geboortedatum, Lengte, Gewicht, each with why | the birth date `api/profile/onboarding.php`, height and weight `api/profile/update.php` | **Overslaan**, or **Opslaan en verder** |
| 4 Doel | *Wil je meteen een doel stellen?* | `api/goals/create.php` | **Naar Ownify** finishes, with or without a goal |

**Gegevens** offers Health Connect only, because it is the one source a
phone can link today. It is left out:

- Apple Health has no app to link it with.
- Google Health in the cloud has no way to connect yet (no `start.php`).

The website cannot reach a phone, so it says where to go: the Ownify app on
an Android phone, signed in to this account. The app asks Health Connect
itself (the phone's own permission screen), offers to install or update it
when it is missing, and shows what it read. Under it: a manual way (a daily
cijfer for Voeding on Gezondheid) and where to do this later (Instellingen →
Apparaten & Gezondheid).

**Over jou** asks only what something needs, and says what:

| Field | Used for |
| --- | --- |
| Geboortedatum | the estimated maximum heart rate (Tanaka, `health_hr_max()`), so how hard a training was; and the energy needs |
| Lengte | the energy needs (`includes/nutrition-targets.php`), which Ownify AI uses |
| Gewicht | the energy needs, and the start of a weight goal |

Without any one of the three there are no energy needs at all. The setup
asks nothing else:

- **Gender and activity level** only refine the energy needs. Without them
  the formula takes its neutral midpoint and the lowest level, and says so.
  Gender can be filled in once in Instellingen; activity level has no field
  on either app.
- **Names** are the person's to add in Instellingen when they like.

A value the account already has is shown as it is. A birth date is set once,
in the setup as in Instellingen, and a birth date already given is shown,
not asked again. With the focus Gewicht, Gewicht comes first.

**Doel** is the normal goal model: **Zelf een doel instellen** opens the
same goal wizard as Doelen (`components/goal-wizard.php`, `GoalWizard`). It
offers a goal of its own only when one can be worked out (below).

## A first goal, only when it can be worked out

The setup suggests a goal only when all of these hold:

- the account has no active goal and may add one;
- goal sources are available;
- the last 14 days of the person's own records carry it (`setup_suggestion_plan()`).

| Focus | Tried, in order |
| --- | --- |
| Slaap, Energie, Alles | sleep, then training |
| Fitheid | training, then sleep |
| Gewicht | nothing: how much, and which way, is the person's own call |

- **Sleep** needs at least 3 nights. The target is the next half hour above
  their average (6:41 → *minstens 7 uur*) on 5 nights of a week: an
  Optellen goal counting days, read from `sleep_duration`. There is none
  above 8 hours, where the sleep score's duration curve tops out.
- **Training** needs at least 3 workouts. It takes their number per week
  plus one, between 2 and 7: an Optellen goal counting sessions over a week.

The goal is checked with the wizard's own rules exactly as it would be made
(`goal_create_from_input(…, dryRun: true)`), so a suggestion that would be
refused is never shown. It is described the way the assistant describes a
goal (`ai_goal_summary()`). The card shows:

- *Een mogelijk eerste doel*;
- what it is based on (*Je sliep de afgelopen 9 nachten gemiddeld 6:41.*);
- the goal itself.

**Toevoegen** makes it through `api/goals/create.php`. **Niet nu** removes
the suggestion, and the wizard is still there. It is never called realistic,
achievable or recommended.

## The focus

What the person most wants to understand. It changes the **order** of things
and never **what** is shown, or any score, weight or size:

- the chip on Overzicht's score card (*Alles*, *Slaap*, *Energie*,
  *Fitheid*, *Gewicht*);
- the ring's legend, and so the categories in the Scorekompas
  (`setup_focus_order()`):

  | Focus | Order |
  | --- | --- |
  | Fitheid | training, slaap, voeding |
  | Gewicht | voeding, training, slaap |
  | Slaap, Energie, Alles | slaap, voeding, training |

- the first days: the rows of the card, and which category the day's fact
  comes from (with Gewicht, the newest weight first);
- the setup itself: Gewicht first in *Over jou*, and which goal is tried
  first.

It can be changed later in Instellingen → Account → **Focus**, in the same
editor every field has, saved by the same endpoint. `NULL` (never chosen) is
*Alles*. The old focus values in `config/dashboard.php` (`nutrition`,
`mobility`, `mental`) were never stored anywhere, so there was nothing to
migrate.

## The first days on Overzicht

Day 1 is the day the setup was finished. The card (`calibration`) sits at
the top of Overzicht on days 1 to 5:

- on days 1 to 3 its eyebrow says *Dag 1 van 3*;
- on days 4 and 5 it says *Je basislijn*.

After day 5 the card is gone. Which card it is follows from the engine
alone:

| Phase | When | Shows |
| --- | --- | --- |
| `building` — *Je basislijn wordt opgebouwd* | no category has a score yet | each category in the focus's order (see below); one fact |
| `first_score` — *Je eerste slaapscore* | the first day a category had a score, and the day after | that category exactly as the Scorekompas shows it (below) |
| `baseline` — *Je startpunt* | after that, up to day 5 | each category that has a score (below) |
| `baseline` — *Nog geen startpunt* | days 4 and 5 without any data at all | that it needs 3 days with data, and the rows as they stand |

Days 4 and 5 with some data but no score yet are still `building`, with
their own lede.

In `building`, each category row shows:

- how far it is, in its own unit: *2 van 3 nachten*, *1 van 3 dagen met
  een cijfer*, *0 van 3 trainingsdagen*;
- a step lit for each day;
- either what the days so far hold (*Gemiddeld 6:41 per nacht*, *Gemiddeld
  een 7*, *2 trainingen, 75 min*), or where its data comes from: *Komt
  binnen via je koppeling* once a source has delivered something, *Komt
  binnen zodra je Health Connect koppelt*, or *Geef je voeding een cijfer op
  Gezondheid*.

The `first_score` card shows that category's score with its band's dot and
each component with the weight it has now. Under it, what the Health Score
rests on for now (*Je gezondheidsscore rust voorlopig alleen op slaap.
Voeding en sport tellen mee zodra er 3 dagen van zijn.*). It links to the
Scorekompas.

The `baseline` card shows each category with a score: its score, its band's
dot and one fact from what the score was worked out from (average sleep a
night, average cijfer, training days a week). It says which categories come
later, and links to the Scorekompas.

The day the first score appeared is found by scoring each day since the
setup at its end, exactly as the Scorekompas's history does (today counts as
of now). History a phone imports on day 1 gives a score at once: the first
score shows on days 1 and 2, the starting point after that. It is never held
back to make the days look like a countdown.

### One fact a day

One sentence from the newest data, from the first category in the focus's
order that has some. It says what happened, never what to do:

| | |
| --- | --- |
| one night | *Je laatste nacht: 6:41 geslapen, van 23:40 tot 6:21.* |
| more nights | *Je ging de afgelopen 3 nachten gemiddeld om 23:40 naar bed.* (the engine's own circular mean of bedtimes) |
| one cijfer | *Je gaf je voeding gisteren een 7.* |
| more | *Je dagcijfers voor voeding lagen tussen 6 en 8.*, or *Je gaf je voeding 3 dagen een 7.* |
| a workout | *Je laatste training duurde 45 minuten, gisteren.* |
| Gewicht | *Je startgewicht: 82,4 kg.* |

## It reads the score, it never scores

**The Health Score is the engine's own.** It is calculated over the last
168 hours (`window_hours` in `config/scoring.php`; 90 days before 1.6.0). A
category gets a score after 3 days with data (`min_days`), stops counting
after 3 days in a row without new data (`expiry_days`, Slaap and Voeding),
and the Health Score is the average of the categories that have one. The
first days add no formula for new accounts and change no weight, curve,
window or minimum:

- the progress counts the days the engine counts (`health_score_at()`, on
  the records it reads);
- the first score is the Scorekompas's own row for that category;
- the facts are the ones its score was worked out from.

`tools/first-days-test.php` and `tools/app-state-test.php` check that the
first score is the engine's own category score.

## Words that changed

The old copy spoke of an onboarding that did not exist, or promised what
the engine does not do:

| Where | Was | Now |
| --- | --- | --- |
| Gezondheid's intro with no score yet | *Je hebt nog 2 dagen data nodig om een score te ontgrendelen.* | *Je eerste score volgt na 3 dagen met gegevens: nog 2 dagen.* |
| Overzicht's ring, some data but no score | *Nog geen gegevens* · *Verbind een bron om je gezondheidsscore te berekenen.* | *Nog geen score* · the sentence above |
| Overzicht's goal card, no goal | *Tijdens de onboarding kies je één doel. Je voortgang van deze week verschijnt hier.* | *Je voortgang van deze week verschijnt hier zodra je een doel instelt.* |
| The focus chip | *Algemeen* | *Alles* |

## Before migration 016

Every new file is required only when it is there (`is_file`), because a
deploy lands one file at a time. `setup_stored()` checks the three columns.
Until the migration is imported:

- registering works as before, and opens on the app;
- there is no setup and no card;
- everybody's focus is *Alles*, and Instellingen has no Focus row;
- a focus sent to `api/profile/update.php` answers with a sentence instead
  of saving it;
- `api/setup/finish.php` answers 503.

`php tools/check-config.php` says whether the columns are there.

To import it: phpMyAdmin → select the database **first** → **Import** →
`database/migrations/016-setup-and-focus.sql`. Re-running it is safe, and
nothing needs to be filled in: existing accounts keep `NULL`.

## Tests

- `php tools/first-days-test.php` (no database, the real engine) walks:
  - days 1 to 6 with nothing;
  - one night each morning;
  - history imported on day 1;
  - a daily cijfer by hand;
  - training, and two categories at once;
  - that the focus orders and never hides;
  - the tone: no advice, nothing called good, bad or realistic;
  - the suggested goal and its limits;
  - the words.
- `tools/app-state-test.php` (development database) checks:
  - a new account starts pending, and an existing one never does;
  - the focus and the profile save through their usual endpoints;
  - the website and the app show the same setup;
  - finishing is final, also after signing out and in again;
  - the first score is the ring's own number with the Scorekompas's own
    components, the day after it is still shown, then the starting point,
    then no card;
  - an account deleted in the middle of its setup is gone.
- `tools/goals-test.sh` and `tools/health-connect-test.sh` finish the setup
  of the accounts they create.
- `OwnifyAppFlowTest` checks the setup from start to finish, a restart past a
  chosen focus, the profile fields, the suggested goal and the wizard, the
  card's three phases opening the Scorekompas, an existing account seeing
  neither, and the focus changed in Instellingen. `AppDataParseTest` checks
  the blocks as the server sends them.
