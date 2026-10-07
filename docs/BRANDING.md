# The Ownify logo

There are two official versions of the logo, supplied as finished artwork.
They are the source of truth: nothing in this repository redraws, recolours
or reshapes them. Everything the apps show is made from them by resizing and
placing only — `tools/brand-icons.py` — and can be made again the same way.

## The supplied files

Kept exactly as they were supplied, in `docs/brand/supplied/` (the `docs`
folder is never uploaded to the server):

| Version | File | What is in it |
| --- | --- | --- |
| Full logo | `ownify_v3.png` | The symbol on its dark-green tile, shown twice side by side: on a dark ground, and cut out on real transparency. 1254 × 1254, RGBA. SHA-256 `210f03ff…a0510a`. |
| Icon-only | `transparant_version_ownify_logo.png` | The symbol alone. 1254 × 1254, **RGB**: the "transparent" background is a light checkerboard in the pixels themselves, not transparency. SHA-256 `f22d09c1…3295cf`. |

## The icon-only master

`docs/brand/ownify-icon.png` (956 × 956, RGBA) is the icon-only logo on real
transparency, made from the supplied file by `tools/brand-icons.py`:

- every pixel of the symbol away from its edge is copied exactly;
- only the 1–2 px anti-aliased edge, where the symbol was blended with the
  checkerboard, is turned back into partial alpha. The checker under each
  edge pixel is a neutral grey, so how much of the pixel the symbol covers
  can be solved for. Laid back over that checker, the master reproduces the
  supplied file to 0.21/255 on average at its edges;
- the canvas is square and centred on the symbol.

Every other file is resized from this master once (premultiplied alpha,
Lanczos), never from another derivative.

## What is made, and where it is used

| Where | File | Size | Behind the symbol |
| --- | --- | --- | --- |
| Browser tab | `favicon.ico` (web root) | 16, 32, 48 | transparent |
| Icon link (Chrome's home-screen shortcut, large tab icons) | `assets/brand/ownify-icon-192.png` | 192 | transparent |
| iPhone home screen | `assets/brand/apple-touch-icon.png` | 180 | the logo's green — iOS shows transparency as black |
| Opening screen, website | `assets/brand/ownify-icon.webp` | 512 | transparent |
| Opening screen, app | `OwnifyAndroid/app/src/main/res/drawable-nodpi/ownify_logo.webp` | 640 | transparent |
| Launcher icon: adaptive foreground, and the monochrome layer | `OwnifyAndroid/app/src/main/res/mipmap-*/ic_launcher_foreground.webp` | 108 dp at each density | transparent; the background layer is `@color/ownify_logo_ground` |
| Play Store listing | `OwnifyAndroid/app/src/main/ic_launcher-playstore.png` | 512, 32-bit PNG | the logo's green, full square — Play rounds the corners itself |

The links are in `components/document-head.php`, shared by every page. The
Android launcher is `res/mipmap-anydpi/ic_launcher.xml` and
`ic_launcher_round.xml`.

On the opening screens the logo takes the place of the old ring and glass
orb, at the ring's size (89.4 % of the mark) and nothing else on the screen
moves. There it is decorative: the name under it already says "Ownify", so
it is not announced twice. The header keeps "Ownify" as text, as it was.

## App icons: the logo on its own green

Where a platform needs an opaque icon — Android's adaptive background layer,
the Play Store, an iPhone home screen — the symbol stands on **`#101C15`**,
the full logo's own dark green (the median of its tile behind the symbol),
never on an invented colour. Everywhere else it keeps its transparency.

- **Centred**: the symbol's centre — the middle of its outer ring — is on the
  canvas centre, to within half a pixel, in every file.
- **Size**: the symbol's farthest point, the star's tip, is 32.5 dp from the
  centre of the 72 dp icon a launcher shows: inside Android's 66 dp safe
  zone, so no launcher mask (circle, squircle, rounded square, teardrop…) can
  clip it. That makes the symbol 77.9 % of the icon's height — close to the
  full logo, where it is about 80 % of its tile. The Play Store and iPhone
  icons use the same proportion.
- **Themed icons** (Android 13+): the monochrome layer is the same picture;
  the system uses only its shape, in the theme's colours, and only when the
  person has themed icons on.
- **Splash** (Android 12+): no splash attributes are set, so the system shows
  the launcher icon on the window's colour (`Theme.Ownify` /
  `Theme.Ownify.Light`).

`ui/BrandIconTest` checks the launcher icon is this adaptive icon on this
green, and that at every density the symbol is centred and everything
visible is inside the safe zone. With `-Downify.shots=<dir>` it also draws
the icon through `AdaptiveIconDrawable` under the common mask shapes, the
themed version, and the splash, for looking at.

## The full logo

Kept as supplied and not used anywhere in the apps: nothing in them calls
for a full brand mark — the header and the opening screen write "Ownify" as
text, and every icon is the icon-only version by design.

Its symbol is not centred on its tile. Measured from the outer edge of the
ring, it is 5.9 px (1.1 %) right of centre on the 531 px tile, and 11.4 px
(2.2 %) below the centre of the tile's square face — vertically it is
centred only if the tile's thicker bottom edge is counted. Centring it would
mean repainting the tile's lighting under and around the symbol — its soft
shadow runs out into the tile's bevel — which is redrawing the logo, so it
is left as it is. A centred export from the design file is the fix. Nothing
the apps show uses it: every version on dark green is the icon-only symbol,
centred on the logo's green (to within half a pixel).

## Changing the logo

1. Put the new supplied files in `docs/brand/supplied/` — or, given a real
   transparent export of the icon, put it at `docs/brand/ownify-icon.png`.
2. `pip install pillow numpy scipy`, then `python3 tools/brand-icons.py`
   (or `--keep-master` to package a master put there by hand). It prints each
   file's size and how far its symbol is from the centre.
3. `./gradlew :app:testDebugUnitTest --tests '*BrandIconTest*' -Downify.shots=<dir>`
   in `OwnifyAndroid/`, and look at the opening screen on both apps, in
   Dark and White Mode.
