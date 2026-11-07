# A wide screen (web only)

The website has one interface: the phone's. On a screen 768px or wider it
is shown inside an iPhone in the middle of the window; there is no separate
desktop layout (it was removed in 36.0). The Android app is a phone app and
has nothing of this.

## Which layout

`<html data-layout>` is set by the first script in `<head>`
(`components/document-head.php`), before anything is painted, and again
whenever the window crosses 768px:

| Screen | Layout |
| --- | --- |
| narrower than 768px | `phone` — the phone's own |
| 768px and wider | `phone-frame` — the phone's website in an iPhone (below) |
| inside the iPhone (`data-frame="phone"`) | `phone` — the phone's own |

Every page has the iPhone: the app (`index.php`), the welcome screen
(`pages/welcome.php`) and the first-run setup (`pages/setup.php`).

## The iPhone (34.0)

A wide screen shows the website as a phone shows it, in an iPhone 15 / 16
Pro (`components/phone-simulator.php`, `assets/css/phone-sim.css`,
`assets/js/phone-sim.js`):

- **The screen is the website itself**: a same-origin `<iframe>` of the page,
  393 × 852. In there the head's script sees the frame (`data-frame="phone"`)
  and the layout is the phone's own, so every breakpoint, viewport unit,
  script and animation is the phone's — nothing is copied. All it changes in
  there: the iPhone's safe areas as a home-screen web app (`--safe-top` 59,
  `--safe-bottom` 34) and no visible scrollbars (scrolling stays). The theme
  in the phone is the account's own.
- **The device** is drawn around and over it and does nothing: titanium
  band, bezel, the buttons, the Dynamic Island, the status bar (the time;
  signal, Wi-Fi, battery; in the ink the page asks for — its
  `apple-mobile-web-app-status-bar-style`) and the home indicator. Its
  colours are a phone's, named once in phone-sim.css (`--device-*`).
- **Around it** the page is always the light Randlicht ground, whatever the
  theme: while this layout is shown the head paints this document light
  (`ownifyTheme.paint`); the account's theme is untouched and comes back
  when the window narrows. The page around the phone is hidden and inert.
- **Fitted to the window**: centred, never larger than itself, scaled down
  (`--sim-k`) so the whole device always shows with 24–64px around it.
- **Loaded only while shown**: the screen loads when the window is wide and
  is let go when it narrows. If anything was done in the phone, the page
  then reloads, so it never shows what the phone has since changed.
- **Account e-mail links** (`?account-link#token`, `?wachtwoord-vergeten`)
  go into the phone whole; the token leaves the window's address bar at once
  (`assets/js/account.js`).
- **Google and Polar take the whole window** (44.1). Their sign-in and
  consent pages refuse to be framed (Google answers *403. That's an error.*),
  and so do Ownify's own callbacks (`frame-ancestors 'none'`). So every trip
  there leaves the phone through `window.ownifyLeave(url)` (the head's
  script, `components/document-head.php`): from the phone it moves the top
  window, as a phone browser shows it, and sets the short-lived marker
  `ownify_trip`. The trips are signing in or registering with Google,
  linking or removing it, confirming with Google, deleting an account that
  has it, and connecting Google Health or Polar. The way back lands on the
  page around the phone. While the marker is there, the server leaves the
  one-time message for the phone's own request (`auth_flash_take()`, by
  `Sec-Fetch-Dest`), and settings.js leaves its reopen notes for it too. The
  phone's request clears the marker. Back on a narrow window, the head's
  script clears it and reloads once.
- A mouse is still a mouse in there: hover is the desktop browser's, not a
  finger's.
