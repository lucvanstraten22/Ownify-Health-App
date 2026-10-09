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

## Version number

The version shown in Instellingen → Over de app (`config/settings.php`: the
`about` row and its `Versie` field) is currently **Beta 14.0**. Bump it with
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
The next version is 14.1 for a small update, or 15.0 for a big one.
