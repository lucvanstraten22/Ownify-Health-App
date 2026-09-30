# Ownify AI

The assistant in the sheet you swipe up — on the website and in the Android
app. It answers questions about the person's own sleep, nutrition, training,
activity, body measurements, Health Score and goals, with Google Gemini on the
**free tier of the Gemini API**, and it can prepare a goal that is only added
once the person says yes.

```
website  (assets/js/ai-chat.js)        ─┐
                                         ├─►  api/ai/*.php  ─►  includes/ai/  ─►  Gemini API
Android  (data/OwnifyAssistant.kt)     ─┘         │                             generateContent
                                                  ▼
                                   ai_conversations · ai_messages · ai_usage · ai_service_state
```

Both apps talk to the same five endpoints, with the same answers, the same
limits and the same stored conversations — a conversation started on the phone
is there on the website. Neither app ever talks to Google: the Gemini API key
exists only on the server.

## Setting it up

Three things, once:

1. import `database/migrations/015-ai-assistant.sql`;
2. create a Gemini API key in Google AI Studio, in a project **without
   billing**;
3. put the key on the server.

Until all three are done the assistant says *De AI-assistent is tijdelijk niet
beschikbaar* and nothing else in the app changes.

### 1. The migration

phpMyAdmin → select the database (`luc_ownify` on the server) in the sidebar
**first** → **Import** → `database/migrations/015-ai-assistant.sql`. Re-running
it is safe. The deploy never carries `database/`, so this is always a manual
step. `database/schema.sql` already has everything for a fresh install.

### 2. A Gemini API key (Google AI Studio)

1. Go to **<https://aistudio.google.com>** and sign in with the Google account
   that should own the key. The first time, accept the terms. A new user gets
   a default Google Cloud project, and a key in it, automatically.
2. **The project must not have billing.** Google's free tier belongs to a
   project; a project with a linked billing account moves up to *Tier 1* and
   is charged for what it uses. Ownify must only ever use the free tier, so:
   - use the default project AI Studio made, or a new one made for Ownify
     alone (recommended: then nothing else shares its free quota);
   - do **not** press *Set up billing* anywhere in AI Studio;
   - to use an existing Google Cloud project instead: AI Studio →
     **Dashboard** → **Projects** → **Import projects**, and pick one whose
     billing is disabled (Google Cloud Console → **Billing** → *My projects*
     shows it). Then go to **API Keys**.
3. **API Keys** → **Create API key**, in that project. Keys made in AI Studio
   since 28 May 2026 are *authorization keys*, limited to the Gemini API from
   the start. An older key marked **Unrestricted** is refused by the Gemini
   API: hover over the label → **Add restrictions** → **Restrict to Gemini API
   only**.
4. Copy the key. It is a password: never in a chat, an e-mail, a screenshot, a
   GitHub issue, a commit, JavaScript or the Android app.

**See the limits you actually have** on AI Studio's rate-limit page,
<https://aistudio.google.com/rate-limit>. Google applies them per project, not
per key, and resets the daily ones at midnight Pacific time. If the daily
number of requests for `gemini-3.8-flash` there is lower than Ownify's
`global_daily_limit` (250), lower that setting to match (see *Settings*).

### 3. The key on the server

**Recommended — `config/ai.local.php`.** The same mechanism as the database
password and the app key: git-ignored, so it is never in the repository, and
the deploy only uploads files from the repository, so it never overwrites or
removes this one. In the web root, beside `index.php`:

```bash
cd /home/<hestia-user>/web/ownify.acits.nl/public_html
cp config/ai.local.php.example config/ai.local.php
nano config/ai.local.php                 # replace PUT-YOUR-GEMINI-API-KEY-HERE
chmod 600 config/ai.local.php
php tools/check-config.php               # [ ok ] ownify ai  key from config/ai.local.php, …
php tools/check-config.php --ai-ping     # one tiny request: "It works: …"
```

Without SSH, Hestia's File Manager does the same: copy
`config/ai.local.php.example`, rename the copy to `ai.local.php`, and replace
the placeholder with the key. A placeholder still starting with `PUT-` is never
taken for a key.

**Or an environment variable**, `GEMINI_API_KEY`, which wins over the file —
e.g. `env[GEMINI_API_KEY] = "…"` in the domain's PHP-FPM pool. The same caveat
as for `OWNIFY_APP_KEY` applies ([DATABASE.md](DATABASE.md#where-to-put-it-on-hestia)):
Hestia regenerates that pool file from its template, so the line can quietly
disappear. Never `SetEnv` in `.htaccess`.

**WampServer:** the same file, in the project folder. The update workflow
mirrors the repository with `robocopy /MIR` and excludes `*.local.php`, so a
key file there survives every update.

The app looks for the key in this order, first one wins:

1. `getenv('GEMINI_API_KEY')` — the process environment
2. `$_SERVER['GEMINI_API_KEY']` — e.g. Apache `SetEnv` in the server config
3. `config/ai.local.php`, returning `['api_key' => '...']`

`php tools/check-config.php` says which one it found — never the key, not even
a prefix — and `--ai-ping` asks Gemini one question of a few tokens to prove
the key and the model work.

## Settings

`config/ai.php`, overridable by `config/ai.local.php` and then by the
environment — so the model or a limit changes without a code change:

| Setting | Default | Environment | What it is |
| --- | --- | --- | --- |
| `model` | `gemini-3.8-flash` | `GEMINI_MODEL` | the Gemini model; must be one on the free tier |
| `daily_limit` | 10 | `AI_DAILY_MESSAGE_LIMIT` | questions per person per day |
| `global_daily_limit` | 250 | `AI_GLOBAL_DAILY_LIMIT` | Gemini requests per day, everybody together |
| `max_rounds` | 3 | — | Gemini requests one question may take (look-ups included) |
| `max_message_chars` | 2000 | — | the longest question |
| `history_messages` | 12 | — | recent messages sent along with a question |
| `history_digest` | 8 | — | earlier questions of the conversation, listed as a reminder |
| `max_conversations` | 50 | — | conversations kept per person; the oldest go |
| `timeout` | 45 | `GEMINI_TIMEOUT` | seconds to wait for Gemini |
| `max_output_tokens` | 2048 | — | the ceiling on an answer, thinking included |
| `thinking_level` | `low` | `AI_THINKING_LEVEL` | how hard the model thinks; `low` is fastest and cheapest on quota |
| `consent_version` | `2026-09-gemini-free` | — | the consent wording people said yes to (see *Consent*) |

## Free, and staying free

Nothing Ownify AI does costs money, by construction: a project without
billing, a free-tier model, no Google Search grounding, and no second provider
or paid model to fall back on. When the free quota is gone, the assistant says
so and waits.

Three limits, checked on the server in this order before Gemini is asked:

1. **Google's own quota.** When Gemini answers HTTP 429 (`RESOURCE_EXHAUSTED`),
   Ownify writes down until when in `ai_service_state` and asks nobody's
   question until then: a daily quota until the next midnight Pacific time,
   when Google resets it; a per-minute one for the delay Google gives (30
   seconds to an hour). Meanwhile everyone gets *de gratis gebruikslimiet is
   bereikt*, and their question stays in the field.
2. **Ownify's own total**, `global_daily_limit`: the Gemini requests made today
   by everybody together (the sum of `ai_usage.gemini_calls`). One question
   takes one request, or two or three when the assistant looks something up.
   It keeps Ownify under Google's limit, so one busy day cannot use up the
   project's quota; past it, the same *gratis gebruikslimiet* answer.
3. **Each person's share**, `daily_limit`: questions per person per calendar
   day (Europe/Amsterdam), in `ai_usage.messages`. A question is reserved with
   one atomic `UPDATE … WHERE messages < limit` before Gemini is asked — two at
   the same moment cannot both take the last one — and given back if Gemini
   does not answer, so a failure never costs a question. Answering a proposal
   (the button, or "ja" / "nee") is not a question and is not counted. Past it:
   HTTP 429 and *Je hebt de gratis AI-berichten van vandaag gebruikt. Morgen kun
   je weer verder.* The sheet shows *Nog 7 van 10 berichten vandaag*.

The frontends only display these numbers; the server enforces all three.

## Consent and privacy

**Nothing goes to Gemini before the person says yes.** The first time the sheet
opens it asks *Ownify AI gebruiken?* and says, in five points: which of their
data is sent to Google Gemini; that it goes automatically and only what fits
the question; that this is the free Gemini API, so Google may use it to improve
its products and people at Google may read it; that conversations are kept in
their account and can be wiped; and that the assistant does not diagnose or
replace a doctor. Two buttons: *Toestaan en beginnen* and *Niet nu*.

The answer is stored per account — `user_profiles.ai_consent` (1 yes, 0 no,
NULL never asked), `ai_consent_version` and `ai_consent_at` — so it follows the
person to every device. The server checks it on every question (HTTP 403
`consent` otherwise); an app cannot skip it. A yes to an older
`consent_version` counts as never asked: change the wording in
`config/dashboard.php` (`ai` → `consent`) in substance — another tier, another
provider, other terms — and change `consent_version` with it, and everybody is
asked again.

**Changing one's mind:** Instellingen → Privacy → *Ownify AI* → *Gegevens
verwerken met Google Gemini* is the same answer as in the sheet, on both apps.
Off, nothing is sent; the sheet says *Ownify AI staat uit* and offers
*Toestemming bekijken*. **Wiping:** Instellingen → Privacy → *AI-gesprekken* →
*Alles wissen* (asks first), or one conversation from the history list in the
sheet. Deleting the account deletes everything of Ownify AI with it (`ON DELETE
CASCADE`).

**What goes to Gemini with a question** — worked out afresh for each question
(`includes/ai/context.php`), never stored:

- always: the date and time; the profile as far as the person filled it in —
  first name, age (never the date of birth), gender, height, weight, activity
  level, member since, language — plus a list of what is unknown, so nothing is
  guessed; the Health Score now; the last 7 days against the 7 before; the
  goals;
- only when the question is about it: the nights (14), training (28 days),
  daily activity (14 days), nutrition (7-14 days), body measurements (30 days),
  and the person's **own** place and points on the boards;
- the conversation: the last 12 messages and a list of up to 8 earlier
  questions.

Never: username, e-mail, profile picture, anyone else's data, passwords,
tokens, or where the person is. The assistant can ask for a longer stretch or
another area through its tools (below), which read the same person's data the
same way.

**What is stored:** the questions and answers (`ai_messages`), each
conversation's title (its first question, shortened), a proposal and what
became of it, and per answer the model, the number of requests, token counts
and the names of the tools used. Never the health data that was sent.

**What is logged:** event lines only — *ai: request received*, *ai: Gemini
request successful (2 requests, tools: get_sleep_summary)*, *ai: not available:
no_key*, a quota or timeout — never the key, a question, an answer, the prompt
or any health data.

## What the assistant is told

`config/ai-prompt.php`, in parts: who it is (Ownify's personal health
assistant); insight first — help people understand themselves, keep what the
data shows apart from interpretation, never correlation as causation; the data
(use the real values, never make up what is missing, ask a tool rather than
guess); scope (health only); safety (no diagnoses; for urgent symptoms a
doctor, 112 in an emergency, 113 for thoughts of suicide); research (general
knowledge only — it has no live search and never invents studies or
citations); actions (changes are proposals); and style (Dutch by default,
informal *je*, short, plain text with a little **bold**). `includes/ai/prompt.php`
joins them with the conversation so far and the data above.

Answers come back as plain text and are turned into paragraphs, headings and
lists of text spans on the server (`includes/ai/format.php`); both apps draw
text and bold only — never HTML from the model.

## Tools (function calling)

Gemini is given nine functions with explicit schemas
(`includes/ai/tools.php`). Everything they read is the signed-in person's own,
with the id from the session — never an id Gemini names.

| Function | Does |
| --- | --- |
| `get_health_summary` | the Health Score and its parts, day by day, up to 90 days |
| `get_sleep_summary` | nights, averages and sleep-time measurements, up to 90 days |
| `get_training_summary` | workouts, weekly totals, VO2max and load, up to 90 days |
| `get_recent_activity` | steps, distance, active minutes, calories, floors per day |
| `get_nutrition_summary` | ratings, calories, macros, water, meals, and the targets |
| `get_goals` | the goals as they stand now, optionally the last completed ones |
| `get_measurement_history` | one body measurement over time |
| `create_goal` | **prepares** a new goal — creates nothing |
| `update_goal` | **prepares** a change to one of their goals (pause, resume, complete, make primary or secondary) — changes nothing |

**How a question runs** (`ai_chat()` in `includes/ai/assistant.php`): Ownify
asks Gemini with the functions available; when Gemini asks for one, Ownify
carries it out and sends the result back, and Gemini goes on — at most
`max_rounds` (3) rounds per question, the last one forced to answer in words,
all within 55 seconds. An unknown function or wrong arguments go back to Gemini
as an error, never to the person.

**A change is only ever a proposal.** `create_goal` checks the goal exactly as
the Doelen wizard would (`goal_create_from_input()` in `includes/goal-create.php`,
without saving — the same rules, including the limit on active goals from
`config/goals.php` and the one primary); `update_goal` checks that the goal is theirs and that the change
makes sense. At most one proposal per answer. It is stored with the answer
(`ai_messages.action_json`, `action_state` *pending*) and shown as a card with
two buttons, e.g. *Doel toevoegen* / *Niet nu*. Only then:

- the button (`api/ai/action.php`) or a plain "ja" / "nee" as the next message
  carries it out or turns it down — no Gemini request, not counted;
- it is claimed atomically (pending → running), so a double tap does it once,
  and checked again at that moment, then marked done or failed; Doelen is read
  again on both apps;
- asking something else instead lets it expire.

## The endpoints

All POST, all JSON, all for a signed-in account only — the website's session
(with its CSRF token) or the app's account token (`api_require_account_user()`).
None accepts a user id; every query filters on the id from the sign-in.

| Endpoint | Takes | Does |
| --- | --- | --- |
| `api/ai/state.php` | `conversation_id`, or `new=1` | consent, availability, today's usage, the conversation list and one conversation |
| `api/ai/chat.php` | `message`, `conversation_id` | asks; returns the question and the answer as stored, and the usage |
| `api/ai/consent.php` | `decision` = `accept` / `decline` | stores the answer |
| `api/ai/action.php` | `message_id`, `decision` = `confirm` / `decline` | answers a proposal |
| `api/ai/delete.php` | `conversation_id`, or `all=1` | deletes one conversation, or all of the person's |
| `api/profile/privacy.php` | `ai_consent` = `1` / `0` | the Privacy switch: the same answer as `consent.php` |

A refusal carries a `code` and a Dutch sentence (`config/dashboard.php`, `ai` →
`errors`) — never Gemini's own text: `consent` 403, `empty` / `too_long` 422,
`not_found` 404, `limit` 429, `quota` / `unavailable` / `timeout` 503,
`blocked` 422 (Gemini would not answer this question), `action_gone` 409 (the
proposal was already answered).

## Testing without Google

```bash
DB_NAME=ownify_dev DB_USER=root php tools/ai-test.php
```

The whole backend over HTTP against a stand-in for Gemini
(`tools/ai-fake-gemini.php`, which only runs under PHP's built-in server):
authentication, consent, conversations, isolation between accounts, the limits,
every kind of failure, the tools and the proposals. No key, and nothing is sent
to Google. It needs a development database with migration 015 — never a live
one. The Android app's flow tests (`OwnifyAppFlowTest`) cover the sheet against
a fake server in the same way.
