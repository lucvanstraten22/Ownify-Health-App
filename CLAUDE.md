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

## Version number

The version shown in Instellingen → Over de app (`config/settings.php`: the
`about` row and its `Versie` field) is currently **Beta 1.1.1**. Bump it with
every change that is shipped, in the same commit, and keep the Android
`versionName` (`OwnifyAndroid/app/build.gradle.kts`) at the same number
(without "Beta") with `versionCode` one higher:

- a small update (fix, tweak): patch, e.g. 1.0.0 → 1.0.1
- a bigger change (new feature): minor, e.g. 1.0.1 → 1.1.0
- a big UI refresh: major, e.g. 1.1.0 → 2.0.0 — only when the user says so

Every commit title starts with the new version number, then the
description: `1.0.2 Show the version in commit titles`.
The next version is 1.1.2 for a small update, or 1.2.0 for a bigger change.
