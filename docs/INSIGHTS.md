# Insights: what Ownify says about your own data

Nuttige inzichten, Eén kleine suggestie and the explanations beside Ownify's
own reference values: short observations from the person's own data, each
with the approved research behind it. Not a chatbot, not a diagnosis, not an
order. The research itself — the registry, the labels and "Uit onderzoek
blijkt" — is [RESEARCH.md](RESEARCH.md).

## Where they show (both apps)

| Where | What |
| --- | --- |
| Overzicht › **Nuttige inzichten** | the day's insight for Slaap, Voeding and Training — or a quiet placeholder for an area without one |
| Overzicht › **Eén kleine suggestie** | at most one a day, always optional ("Altijd een suggestie, nooit een opdracht.") |
| Slaap, Voeding, Training (their own page) | the same stored insight as that area's own card, right under the area's score (29.1); nothing when the area has none |
| Instellingen › Account | the BMI, under Lichaamsmaten (`settings` block `insight`, slot `body`) |
| Voeding's cijfer | the research on eating patterns (NUT-03): context for the cijfer, never a score of it |
| Voeding's fat and carbohydrate rows | the 2026 norm behind fat's range, and that carbohydrates have none |
| Slaap's night, when it has stages | how far a wearable's stages can be trusted (SLP-06) |
| Training's heart rate, with data | how accurate a wearable's heart rate is (MEA-01) — on Training's own page, not on a session's |
| Scorekompas › **Mogelijk aandachtspunt** | the research about the component it names, where there is any: its context, never its reason |

Every one carries "Bekijk onderzoek" as the goal wizard's glass chip, opening
the detail ("Uit onderzoek blijkt"), which is the one place that says how
strong the research is (29.1: the label is no longer beside the insight). No new card,
chip or button: the insights card, the suggestion card and Instellingen'
blocks are the ones that were there.

The Scorekompas's fourth section is called **Mogelijk aandachtspunt** (it
was "Grootste kans"): a wording change only — the same calculation, the same
component, now said as what it is, a possible point of attention.

## How an insight comes about

```
facts (includes/insights/facts.php)
  → rules (includes/insights/rules.php, config/insights.php `rules`)
  → candidates the data and the research allow
  → ranked, at most one per slot and one suggestion
  → stored (user_insights) → shown by both apps (includes/insights/render.php)
```

**Facts** are read through the counting Ownify already has —
`health_nights`, `health_workouts_counted`, `health_metric_totals`,
`health_daily_metric_days`, `nutrition_targets_for_user` — so a night, a
workout or a day's steps count the same everywhere. Missing data is never
zero: a week without enough nights says nothing about sleep, a day whose
food log looks incomplete is left out of Voeding's averages.

**Data rules** (`config/insights.php` `data`) are Ownify's own, and decide
only whether there is enough to say anything: at least 5 nights in the last
7 days and the latest at most 2 days old; 5 complete days of steps; 3 days
whose logged energy is 70–160% of the person's energy target; workouts
recorded at all (2 in 28 days) before their absence means anything; a
weight at most 180 days old. Every number about health comes from a claim.

**Rules** — one per finding, each naming the claims it may use per age
group, in the source's own boundaries:

| Rule | Slot | Applicability | Claims | Outcomes | May suggest |
| --- | --- | --- | --- | --- | --- |
| `sleep.duration` | sleep | direct | youth 6–17, adults 18–60, CDC 61–64, NSF 65+ | below, within, above | below |
| `sleep.regularity` | sleep | direct | SLP-05 | less steady, steadier, level | less steady, level |
| `activity.minutes` | training | proxy | Beweegrichtlijnen youth 4–17, adults 18+ | below, reached | below (with WHO's "some is better than none") |
| `activity.strength` | training | proxy | Beweegrichtlijnen | below, reached | below |
| `activity.steps` | training | direct | Ding (below 7,000), Paluch's plateau for the age | below, toward, plateau | below |
| `nutrition.fat` | nutrition | proxy | NUT-01, 4+ | below, within, above | — |
| `nutrition.saturated_fat` | nutrition | proxy | NUT-01 | above, within | above |
| `nutrition.fibre` | nutrition | proxy | NUT-01 (per 1,000 kcal), 2+ | below, within, above | below |
| `nutrition.protein` | nutrition | proxy | NUT-02, 18+ | below, reached | below |
| `nutrition.salt` | nutrition | weak | NUT-03, 18+ (from logged sodium) | above, within | above |
| `body.bmi` | body | weak | BOD-01, 18+ | below, overweight, obesity | — |

Without a known age, a rule whose claim has an age bound gives nothing. A
label that would fall below Beperkt onderbouwd gives nothing either.

**Ranking** (`rank`): relevance × 10, the evidence as shown × 3, how much
data there was × 2; the same rule and outcome as yesterday in that slot
counts 4 less, so a new finding can come first. The suggestion is chosen
from the outcomes rules allow, never the same one within 3 days.

**Texts** are templates (`copy.bodies`), Dutch with their English in
`lang/en/insights.php`, numbers and dates through the i18n helpers in the
person's language and units. They describe and compare; they never say why
(no causes), never diagnose, never compare with other people and never
promise an outcome.

## Once a day, stored, and the same everywhere

The first page or app load of the person's local day works the day out;
every later load, on either app, reads what was stored
(`includes/insights/lifecycle.php`):

- **One worker.** The day is claimed with `INSERT IGNORE` into `insight_days`
  (`user_id`, `local_date`); whoever wins evaluates, everyone else reads. A
  `running` day older than 120 seconds was left by a request that died and is
  taken over with a compare-and-set on `started_at`.
- **New data.** A save or a sync marks the day (`data_changed_at`, by
  `insights_note_change()` — Health Connect and Polar imports, Voeding's
  cijfer, a meal, sleep or a workout typed in, a measurement, the onboarding
  facts). The next load reassesses — at most every 10 minutes and 8 times a
  day — against the stored facts. Nothing changed: nothing is rewritten. A
  slot whose finding changed is updated in place; the day's suggestion that
  no longer fits is **withdrawn, never replaced** ("Deze suggestie past niet
  meer bij je nieuwste gegevens."). Reassessing never asks Gemini again.
- **One row per slot per day** (`user_insights`, unique on `user_id`,
  `local_date`, `slot`): a re-sync that brings the same records twice writes
  nothing new.
- `engine_version` in `config/insights.php`: raised when a rule or text
  changes meaning, so the next load works the day out again.
- Stored days and insights older than 35 days are deleted.

Each stored insight keeps the exact claim version it used (`claim_id`), its
label and applicability, the facts it was written from (`facts_json`) and a
fingerprint of them — so it can be explained later, and never silently
changes meaning when the registry moves on.

## In Gemini's words — only with its own consent

> **Switched off for now** (`config/insights.php` `gemini.enabled` = false),
> until it is decided whether health data may go to Gemini's free API. While
> off: the Inzichten switch is not on Instellingen › Privacy (both apps), a
> yes cannot be saved (`api/profile/privacy.php` answers 409), no wording is
> asked for, and a wording stored earlier is not shown — every insight is
> Ownify's own sentence. OwnifyAI is not affected. Turning it back on is that
> one setting. `tools/insights-test.php` checks the off state, then tests the
> wording with `OWNIFY_TEST_INSIGHTS_GEMINI=1`.

By default every insight is Ownify's own template. With the separate switch
in Instellingen › Privacy › **Inzichten** ("Inzichten laten verwoorden door
Google Gemini", `user_profiles.ai_insights_consent`, version
`2026-10-insights-gemini-free`), Gemini may reword the day's insights once:

- **What is sent:** the template sentences, the numbers in them and the claims
  behind them — never a name, an account or any other data.
- **When:** after the response has gone (`register_shutdown_function`,
  `session_write_close`, `fastcgi_finish_request`), so no page waits for it.
- **How often:** one call per person per day; insights may use at most 60 of
  `config/ai.php`'s shared daily calls (the chat keeps the rest), and they
  respect the same pace and quota block as OwnifyAI. A quota or timeout
  leaves the templates; nothing retries in a loop.
- **What comes back** is checked before anything is stored
  (`insights_phrase_validate()`): JSON in the requested schema
  (`responseJsonSchema`, falling back to plain JSON on a model that refuses
  the schema), exactly the slots and claim ids asked for, at most 420
  characters, exactly the template's numbers and no others, no markup, the
  right language, and none of the forbidden words (causes, orders, diagnoses,
  comparisons with others, links). Anything else is dropped and the template
  shown.
- **Withdrawing** the consent removes every stored wording at once.

The free Gemini API may use what it receives to improve Google's products,
and Google's staff may read it — the Privacy page says so, beside both
Gemini switches.

## Data and privacy

Four tables (migration 028; [DATABASE.md](DATABASE.md)): `research_sources`,
`research_claims`, `insight_days` and `user_insights`, and three consent
columns on `user_profiles`. Everything about a person hangs off `users` with
`ON DELETE CASCADE`: deleting the account deletes their insights. The API
serves only the signed-in person's own; nothing is shared with Community.

## Efficiency, measured

`tools/insights-test.php` (a throwaway database, a stand-in Gemini):

- the day's first evaluation: about 25 ms; a stored read: under 1 ms;
- people today 8, evaluations 12, Gemini requests 5 — one per person with the
  consent who was asked;
- one wording request for 4 insights: about 3,360 bytes of JSON, roughly 840
  tokens by the usual 4 bytes a token. That is an estimate: the stand-in
  reports whatever tokens it is told to, so the real count (and
  `cachedContentTokenCount`, which is recorded) needs a real key.

## Tests

- `tools/insights-test.php` — the lifecycle (once a day, concurrency, a dead
  worker, re-syncs, reassessment, withdrawal, pruning), age gating, missing
  data, every rule's outcomes, the labels, Gemini's wording and every way it
  is refused, quota and timeouts, consent withdrawal, and that the points and
  the Health Score are untouched.
- `tools/migration-028-test.php` — migration 028 and its rollback on a
  throwaway database, the registry against it.
- `tools/i18n-test.php` — no Dutch in English, the research included.
- Android: `ui/ResearchFlowTest` — the stored insights and their chips on
  Overzicht, Slaap, the Scorekompas and Instellingen, each detail over the
  page that showed it and back, and the PDF fetched, cached and refused.
