# The desktop layout (web only)

The website has a layout for desktop and tablet screens next to
the phone's. The Android app has none (it is a phone app) and is unchanged.

## Which layout

`<html data-layout>` is set by the first script in `<head>`
(`components/document-head.php`), before anything is painted:

| Screen | Layout | The switch |
| --- | --- | --- |
| narrower than 768px | `phone` — the phone's own | not shown |
| 768px and wider | `desktop` — unless this browser chose Telefoon | shown |
| 768px and wider, Telefoon chosen | `phone-frame` — the phone's layout in a 430px column | shown |

The choice is kept in this browser (`localStorage`, `ownify_view`); a phone
never sees a switch. Switching changes only the layout — no reload, no
navigation: the page, an open second page and every scroll position stay.

## Desktop

- **The sidebar** is the dock, standing up (`components/app-dock.php`,
  `assets/css/desktop.css`): the name, the five tabs — the same chosen glass
  moving down them (`page-navigation.js`) — and at its foot OwnifyAI,
  Apparaten and the account, which the header's buttons and the handle open
  on a phone, and under them the view switch (`components/view-switch.php`;
  like the tabs, no glass of its own on the sidebar's, the sidebar's inset
  fill instead). Pressed again, they close what they opened. The red dots
  (docs/NOTICES.md) sit beside the sidebar's icons as beside the tab bar's.
- **No header.** Everything in it is in the sidebar, so it is not drawn and
  every page's title starts on the sidebar's top edge (29.2).
- **The page** stands beside the sidebar, at most `--page-max` (1600px) wide,
  with the same gap from the screen's right edge as the sidebar keeps from
  its left, in columns where they help — measured on the page's own width
  (container queries), so it re-flows when a second page opens:
  - Overzicht: the score beside the primary goal, the suggestion and the
    insights. From 960px Primair doel and Eén kleine suggestie side by side,
    one size, with Nuttige inzichten under them; narrower, the three under
    each other, Primair doel and Eén kleine suggestie still one height.
  - Doelen: the switch at its own width; the secondary goals in two
    columns. From 1100px the primary goal in a column of its own, as tall
    as the four others two by two beside it; every card at its own height.
    Afgerond: two, then (from 1100px) three columns.
  - Community: Ranglijst and Uitdagingen side by side, one height, at their
    own height; what they show centred in them, their buttons at the bottom.
  - Gezondheid: the three areas across, the Verloop under them; the height
    left is shared between the areas (up to 260px) and the Verloop's line.
  - Instellingen: one column (760px).
- **As tall as the sidebar (29.2).** Overzicht and Gezondheid end where
  the sidebar ends: their cards share the screen's height out, so the page
  and the sidebar close on one line. Content taller than the screen still
  just scrolls. Doelen and Community keep every card at its own height.
- **The second page.** A detail page (a goal, Slaap, Voeding, Training, a
  chart, a training, a settings page, the Ranglijst, Uitdagingen, a
  challenge, the Scorekompas), OwnifyAI, Nieuw doel, the account and
  Apparaten all open in one column on the right (544px), sliding in from the
  right — one at a time (`assets/js/desktop.js` closes the one in front).
  - From 1200px it stands **beside** the page, which makes room for it and
    stays usable; below that it **covers** the page (not the sidebar).
  - What opened it stays marked in the page (the chosen row's inset ring).
  - Opening another item swaps it with a short slide; a deeper page (a
    chart of Slaap) opens over it with a back pill; Escape and its own
    button close it. Leaving for another tab keeps it open.
  - Both pages show the same at all times: what the second page saves
    refreshes the page in place (`goals.js`, `settings.js`, `pull-sync.js`),
    as it always has.
  - The panels lose their scrim and are not modal beside the page.

## Phone-frame

Telefoon on a wide screen: the whole app — header, pages, tab bar, detail
pages, OwnifyAI — in a 430px column in the middle, exactly as on a phone; the
switch floats at the window's top right (the header's copy of
`view-switch.php`), the way back to Desktop.

## Not changed

The phone layout, the Android app, the panels that confirm or edit
(`settings-confirm`, `settings-editor`, `settings-pairing`, the challenge
sheet: still dialogs in the middle), and every component's look.
