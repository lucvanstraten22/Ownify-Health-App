# JoLu

JoLu is two apps: the web app (this repository's root: `index.php`, `pages/`,
`components/`, `assets/`, `api/`, `lib/`) and the native Android app
(`HealthappAndroid/`, Jetpack Compose). The web app is the source of truth for
how things look and behave; `HealthappAndroid/docs/PARITY.md` records where
and why the Android app differs.

## Every request applies to both apps

Unless a request explicitly says "webapp only" or "Android app only", it
applies to BOTH the web app and the Android app: implement it in both, and
keep them visually and behaviourally consistent — design, interaction,
timing, hierarchy and user experience — wherever technically possible. Use
each platform's own implementation (CSS/JS on the web, Compose on Android)
to reach the same result. Do not ask whether a request also applies to the
other platform unless a genuine technical ambiguity prevents implementing it.
