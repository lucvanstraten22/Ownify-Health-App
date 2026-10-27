# Ownify

Ownify is two apps: the web app (this repository's root: `index.php`, `pages/`,
`components/`, `assets/`, `api/`, `lib/`) and the native Android app
(`OwnifyAndroid/`, Jetpack Compose). The web app is the source of truth for
how things look and behave; `OwnifyAndroid/docs/PARITY.md` records where
and why the Android app differs.

## Every request applies to both apps

Unless a request explicitly says "webapp only" or "Android app only", it
applies to BOTH the web app and the Android app: implement it in both, and
keep them visually and behaviourally consistent — design, interaction,
timing, hierarchy and user experience — wherever technically possible. Use
each platform's own implementation (CSS/JS on the web, Compose on Android)
to reach the same result. Do not ask whether a request also applies to the
other platform unless a genuine technical ambiguity prevents implementing it.

## Two themes

Ownify has Dark Mode (the default) and White Mode, on both apps. Every colour
goes through the theme: a token or a role in `assets/css/theme.css` (`:root`
for Dark, `:root[data-theme="light"]` for White) and `OwnifyPalette` /
`Ownify.ink(…)`, `fill(…)`… in `OwnifyAndroid/app/src/main/java/com/ownify/android/ui/theme/OwnifyTheme.kt`
— never a literal white, black or ground grey in a component. Anything new
must look right in both themes. `docs/THEME.md` explains the roles.

## Two languages, two unit systems

Ownify is Dutch and English, metric and imperial, on both apps:
`docs/I18N.md`. Code and config are written in Dutch; every user-facing
string goes through `t()`/`tf()`/`tn()` (PHP), `T.t()`… (scripts) or
`tr()`/`trf()`/`trn()` (Kotlin) with the literal Dutch, and gets its English
in `lang/en/` — then `php tools/i18n-build.php` (and `--check` must pass).
Numbers and dates go through the i18n helpers, never a fixed Dutch format.
Measurements are stored in kg, cm, km/m, °C and seconds per km and are only
converted where they are shown or typed; never store a converted value.

## Charts over time

Every chart that shows something over days, on both apps — the Verloop,
the Scorekompas, a goal's chart and every future one — follows one
standard: `docs/CHARTS.md`, in code `lib/time-axis.php`. A chart represents
a period of time (7 dagen, 30 dagen, 90 dagen, 1 jaar), not the data there
happens to be: a young history starts at the left on its first day and the
rest of the period stays empty on the right; once the history fills the
period the window rolls, today at the right. Dates every day / every third
day / every week / 13 month boundaries, never "Vandaag" or a year. Read it
before building or changing a chart; never give a chart its own window,
dates or positions.

## Design and motion skills

For all design, interface, motion and UI-quality work on Ownify — on both
apps — use these skills whenever they are relevant, as standing
instructions: `apple-design`, `mobile-native`, `prototype`, `break-ui`,
`improve-animations` and `review-animations` (and `animation-vocabulary` to
name an effect). **`apple-design` is the highest authority**: where the
skills disagree, it wins. If a skill is not available in a session, say so
plainly instead of working as if it had been used.

## Strict design consistency

Never introduce new styles, components, variants, tokens or one-off
implementations without the user's explicit approval. Always use the
approved, consolidated style system (`docs/THEME.md`: the one Liquid Glass,
the quiet card, Well / Row / Note / Slot, the green and gold glass actions,
the pill field, the two icon-tile sizes, the radius scale, the Randlicht
ground) consistently across both the web app and the Android app. Never
introduce inconsistencies or make design decisions on the user's behalf. If
a new style seems genuinely necessary, or anything is unclear, ask first and
wait for approval.

## Version number

The version shown in Instellingen → Over de app (`config/settings.php`: the
`about` row and its `Versie` field) is currently **Beta 28.1**. Bump it with
every change that is shipped, in the same commit, and keep the Android
`versionName` (`OwnifyAndroid/app/build.gradle.kts`) at the same number
(without "Beta") with `versionCode` one higher.

Since 10.0 the version has two parts (the user's choice; 1.9.2 was followed
by 10.0):

- a small update (fix, tweak): the second number, e.g. 10.0 → 10.1
- a big update (new feature, bigger change): the next whole number, e.g.
  10.1 → 11.0

Every commit title starts with the new version number, then the
description: `10.1 Show the version in commit titles`.
The next version is 28.2 for a small update, or 29.0 for a big one.
