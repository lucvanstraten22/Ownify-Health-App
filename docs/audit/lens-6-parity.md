# Lens 6 — Web ↔ Android parity and terminology

Reviewer 6 of 9. Read-only. `apple-design` loaded. `$A` = the evidence set in BRIEF.md.
**Stopped early on the coordinator's instruction**: Part A is finished, Part B is partly done, Part C was barely started (see "Not checked").

## Summary

1. **Part A — photo on Android:** the cause is the **screenshot harness, not the app**. The data, the state, the parsing and the composable are all correct. The harness was given `-Downify.server=http://127.0.0.1:8080` without a trailing slash, so every avatar URL came out malformed and every Android shot shows the placeholder icon.
2. **Part A — real name:** this is **DESIGN, and both apps agree**. Neither account popup shows the real name; it is shown on both in Instellingen › Account. The web phone-* shots show "Dev" and no photo only because they were taken before the photo upload and the name change.
3. Several parity differences in the evidence come from **how it was captured**, not from the apps: when it was taken, a temporary device token, goal ids that do not exist for this account, and the web ring caught mid-animation. Other reviewers should not count these as app differences (6.1).
4. Real inconsistencies found so far:
   - The Overzicht "Primair doel" card has no chevron on Android (6.2).
   - The insight tier label is 13 px on the web and 11 sp on Android (6.3).
   - An insight bar at 0 % is dashed on the web and solid on Android (6.4).
5. PARITY.md is out of date on the pillars: it still says capsules and dots, but both apps draw rings (6.5).
6. Wording: 37 screen pairs were compared text by text in both themes. Apart from evidence artefacts, the server-sent copy is identical on both apps.

---

## Part A — the owner's question: photo and real name in the account sheet

### Verdict

| | Web account popup | Android account sheet |
|---|---|---|
| Photo | **Shown.** It is missing from `phone-*` only because of when those shots were taken. | **Missing from the shots because of the screenshot harness.** The app itself loads it correctly; on a real phone it should show (HYPOTHESIS, not tested on a device). |
| Real name | **Not shown — DESIGN.** | **Not shown — DESIGN.** Same as the web. |

### The chain, link by link (all VERIFIED)

1. **DATA — stored.**
   - `SELECT … FROM ownify_e2e.users u JOIN user_profiles p` → `dev_jij | Jasper | van der Veen | uploads/avatars/u1-2e42db4798b3a48f.png`.
   - The file exists in the folder the test server runs from (`/proc/1375/cwd` → `scratchpad/e2e/app`): `uploads/avatars/u1-2e42db4798b3a48f.png` plus its small `-s.webp` copy, both written at 13:10.
   - It is not in the repository's own `uploads/`. That is expected: the server runs from a copy.
2. **The server builds it.**
   - `includes/user.php:84-94` `user_account()` selects `p.first_name, p.last_name, p.avatar_path`.
   - `lib/app-data.php:281-300` `app_auth_account()` puts it in `auth.user`.
   - `api/app/state.php:44` and `app_state_payload()` (`lib/app-data.php:320-326`) only remove `id`.
   - `settings_profile()` (`lib/settings.php:108, 325`) builds `settings.profile`.
3. **STATE — sent.** `$A/android/state-dev_jij.json` (fetched 13:13, after the 13:08 upload) contains:
   - `data.auth.user.avatar_path` = `uploads/avatars/u1-2e42db4798b3a48f.png`
   - `data.auth.user.first_name` = `Jasper`, `.last_name` = `van der Veen`
   - `data.settings.profile.{avatar, first_name, last_name, name="Jasper van der Veen"}`
   - the small copy (`u1-…-s.webp`) as `community.you.avatar`, in every board entry for `dev_jij`, and in the challenge faces.
4. **Android parsing.**
   - `data/AppData.kt:2904-2913` `Auth.parse`: `avatar = user.str("avatar_path")` is read; `first_name` and `last_name` are **not** read into `Auth`.
   - `AppData.kt:2562-2566` `Profile.parse` does read `avatar`, `first_name` and `last_name`.
5. **Android account sheet.**
   - `ui/screens/account/AccountPanel.kt:197` calls `AvatarCircle(auth.avatar, 52.dp, 22.dp)`.
   - `:199-200` shows `auth.username` and "Lid sinds …"; the name is not shown, by design (as on the web).
   - `AvatarCircle` (`:323-328`) → `ui/app/Avatar.kt` `Avatar()` → `Avatars.load(path)` → `connection/OwnifyApi.kt:371-395` `image()`.
6. **The harness — this is where the photo is lost.**
   - `$A/android-run.sh` passes `-Downify.server=http://127.0.0.1:8080`, with no trailing slash.
   - `ScreenshotCapture.kt:103` creates `OwnifyApi(server)`.
   - `OwnifyApi.kt:373` builds the URL as `URI.create(baseUrl + path.trimStart('/'))`, which gives `http://127.0.0.1:8080uploads/avatars/u1-….png`.
   - That throws `MalformedURLException: Error at index 4 in: "8080uploads"`. I reproduced this with a two-line Java check in the scratchpad; with the slash, the same URL returned `200 image/png`.
   - The exception is caught at `:392-394` and `null` is returned. `preload()` (`ScreenshotCapture.kt:118-128`) therefore caches nothing, and `Avatar()` falls back to `OwnifyIcons.user`.
   - The app's own `BASE_URL` is `"https://ownify.acits.nl/"` (`OwnifyApi.kt:558`) and ends with a slash. The other tests also pass a URL with a slash (`ThemeSwitchTest.kt:47`, `PhoneSyncUiTest.kt:241`).
7. **Web.**
   - `components/account-modal.php:41` reads `$user = $auth['user']`.
   - `:122-128` shows the `<img src=avatar_path>`.
   - `:131-132` shows the username and "Lid sinds".
   - The file header says why there is no name (`:6-7`): "Deliberately not a profile page."
   - The header button shows the photo too (`components/header.php:41-45`). It is visible in `$A/web/pop-phone-dark/acc-main-withphoto.png`.
8. **Where the real name is shown — on both apps.**
   - Instellingen › Account, the hero's meta line.
   - Web: `pages/settings-detail.php:85-87`; seen as `settings-hero__meta` "Jasper van der Veen" in `$A/web/en-phone-dark/detail-settings-account-0.json`.
   - Android: `ui/screens/settings/SettingsDetail.kt:154-161`; seen as "Jasper van der Veen" in `$A/android/dark/app-settings-account.json`.
   - The Settings identity card shows the username on both: `pages/settings.php:44-46` and the Android `app-settings.png`.

### What this means for the evidence

- **Every Android shot that should show a picture shows the placeholder instead.** This affects:
  - the header button (`AppShell.kt:233` → `Header.kt:133`)
  - the Instellingen identity card
  - the Instellingen › Account hero
  - the community podium ("Jij")
  - the leaderboard
  - the challenge faces
- Do not report any of these as an app defect without a re-capture.
- The web `phone-dark` and `phone-light` sets (12:34–12:56) were taken before the upload at 13:08 and before the name change. They show no photo and the name "Dev". The `pop-*` and `en-phone-dark` sets (13:08–13:12) come after both.

### Proposal

- **Owner's choice (PREFERENCE):** if the real name should appear in the account sheet, add it as the `account__meta` line on both apps. That uses an existing style, but it reverses the stated "not a profile page" decision, so it needs the owner's approval.
- **Harness (test-only, S):**
  - Re-run `ScreenshotCapture` with `-Downify.server=http://127.0.0.1:8080/` so the shots carry the photo.
  - Either add the trailing-slash requirement to `OwnifyAndroid/README.md:276`, or have `OwnifyApi` add the slash itself. `send()` at `:469` joins strings the same way, so the same issue applies there.
  - Risk: none for the API contract.

---

## Part B — parity

### 6.1 Evidence artefacts that look like parity differences
- **Status:** VERIFIED · **Kind:** an evidence defect, not an app defect.
- **Where and why:**

| What you see | Why |
|---|---|
| Instellingen: Android "1 verbonden" vs web "Geen verbonden" | `$A/state-fetch.php` issues a temporary device token for the fetch. The server counts Health Connect as connected as soon as a phone signs in (PARITY.md:237). |
| Devices popup: Android lists "Fixture capture" / "Health Connect · Verbonden"; web says "Geen apparaten gekoppeld" | Same cause: the temporary device token. |
| Thema: Android "Systeem" vs web "Donker" | Documented in PARITY.md:244. |
| Android `app-goal-300…303` are all the Doelen page ("Nog geen doelen") | `ScreenshotCapture.kt:496-499` hardcodes goal ids 300–303 from the demo account. dev_jij's only goal is id 1 (ended, under Afgerond). There is **no Android evidence for the goal detail page**. |
| Web Overzicht ring looks pale with a glowing head; the pillar rings have no coloured arc | `$A/cap.js:29` waits only 500 ms; `assets/js/hero-ring.js:19` has the head travel for 2500 ms; the pillar arcs stay undrawn until `hero-ring.js` draws them (`components.css:863-871`). Android is captured with `LocalStillMotion`, i.e. at rest. |
| Goal wizard: Android "Optimaal 7.000 stappen per dag" + "Je gegevens verschijnen hier…" vs web "Nu 8.100 · optimaal 10.000 stappen" | **HYPOTHESIS:** the Android wizard shots use `-Downify.state.free` (dev_lisa, an empty account). Confirm in `ScreenshotCapture`'s wizard tests. |
| Score 86 in the web `pop-*` shots (13:08) vs 87 in `page-overview` (12:34) and in the Android state (13:13) | **DATA-SUSPECT**, not investigated. |
| "DEV â€” 300.000 stappen in april" | Double-encoded test data, like the "calorieÃ«n" case in PARITY.md:288. |

- **Proposal:** re-capture both apps from one state at one moment:
  - no device token left in the state;
  - real goal ids;
  - the web captured with reduced motion, as PARITY.md's own verification does.
- **Priority:** P1 for the audit's reliability.

### 6.2 The Overzicht "Primair doel" card has no chevron on Android
- **Status:** VERIFIED · **Kind:** defect
- **Where:** `$A/web/phone-dark/page-overview-0.png` vs `$A/android/dark/app-overview.png`.
  - Web: `components/goal-progress.php:26` (`icon('chevron-right', 'card__open-chevron')` after the title).
  - Android: `ui/screens/overview/OverviewPage.kt:321` (`CompactHead(OwnifyIcons.solidChart, goal.title)`, which has no chevron).
- **Problem:** the web head reads "Primair doel ›"; Android reads "Primair doel" with no chevron. The insights card next to it does have its chevron on both apps (`OverviewPage.kt:520-523`).
- **Why it matters:** the whole card opens the goal, or Doelen when there is none. The chevron is the only visual cue that it does, and it is missing on one app.
- **Proposal:** give Android's goal card head the same label-plus-chevron as the web. The pattern already exists at `OverviewPage.kt:211-223` (`.card__open-chevron`).
- **Complexity:** S · **Risk:** none.
- **Priority:** P2

### 6.3 Insight tier label: 13 px on the web, 11 sp on Android
- **Status:** VERIFIED · **Kind:** defect
- **Where:** Overzicht › Nuttige inzichten (`page-overview-1` vs `app-overview-2`).
  - Web: `components/insights.php:81` → `.card__meta--small` = `--fs-subhead` (`assets/css/components.css:176`).
  - Android: `OverviewPage.kt:561` (`JStyle.Tiny`).
- **Problem:** the metrics give "Nog onduidelijk" as fs 13px / 400 on the web and size 11 / 400 on Android, for every row.
- **Proposal:** use `JStyle.Meta` (FsSubhead) on Android. Then check the same label on the Inzichten page (`ui/screens/insights/Insights.kt`; not checked).
- **Complexity:** S
- **Priority:** P2

### 6.4 An insight still being gathered, at 0 %: dashed bar on the web, solid on Android
- **Status:** VERIFIED · **Kind:** defect (small)
- **Where:** "Late training en inslapen" (C02: `state` `waiting`, `progress` 0).
  - Web: `insights.php:68-70` marks the row `.is-empty`, and `.is-empty .meter` draws the dashed track (`components.css:962-966`).
  - Android: `ReadingBar.kt:88-90` calls `Meter(share = 0)`, and `Meter` only draws dashed when `share == null` (`Components.kt:562`).
- **Problem:** the web shows the dashed "nothing yet" track; Android shows a solid empty track.
- **Proposal:** on Android, draw the dashed track when the row is not `filled`. That matches the web's rule that a row still being gathered is "as quiet as an empty one".
- **Affects:** the Inzichten page and the insight page's bar (`Insights.kt:123, 198`).
- **Complexity:** S
- **Priority:** P3

### 6.5 PARITY.md is out of date
- **Status:** VERIFIED · **Kind:** documentation defect
- **Stale claims:**
  - Line 48: "the three pillars as capsules (value at Title 2, dot, name)". Both apps have drawn rings since 18.7: `components/health-score.php:103-113` (`.pillar-rings`) and `Components.kt:756-765` (`PillarRings`).
  - Lines 161-162: the score colour is "only the dot beside each pillar". It is now the pillar ring's arc (`--score-color`, `components.css:863-866`).
- **Missing differences:**
  - 6.2, 6.3 and 6.4.
  - The harness needs a server URL that ends in "/" (Part A).
- **Proposal:** update those lines; add the trailing-slash note to the README or PARITY's Verification section.
- **Complexity:** S
- **Priority:** P3

### 6.6 Wording and copy across 37 screen pairs (both themes)
- **Status:** VERIFIED · **Kind:** —
- **How:** every web text was compared with Android's on-screen texts, allowing for the web metrics cutting long texts off.
- **Result:** no copy difference except the artefacts in 6.1.
- **Split vs single strings:** a few texts are one string on Android and two elements on the web. The text is the same; whether it looks the same was not checked.
  - Overzicht date: "woensdag" + "11 november" on the web.
  - Training session: "Maandag 9 november ·" + "18:15 – 18:54" on the web.
- **Why some texts are "missing" on Android:**
  - Android draws chart level labels and ticks on a Canvas, so they are not in its text dumps.
  - Some texts appear only on the web because the Android capture scrolls less far: the Scorekompas stops before "Vergeleken met jezelf"; Meldingen has no scroll, so "Opvallende veranderingen / Suggesties / Vriendverzoeken…" are not captured; Voeding's open rows; the challenge code "07460".

### 6.7 Photo field's file name
- **Status:** VERIFIED · **Kind:** PREFERENCE (platform)
- **What:** the web shows "Geen bestand..." (the browser's own file-input text, cut off, on two lines); Android shows "Geen bestand gekozen" (in full, on two lines). See `acc-main-withphoto` vs `app-account`.
- **Note:** lens 3 covers the redesign of this field (early observation 2).
- **Priority:** P3

---

## Part C — terminology (barely started)

- I saw no difference in Dutch copy between the apps. The server sends almost every string to both (6.6).
- One code observation: `components/account-modal.php:85` prints the literal `'Account'` without `t()`; Android uses `tr("Account")`. It reads the same in English, so nothing is visible, but it breaks the i18n rule. **Status:** VERIFIED · **Priority:** P3.
- Not done: the list of terms in the task (Gezondheidsscore/score/dagscore, Trainingen/Training/Workouts, Doel/Doelen, Uitdaging, OwnifyAI wording, "Bekijk onderzoek"/"Uit onderzoek blijkt", capitalisation), and comparing `lang/en/*.php` with Android's `assets/lang-en.json`. PARITY.md:248 says that file is generated from the same catalogue; this was not verified.

## Not checked / could not verify

- **Part B visuals not reviewed:**
  - Pages: the Scorekompas, Slaap, Voeding, Training and their area charts, the training session, Ranglijst, Uitdagingen, a challenge, Inzichten, an insight, research, all eight `settings-*` pages.
  - Popups: devices, assistant, goal wizard, challenge sheet, code, welcome.
  - All of White Mode (the light text diff matched dark).
  - Side-by-side composites for these are ready in `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/lens6/sbs/` (web left, Android right). The diff script and its output are `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/lens6/cmp.py`, `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/lens6/dark.txt` and `/tmp/claude-0/-home-user-Health-App/ed16b8ae-9bd3-5924-8d5f-adc273350a39/scratchpad/lens6/light.txt`.
- **Goal detail parity:** there is no Android evidence (6.1). It needs a capture with goal id 1.
- **Photos everywhere on Android:** needs a re-capture with the corrected server URL. The device behaviour itself was not tested.
- **Part C:** as above. Also not done: whether the English for Android-only strings differs between web and Android.
- **PARITY.md:** not checked line by line against the screens I did not review.
