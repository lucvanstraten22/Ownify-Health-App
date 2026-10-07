#!/usr/bin/env python3
"""
The Ownify logo, packaged for every place the apps show it.

    pip install pillow numpy scipy
    python3 tools/brand-icons.py                # the master, then every derivative
    python3 tools/brand-icons.py --keep-master  # every derivative from the master as it is
                                                # (e.g. a real transparent export put there)
    python3 tools/brand-icons.py --check        # only report the master's geometry

The supplied artwork is the source of truth (docs/BRANDING.md). This script
never draws, recolours or reshapes it: it only

  1. makes the icon-only master, docs/brand/ownify-icon.png, from the supplied
     icon-only file. That file is RGB: its "transparent" background is a light
     checkerboard in the pixels themselves. Every pixel of the symbol away
     from its edge is copied exactly; only the 1-2 px anti-aliased edge,
     where the symbol was blended with the checker, is turned back into
     partial alpha (the checker beneath each edge pixel is a neutral grey,
     so its coverage can be solved for). The square canvas is centred on the
     symbol.
  2. resizes that master once per output - premultiplied alpha, Lanczos -
     onto a canvas, the symbol's centre on the canvas centre to the
     sub-pixel. Where a platform needs an opaque icon (Android's adaptive
     background, the Play Store, iOS), the ground behind the symbol is the
     full logo's own dark green (LOGO_GROUND), never an invented colour.

Every output is measured afterwards: its size, and how far the symbol's
centre is from the canvas centre.
"""

import argparse
import os
import sys

import numpy as np
from PIL import Image
from scipy import ndimage as ndi

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SUPPLIED_ICON = os.path.join(ROOT, 'docs/brand/supplied/transparant_version_ownify_logo.png')
MASTER = os.path.join(ROOT, 'docs/brand/ownify-icon.png')

# The full logo's tile, behind its symbol: the median of its ground pixels in
# docs/brand/supplied/ownify_v3.png (16, 28, 21). Used only where a platform
# requires an opaque icon.
LOGO_GROUND = (0x10, 0x1C, 0x15)

# App icons: the symbol's farthest point (the star's tip) at 32.5 dp from the
# centre of the 72 dp visible icon, just inside Android's 66 dp safe zone, so
# no launcher mask can clip it. The same proportion everywhere an app icon is
# made, so the launcher, the Play Store and an iPhone home screen agree.
SAFE_RADIUS_DP = 32.5
VISIBLE_DP = 72.0
LAYER_DP = 108.0

ANDROID = os.path.join(ROOT, 'OwnifyAndroid/app/src/main')
DENSITIES = {'mdpi': 1.0, 'hdpi': 1.5, 'xhdpi': 2.0, 'xxhdpi': 3.0, 'xxxhdpi': 4.0}


# ---------------------------------------------------------------- master

def make_master(src_path: str, dst_path: str) -> None:
    C = np.asarray(Image.open(src_path).convert('RGB')).astype(np.float64)
    H, W, _ = C.shape
    chroma = C.max(2) - C.min(2)
    lum = C.mean(2)

    # The checkerboard: neutral and light (its squares are ~233 and ~254).
    neutral_light = (chroma <= 6) & (lum >= 222)
    other = ~neutral_light
    lab, n = ndi.label(other, structure=np.ones((3, 3)))
    sizes = ndi.sum(other, lab, index=np.arange(1, n + 1))
    keep = np.zeros(n + 1, bool)
    keep[1:] = sizes >= 500
    S = keep[lab]                       # the symbol's parts
    stray = other & ~S                  # e.g. a darker line along the bottom edge

    dist_in = ndi.distance_transform_edt(S)
    dist_out = ndi.distance_transform_edt(~S)
    core = S & (dist_in >= 2.5)
    band = (S & (dist_in < 2.5)) | (~S & (dist_out <= 1.5))
    bg_src = ~S & (dist_out >= 3) & neutral_light

    # An edge pixel is a blend of the symbol's outermost colour - its 1 px
    # dark rim - with the checker, so its symbol colour is its inner
    # neighbour's.
    rim_in = S & (dist_in >= 1.5)
    _, (cy, cx) = ndi.distance_transform_edt(~rim_in, return_indices=True)
    F = C[cy, cx]
    _, (by, bx) = ndi.distance_transform_edt(~bg_src, return_indices=True)
    B_near = C[by, bx]

    # C = alpha * F + (1 - alpha) * g * (1, 1, 1), solved for alpha and g.
    FF = (F * F).sum(2); FU = F.sum(2); FC = (F * C).sum(2); UC = C.sum(2)
    det = FF * 3.0 - FU * FU
    with np.errstate(divide='ignore', invalid='ignore'):
        a_ls = (3.0 * FC - FU * UC) / det
        b_ls = (FF * UC - FU * FC) / det
        g_ls = b_ls / (1.0 - a_ls)
    g = np.where(np.isfinite(g_ls) & (g_ls >= 226) & (g_ls <= 256), g_ls, B_near.mean(2))
    g = np.where(np.isfinite(a_ls) & (a_ls < 0.05), C.mean(2), g)
    g = np.clip(g, 226.0, 255.0)
    B = np.repeat(g[..., None], 3, axis=2)

    d = F - B
    den = (d * d).sum(2)
    alpha = np.zeros((H, W))
    alpha[core] = 1.0
    with np.errstate(divide='ignore', invalid='ignore'):
        est = np.clip(((C - B) * d).sum(2) / den, 0.0, 1.0)
    sep = den >= 400
    alpha[band & sep] = est[band & sep]
    alpha[band & ~sep] = S[band & ~sep].astype(float)
    alpha[stray] = 0.0

    out = F.copy()                      # transparent pixels carry the nearest symbol colour
    out[core] = C[core]
    with np.errstate(divide='ignore', invalid='ignore'):
        unmix = (C - (1.0 - alpha[..., None]) * B) / alpha[..., None]
    solid = band & (alpha >= 0.3)
    out[solid] = np.clip(unmix[solid], 0, 255)

    A8 = np.clip(np.rint(alpha * 255), 0, 255).astype(np.uint8)
    RGB8 = np.clip(np.rint(out), 0, 255).astype(np.uint8)
    rgba = np.dstack([RGB8, A8])

    ys, xs = np.nonzero(A8 > 0)
    x0, x1, y0, y1 = xs.min(), xs.max(), ys.min(), ys.max()
    side = int(max(x1 - x0 + 1, y1 - y0 + 1))
    left = int(round((x0 + x1) / 2 - (side - 1) / 2))
    top = int(round((y0 + y1) / 2 - (side - 1) / 2))
    canvas = np.zeros((side, side, 4), np.uint8)
    sx0, sy0, sx1, sy1 = max(left, 0), max(top, 0), min(left + side, W), min(top + side, H)
    canvas[sy0 - top:sy1 - top, sx0 - left:sx1 - left] = rgba[sy0:sy1, sx0:sx1]
    os.makedirs(os.path.dirname(dst_path), exist_ok=True)
    Image.fromarray(canvas, 'RGBA').save(dst_path, optimize=True)

    recomposed = alpha[..., None] * out + (1 - alpha[..., None]) * B
    err = np.abs(recomposed - C).max(2)[band & (alpha >= 0.05)]
    print(f'master  {rel(dst_path)}: {side} x {side}; {int(core.sum())} symbol pixels copied exactly '
          f'({bool((RGB8[core] == C[core].astype(np.uint8)).all())}), {int(band.sum())} edge pixels '
          f'(re-blended over the checker: mean error {err.mean():.2f}/255)')


# ---------------------------------------------------------------- placing

def symbol_geometry(master: Image.Image):
    """The symbol's centre and its farthest point, in master pixels (edges at 50% coverage)."""
    a = np.asarray(master)[..., 3] > 127
    ys, xs = np.nonzero(a)
    cx = (xs.min() + xs.max() + 1) / 2.0
    cy = (ys.min() + ys.max() + 1) / 2.0
    extent = float(max(xs.max() - xs.min() + 1, ys.max() - ys.min() + 1))
    reach = float(np.max(np.hypot(xs + 0.5 - cx, ys + 0.5 - cy)))
    return cx, cy, extent, reach


def place(master: Image.Image, size: int, scale: float, ground=None) -> Image.Image:
    """The master at `scale` output px per master px, its centre on the canvas centre."""
    cx, cy, _, _ = symbol_geometry(master)
    span = size / scale                              # master px covered by the canvas
    pad = int(np.ceil(span)) + 2
    padded = Image.new('RGBA', (master.width + 2 * pad, master.height + 2 * pad), (0, 0, 0, 0))
    padded.paste(master, (pad, pad))
    box = (cx + pad - span / 2, cy + pad - span / 2, cx + pad + span / 2, cy + pad + span / 2)
    # Pillow resamples RGBA premultiplied; Lanczos, with a proper reducing filter.
    layer = padded.resize((size, size), Image.LANCZOS, box=box)
    if ground is None:
        return layer
    out = Image.new('RGBA', (size, size), ground + (255,))
    return Image.alpha_composite(out, layer)


def centre_offset(img: Image.Image, ground=None):
    """How far the symbol's centre is from the canvas centre, in px (x, y)."""
    arr = np.asarray(img.convert('RGBA')).astype(int)
    if ground is None:
        m = arr[..., 3] > 127
    else:
        diff = np.abs(arr[..., :3] - np.array(ground)).sum(2)
        m = diff > 3 * 48                              # clearly the symbol, not the ground
    ys, xs = np.nonzero(m)
    w, h = img.size
    return ((xs.min() + xs.max() + 1) / 2.0 - w / 2.0, (ys.min() + ys.max() + 1) / 2.0 - h / 2.0)


def rel(path: str) -> str:
    return os.path.relpath(path, ROOT)


def save(img: Image.Image, path: str, ground=None, note: str = '') -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    if path.endswith('.webp'):
        img.save(path, 'WEBP', lossless=True, quality=100, method=6, exact=True)
    else:
        img.save(path, optimize=True)
    dx, dy = centre_offset(img, ground)
    print(f'{rel(path):70s} {img.width:4d} x {img.height:<4d} centre {dx:+.2f},{dy:+.2f} px  '
          f'{os.path.getsize(path) / 1024:6.1f} KB  {note}')


# ---------------------------------------------------------------- outputs

def build(master: Image.Image) -> None:
    cx, cy, extent, reach = symbol_geometry(master)
    print(f'symbol: {extent:.0f} px tall, centre ({cx:.1f}, {cy:.1f}), farthest point {reach:.1f} px from it')

    # App icons: px per master px, per dp of the visible icon.
    dp_per_px = SAFE_RADIUS_DP / reach
    share = extent * dp_per_px / VISIBLE_DP           # the symbol's height, as a share of the icon
    print(f'app icons: the symbol {share * 100:.1f}% of the icon\'s height; its farthest point at '
          f'{SAFE_RADIUS_DP} dp of the 33 dp safe radius')

    # ---- web
    fav = [place(master, n, n / extent) for n in (16, 32, 48)]
    path = os.path.join(ROOT, 'favicon.ico')
    fav[2].save(path, format='ICO', sizes=[(16, 16), (32, 32), (48, 48)], append_images=fav[:2])
    for f in fav:
        dx, dy = centre_offset(f)
        print(f'{rel(path) + f" [{f.width}]":70s} {f.width:4d} x {f.height:<4d} centre {dx:+.2f},{dy:+.2f} px')
    print(f'{"":70s} {"":11s} {"":21s} {os.path.getsize(path) / 1024:6.1f} KB  favicon (16, 32, 48), transparent')

    save(place(master, 192, 192 / extent), os.path.join(ROOT, 'assets/brand/ownify-icon-192.png'),
         note='icon link, transparent')
    save(place(master, 512, 512 / extent), os.path.join(ROOT, 'assets/brand/ownify-icon.webp'),
         note='opening screen, transparent')
    save(place(master, 180, 180 * share / extent, LOGO_GROUND),
         os.path.join(ROOT, 'assets/brand/apple-touch-icon.png'), LOGO_GROUND,
         note='iPhone home screen (iOS fills transparency black, so opaque)')

    # ---- Android
    for name, k in DENSITIES.items():
        n = int(round(LAYER_DP * k))
        save(place(master, n, dp_per_px * k), os.path.join(ANDROID, f'res/mipmap-{name}/ic_launcher_foreground.webp'),
             note='adaptive foreground + monochrome')
    save(place(master, 640, 640 / extent), os.path.join(ANDROID, 'res/drawable-nodpi/ownify_logo.webp'),
         note='opening screen, transparent')
    store = place(master, 512, 512 * share / extent, LOGO_GROUND)
    save(store, os.path.join(ANDROID, 'ic_launcher-playstore.png'), LOGO_GROUND,
         note='Play Store listing, full square, opaque (Play rounds it)')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--check', action='store_true', help="report the master's geometry without writing")
    parser.add_argument('--keep-master', action='store_true', help='skip making the master; package the one there')
    args = parser.parse_args()

    if args.check:
        master = Image.open(MASTER).convert('RGBA')
        print(symbol_geometry(master))
        return 0

    if not args.keep_master:
        if not os.path.exists(SUPPLIED_ICON):
            print(f'missing {rel(SUPPLIED_ICON)}', file=sys.stderr)
            return 1
        make_master(SUPPLIED_ICON, MASTER)
    build(Image.open(MASTER).convert('RGBA'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
