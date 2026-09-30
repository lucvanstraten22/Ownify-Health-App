#!/usr/bin/env python3
"""
Generates OwnifyIcons.kt from components/icons.php: every icon of the web app as
a Compose ImageVector on the same 24 grid, 1.6 stroke, round caps and joins,
with the same optical normalisation ([scale, cx, cy]) for the tab and header
icons. <circle> and <rect rx> become the equivalent path data.

Run from the repository root after changing components/icons.php:

    python3 OwnifyAndroid/tools/gen_icons.py components/icons.php \
        OwnifyAndroid/app/src/main/java/com/ownify/android/ui/design/OwnifyIcons.kt
"""
import re
import sys

src = open(sys.argv[1]).read()
out_path = sys.argv[2]

block = src[src.index("$icons = ["):src.index("];", src.index("$icons = ["))]
entries = re.findall(r"'([a-z-]+)'\s*=>\s*((?:'[^']*'\s*(?:\.\s*)?)+),", block)

solid_block = src[src.index("static $solid = ["):src.index("];", src.index("static $solid = ["))]
solid_entries = re.findall(r"'([a-z-]+)'\s*=>\s*((?:'[^']*'\s*(?:\.\s*)?)+),", solid_block)

optical_block = src[src.index("static $optical = ["):src.index("];", src.index("static $optical = ["))]
optical = {m[0]: (float(m[1]), float(m[2]), float(m[3]))
           for m in re.findall(r"'([a-z-]+)'\s*=>\s*\[([\d.]+),\s*([\d.]+),\s*([\d.]+)\]", optical_block)}


def attrs(tag):
    return dict(re.findall(r'([a-z-]+)="([^"]*)"', tag))


def fmt(v):
    v = round(v, 4)
    return ("%s" % v).rstrip("0").rstrip(".") if "." in ("%s" % v) else "%s" % v


def element_paths(body):
    paths = []
    for tag in re.findall(r"<(?:path|circle|rect)[^>]*/>", body):
        a = attrs(tag)
        if tag.startswith("<path"):
            paths.append(a["d"])
        elif tag.startswith("<circle"):
            cx, cy, r = float(a["cx"]), float(a["cy"]), float(a["r"])
            paths.append(f"M{fmt(cx - r)} {fmt(cy)}a{fmt(r)} {fmt(r)} 0 1 0 {fmt(2 * r)} 0a{fmt(r)} {fmt(r)} 0 1 0 {fmt(-2 * r)} 0Z")
        else:
            x, y, w, h = float(a["x"]), float(a["y"]), float(a["width"]), float(a["height"])
            rx = float(a.get("rx", "0"))
            paths.append(
                f"M{fmt(x + rx)} {fmt(y)}H{fmt(x + w - rx)}A{fmt(rx)} {fmt(rx)} 0 0 1 {fmt(x + w)} {fmt(y + rx)}"
                f"V{fmt(y + h - rx)}A{fmt(rx)} {fmt(rx)} 0 0 1 {fmt(x + w - rx)} {fmt(y + h)}"
                f"H{fmt(x + rx)}A{fmt(rx)} {fmt(rx)} 0 0 1 {fmt(x)} {fmt(y + h - rx)}"
                f"V{fmt(y + rx)}A{fmt(rx)} {fmt(rx)} 0 0 1 {fmt(x + rx)} {fmt(y)}Z")
    return paths


def camel(name):
    parts = name.split("-")
    return parts[0] + "".join(p.capitalize() for p in parts[1:])


lines = []
names = []
for name, raw in entries:
    body = "".join(re.findall(r"'([^']*)'", raw))
    paths = element_paths(body)
    names.append(name)
    ident = camel(name)
    lines.append(f"    /** `{name}` */")
    lines.append(f"    val {ident}: ImageVector by lazy {{")
    if name in optical:
        s, cx, cy = optical[name]
        lines.append(f'        icon("{name}", Optical({fmt(s)}f, {fmt(cx)}f, {fmt(cy)}f),')
    else:
        lines.append(f'        icon("{name}", null,')
    for i, p in enumerate(paths):
        sep = "," if i < len(paths) - 1 else ""
        lines.append(f'            "{p}"{sep}')
    lines.append("        )")
    lines.append("    }")
    lines.append("")

solid_lines = []
solid_names = []
for name, raw in solid_entries:
    body = "".join(re.findall(r"'([^']*)'", raw))
    solid_names.append(name)
    solid_lines.append(f"    /** Solid `{name}` (icon_solid()) */")
    solid_lines.append(f"    val {camel('solid-' + name)}: ImageVector by lazy {{")
    solid_lines.append(f'        solidIcon("{name}",')
    tags = re.findall(r"<(?:path|circle|rect)[^>]*/>", body)
    for i, tag in enumerate(tags):
        a = attrs(tag)
        d = element_paths(tag)[0]
        line = a.get("stroke-width") if a.get("fill") == "none" else None
        sep = "," if i < len(tags) - 1 else ""
        solid_lines.append(f'            Part("{d}", {line}f){sep}' if line else f'            Part("{d}"){sep}')
    solid_lines.append("        )")
    solid_lines.append("    }")
    solid_lines.append("")

solid_by_name = "\n".join(f'        "{n}" to {camel("solid-" + n)},' for n in solid_names)

by_name = "\n".join(f'        "{n}" to {camel(n)},' for n in names)

kotlin = f'''package com.ownify.android.ui.design

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.addPathNodes
import androidx.compose.ui.unit.dp

/**
 * The Ownify icon set — generated from the website's components/icons.php by
 * OwnifyAndroid/tools/gen_icons.py (do not edit by hand), so the two draw
 * exactly the same marks: a 24 grid, a 1.6 stroke, round caps
 * and joins, no fills. <circle> and <rect> are written out as the same shapes
 * in path data.
 *
 * The tab and header icons carry the website's optical normalisation
 * ([Optical]: scale about the ink's own centre, then the stroke divided back
 * out so the line weight stays 1.6), measured there off the rendered ink.
 *
 * Drawn in black; [OwnifyIcon] tints them with the text colour, as
 * `stroke="currentColor"` does on the website.
 *
 * GENERATED — regenerate rather than edit (see OwnifyAndroid/README.md).
 */
object OwnifyIcons {{

{chr(10).join(lines)}
    /** By the website's own name, for icons named in the server's data ("moon", "utensils", …). */
    val byName: Map<String, ImageVector> by lazy {{
        mapOf(
{by_name}
        )
    }}

    /** The icon the server names, or null for a name this build does not know. */
    fun named(name: String?): ImageVector? = name?.let {{ byName[it] }}

{chr(10).join(solid_lines)}
    val solidByName: Map<String, ImageVector> by lazy {{
        mapOf(
{solid_by_name}
        )
    }}

    /**
     * `icon_solid()`: the solid version, for a coloured tile — its outline
     * where the website has no solid one; null for a name this build does not know.
     */
    fun solid(name: String?): ImageVector? = name?.let {{ solidByName[it] ?: byName[it] }}
}}

/** [scale] about ([cx], [cy]), then back to the grid's centre — `translate(12 12) scale(s) translate(-cx -cy)`. */
internal class Optical(val scale: Float, val cx: Float, val cy: Float)

private const val STROKE = 1.6f

private fun icon(name: String, optical: Optical?, vararg paths: String): ImageVector {{
    val builder = ImageVector.Builder(
        name = "ownify.$name",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f
    )

    if (optical != null) {{
        builder.addGroup(
            name = "optical",
            pivotX = optical.cx,
            pivotY = optical.cy,
            scaleX = optical.scale,
            scaleY = optical.scale,
            translationX = 12f - optical.cx,
            translationY = 12f - optical.cy
        )
    }}

    val width = if (optical != null) STROKE / optical.scale else STROKE

    for (data in paths) {{
        builder.addPath(
            pathData = addPathNodes(data),
            fill = null,
            stroke = SolidColor(Color.Black),
            strokeLineWidth = width,
            strokeLineCap = StrokeCap.Round,
            strokeLineJoin = StrokeJoin.Round
        )
    }}

    if (optical != null) {{
        builder.clearGroup()
    }}

    return builder.build()
}}

/** One shape of a solid icon: filled, or — [line] > 0 — a line of that width. */
private class Part(val data: String, val line: Float = 0f)

private fun solidIcon(name: String, vararg parts: Part): ImageVector {{
    val builder = ImageVector.Builder(
        name = "ownify.solid.$name",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f
    )
    for (part in parts) {{
        if (part.line > 0f) {{
            builder.addPath(
                pathData = addPathNodes(part.data),
                fill = null,
                stroke = SolidColor(Color.Black),
                strokeLineWidth = part.line,
                strokeLineCap = StrokeCap.Round,
                strokeLineJoin = StrokeJoin.Round
            )
        }} else {{
            builder.addPath(pathData = addPathNodes(part.data), fill = SolidColor(Color.Black))
        }}
    }}
    return builder.build()
}}
'''
open(out_path, "w").write(kotlin)
print(f"{len(names)} icons, {len(optical)} optical, {len(solid_names)} solid")
