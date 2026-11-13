# Cleanup (50.0, Phase 6)

What was removed in 50.0's cleanup, and why each is safe. The rule: only
what is proven unused — no call, include, route, cron, tool, test, Android
reference or dynamic call (`call_user_func`, a name built from a string)
reaches it, checked across the whole repository (web, `api/`, `lib/`,
`includes/`, `tools/`, `OwnifyAndroid/`). What is unclear stays, and is
listed at the end.

## The database

**`goals_backup_024` and `goal_progress_backup_024`** — dropped by
`database/migrations/032-drop-goal-backups.sql` (rollback:
`032-rollback.sql`). Migration 025 (22.0) made them as plain copies of the
goals of before the goal rebuild, "to look back at or drop". Nothing reads
or writes them; the old goals cannot be carried into the new model; they
only keep old personal data. 032 touches nothing else, does nothing on a
database that never had them, and can be run again. Tested on a throwaway
database: both dropped, `goals` and its rows untouched, a second run a
no-op, the rollback putting the two names back (empty — their rows only
come back from the backup taken before). `schema.sql` never had them.
Migrations are imported by hand; 032 is not run anywhere by this change.

Kept: `daily_scores`. The comment above a removed reader said nothing
writes it; that was out of date — `includes/health-score.php` writes and
reads it, and the export and the wipe include it.

## The website and the server (PHP)

Seventeen functions nobody calls (a name search over every PHP, script, SQL and Kotlin file finds only the definition; no `call_user_func` or string-built call exists anywhere):

| Function | File | Proof |
| --- | --- | --- |
| `ai_message_count()` | includes/ai/store.php | no caller anywhere |
| `auth_normalise_email()` | includes/auth.php | no caller anywhere |
| `auth_validate_password()` | includes/auth.php | no caller anywhere |
| `challenge_suggest_target()` | includes/challenges.php | no caller anywhere |
| `user_blocked_ids()` | includes/friends.php | no caller anywhere |
| `health_sleep_sessions()`, `health_workouts()`, `health_nutrition_entries()`, `health_daily_scores()` | includes/health-data.php | no caller anywhere |
| `integrations_connected_count()` | includes/integrations.php | no caller anywhere |
| `points_history()` | includes/points.php | no caller anywhere |
| `polar_days()` | includes/polar.php | no caller anywhere |
| `research_band()` | includes/research.php | no caller anywhere |
| `user_search_by_username()` | includes/user.php | no caller anywhere |
| `goal_chart_month()` | lib/goal-chart.php | no caller anywhere |
| `settings_use_state_variant()` | lib/settings.php | no caller anywhere |
| `range_days()` | tools/health-score-test.php | no caller anywhere |

Every file lints; the PHP tests pass (below).

Removed with the button change (style 2, the same release): the
`.btn--caution` rules and the gold glass (decision 2C) — the irreversible
step is gold words in a split now — and the goal wizard's own nav rules
(`.goal-wizard__nav` is a `.split.split--go`).

## The Android app

Eighteen declarations nothing uses (a name search over all Kotlin and XML, tests included, finds only the definition; two of them, `OrbGlass` and `fieldBox`, were used only by the others); the app compiles and the tests pass:

| Declaration | File | Proof |
| --- | --- | --- |
| `AiTexts.busyText()` | data/AppData.kt | no caller anywhere |
| `PageOverlay()` | ui/app/AppShell.kt | no caller anywhere |
| `DockSpacer()` | ui/app/Dock.kt | no caller anywhere |
| `Modifier.touchTarget()` | ui/app/Overlays.kt | no caller anywhere |
| `CardFilter` | ui/design/Glass.kt | no caller anywhere |
| `groundAtHand()` | ui/design/Ground.kt | no caller anywhere |
| `Surfaces.WelcomeButton`, `Surfaces.WelcomePrimary` | ui/design/Surfaces.kt | no caller anywhere |
| `GlassOrb()`, `OrbGlass` | ui/screens/Welcome.kt | no caller anywhere |
| `Badge()`, `FileField()`, `Modifier.fieldBox()` | ui/screens/account/AccountPanel.kt | no caller anywhere |
| `MetricChip()`, `PickPerson()` | ui/screens/community/ChallengeSheets.kt | no caller anywhere |
| `Skeleton()` | ui/screens/overview/OverviewPage.kt | no caller anywhere |
| `BasisRow()` | ui/screens/research/Research.kt | no caller anywhere |
| `OwnifyThemeStore.dialogContext()` | ui/theme/OwnifyThemeStore.kt | no caller anywhere |

`lang-en.json` (built by `tools/i18n-build.php`) loses the four sentences
only these used; `lang/en/` keeps them where the website still asks.

## Kept on purpose

- **Theme accessors no screen reads yet** — `Ownify.GlassStrong`,
  `ShadowCard`, `ShadowFloat`, `SurfaceTo`, `TipSurface`, `AttentionGold`,
  and the type style `Small`: they mirror `theme.css` tokens and roles, so
  taking them out would be a change to the style system, not cleanup.
- **CSS rules without a match in the markup**: class names are also built
  in scripts (`'is-' + state`, `classList.toggle(…)`), so a rule that looks
  unused cannot be proven so by searching. None removed.
- **Every asset** in `assets/` is referenced by name.
- **The migration history** (001–031 and the rollbacks): it is how an
  existing database is brought up to date.
