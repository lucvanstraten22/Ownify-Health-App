# The desktop layout (web only)

The website has a layout for desktop and tablet screens next to
the phone's. The Android app has none (it is a phone app); what the desktop
changes on the phone too — the challenge card in the green Note — is in
docs/CHALLENGES.md.

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

- **The frame (30.0).** The sidebar and the page are one group, centred on
  the screen, at most 1760 × 1060 design pixels, with a quiet margin above
  and below (3.2% of the screen's height, 20–56px). The head's script sets
  `--frame-x/-y/-w/-h` and `--ui-scale` before anything is painted. The
  group is drawn at one scale (CSS `zoom` on the sidebar, the page and the
  second page): ×1 at 1920 × 1080, ×1.2 at 2560 × 1440, never more than
  ×1.25 and never less than ×1 — so on a larger, denser screen type keeps
  its size instead of shrinking. Nothing is stretched to the screen's
  height: every block keeps its own height.
  - Zoom and measuring: `getBoundingClientRect()` answers in screen pixels,
    `offsetWidth`/`offsetTop` in the element's own; scripts that place
    things from a rect divide by `k = rect.width / offsetWidth`
    (`page-navigation.js`, `chart-axis.js`, `nutrition.js`, and
    `hero-ring.js` draws its canvas at the zoomed density).
- **The sidebar** is the dock, standing up (`components/app-dock.php`,
  `assets/css/desktop.css`): the name, then one list of eight rows — the
  five tabs (the same chosen glass moving down them, `page-navigation.js`),
  OwnifyAI, Apparaten and the account — every row 40px, every icon in one
  24px column, every label on one edge; under them, in the middle, the view
  switch (`components/view-switch.php`; like the tabs, no glass of its own,
  the sidebar's inset fill instead). Pressed again, the last three close
  what they opened. The red dots (docs/NOTICES.md) sit beside its icons.
  - **It grows with the page (30.0).** Its bottom is where the page's last
    block ends (`desktop.js` sets `--nav-h`, measured without a block's
    slide-in): never shorter than its own rows, never taller than the
    frame, so a page that scrolls gets the frame's height. When there is
    room, OwnifyAI, Apparaten and the account stand at its foot; at its
    shortest they follow the tabs. Switching pages it eases to the new
    length (360ms, the strong ease-out; none with reduced motion).
- **No header.** Everything in it is in the sidebar, so it is not drawn and
  every page's title starts on the sidebar's top edge (29.2).
- **The page** fills the frame beside the sidebar (20px between them), in
  columns where they help — measured on the page's own width (container
  queries), so it re-flows when a second page opens. From 1100px:
  - Overzicht, in bands: the score as one band (the ring | what it is, its
    direction and lede | the three pillar rings); Primair doel | Eén kleine
    suggestie; Nuttige inzichten with its three insights side by side.
  - Gezondheid, three columns: Slaap, Voeding and Training under each other
    in the first (icon, name, the big score, its bar), the Verloop across
    the other two, as tall as the three.
  - Doelen, three columns: the primary goal beside the four others two by
    two, every card at its own height. Afgerond: three columns.
  - Community, three columns: the Ranglijst across two — Vrienden and
    Nederland side by side, each the six rows around your place (two above
    you and three below, five below when you lead; when you are not in its
    top, its top five and you), with "#2 van 5" (`hydrate_community_windows()`
    in `lib/hydrate-community.php`, the board's own rows) and "deze maand";
    Uitdagingen in the third, its buttons at the bottom. Narrower, and on a
    phone, the Ranglijst keeps its podium.
  - Between 760 and 1100px each page falls back to two columns or one.
  - Instellingen: one column (760px).
- **The second page.** A detail page (a goal, Slaap, Voeding, Training, a
  chart, a training, a settings page, the Ranglijst, Uitdagingen, a
  challenge, the Scorekompas, research), OwnifyAI, Nieuw doel, the account
  and Apparaten all open in one column at the frame's right (544px, at the
  frame's scale), sliding in from the right — one at a time
  (`assets/js/desktop.js` closes the one in front). Closed, they wait past
  the screen's edge by the frame's margin too (`nav.PARK_GAP`), so no sliver
  shows. Their rows are as wide as the column (`--content-width`), never the
  phone's width.
  - From 1200px it stands **beside** the page, which makes room for it and
    stays usable; below that it **covers** the page (not the sidebar).
  - What opened it stays marked in the page (the chosen card's inset ring;
    not on Overzicht's score band, which has no card edge for it).
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
