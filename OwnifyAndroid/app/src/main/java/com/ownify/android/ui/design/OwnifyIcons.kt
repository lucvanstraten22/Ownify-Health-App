package com.ownify.android.ui.design

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
object OwnifyIcons {

    /** `device` */
    val device: ImageVector by lazy {
        icon("device", Optical(0.968f, 12f, 12f),
            "M10 6H14A3 3 0 0 1 17 9V15A3 3 0 0 1 14 18H10A3 3 0 0 1 7 15V9A3 3 0 0 1 10 6Z",
            "M10 6V3.6h4V6M10 18v2.4h4V18",
            "M12 10.6v2.2l1.4 1"
        )
    }

    /** `user` */
    val user: ImageVector by lazy {
        icon("user", Optical(1.0331f, 12f, 12.71f),
            "M8.8 9a3.2 3.2 0 1 0 6.4 0a3.2 3.2 0 1 0 -6.4 0Z",
            "M5.6 19.6a6.6 6.6 0 0 1 12.8 0"
        )
    }

    /** `user-plus` */
    val userPlus: ImageVector by lazy {
        icon("user-plus", null,
            "M6.2 9a3.2 3.2 0 1 0 6.4 0a3.2 3.2 0 1 0 -6.4 0Z",
            "M3 19.6a6.4 6.4 0 0 1 12.8 0",
            "M19.2 8.2v5.2M16.6 10.8h5.2"
        )
    }

    /** `heart` */
    val heart: ImageVector by lazy {
        icon("heart", Optical(0.8878f, 12f, 11.42f),
            "M20.3 4.9a5 5 0 0 0-7.1 0L12 6.1l-1.2-1.2a5 5 0 1 0-7.1 7.1l1.2 1.2L12 19.4l7.1-6.2 1.2-1.2a5 5 0 0 0 0-7.1Z"
        )
    }

    /** `community` */
    val community: ImageVector by lazy {
        icon("community", Optical(1.037f, 12.15f, 12.67f),
            "M6.3 9.4a2.9 2.9 0 1 0 5.8 0a2.9 2.9 0 1 0 -5.8 0Z",
            "M3.8 19.5a5.4 5.4 0 0 1 10.8 0",
            "M14.7 8a2.2 2.2 0 1 0 4.4 0a2.2 2.2 0 1 0 -4.4 0Z",
            "M16.4 13.3a4.6 4.6 0 0 1 3.8 6.2"
        )
    }

    /** `moon` */
    val moon: ImageVector by lazy {
        icon("moon", null,
            "M20.2 14.4A8.4 8.4 0 0 1 9.6 3.8a8.4 8.4 0 1 0 10.6 10.6Z"
        )
    }

    /** `utensils` */
    val utensils: ImageVector by lazy {
        icon("utensils", null,
            "M5.2 3.6v5.4a2.6 2.6 0 0 0 5.2 0V3.6",
            "M7.8 3.6v16.8",
            "M18.6 3.6v16.8",
            "M18.6 3.6c-2.3 1.4-3.6 4-3.6 7.3 0 2 1.1 3.3 3.6 3.7"
        )
    }

    /** `bolt` */
    val bolt: ImageVector by lazy {
        icon("bolt", null,
            "M13.2 2.8 5.6 13.2h5.3l-.9 8 7.6-10.4h-5.3l.9-8Z"
        )
    }

    /** `dumbbell` */
    val dumbbell: ImageVector by lazy {
        icon("dumbbell", null,
            "M7 6.6v10.8M17 6.6v10.8M3.2 9.4v5.2M20.8 9.4v5.2M7 12h10"
        )
    }

    /** `rings` */
    val rings: ImageVector by lazy {
        icon("rings", Optical(0.9336f, 12f, 12f),
            "M3.6 12a8.4 8.4 0 1 0 16.8 0a8.4 8.4 0 1 0 -16.8 0Z",
            "M9.6 12a2.4 2.4 0 1 0 4.8 0a2.4 2.4 0 1 0 -4.8 0Z"
        )
    }

    /** `sliders` */
    val sliders: ImageVector by lazy {
        icon("sliders", Optical(1.1601f, 12f, 12f),
            "M4 8.5h8.5M17.5 8.5H20M4 15.5h3.5M12.5 15.5H20",
            "M12.8 8.5a2.2 2.2 0 1 0 4.4 0a2.2 2.2 0 1 0 -4.4 0Z",
            "M7.8 15.5a2.2 2.2 0 1 0 4.4 0a2.2 2.2 0 1 0 -4.4 0Z"
        )
    }

    /** `pulse` */
    val pulse: ImageVector by lazy {
        icon("pulse", null,
            "M3 12.2h4.2l2.3-5.8 3.6 11.4 2.3-5.6H21"
        )
    }

    /** `chart` */
    val chart: ImageVector by lazy {
        icon("chart", null,
            "M4 4.5v15h15.5",
            "M7.6 15.4 11 11.2l2.9 2.4 4.4-6"
        )
    }

    /** `flag` */
    val flag: ImageVector by lazy {
        icon("flag", Optical(1.0027f, 12.75f, 12.29f),
            "M6 20.6V4",
            "M6 5c4.5-2 9 2 13.5 0v8.6c-4.5 2-9-2-13.5 0Z"
        )
    }

    /** `sparkle` */
    val sparkle: ImageVector by lazy {
        icon("sparkle", null,
            "M12 3.6 13.7 9 19 10.8 13.7 12.6 12 18l-1.7-5.4L5 10.8 10.3 9 12 3.6Z",
            "M18.4 17.2l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z"
        )
    }

    /** `ranking` */
    val ranking: ImageVector by lazy {
        icon("ranking", null,
            "M6 20v-4.6M12 20V7.8M18 20v-8.4"
        )
    }

    /** `award` */
    val award: ImageVector by lazy {
        icon("award", null,
            "M6.8 9a5.2 5.2 0 1 0 10.4 0a5.2 5.2 0 1 0 -10.4 0Z",
            "M8.6 13.4 7.4 20.6l4.6-2.5 4.6 2.5-1.2-7.2"
        )
    }

    /** `chevron` */
    val chevron: ImageVector by lazy {
        icon("chevron", null,
            "M6.8 14.4 12 9.2l5.2 5.2"
        )
    }

    /** `chevron-down` */
    val chevronDown: ImageVector by lazy {
        icon("chevron-down", null,
            "M6.8 9.6 12 14.8l5.2-5.2"
        )
    }

    /** `chevron-left` */
    val chevronLeft: ImageVector by lazy {
        icon("chevron-left", null,
            "M14.4 6.8 9.2 12l5.2 5.2"
        )
    }

    /** `chevron-right` */
    val chevronRight: ImageVector by lazy {
        icon("chevron-right", null,
            "M9.6 6.8 14.8 12l-5.2 5.2"
        )
    }

    /** `lock` */
    val lock: ImageVector by lazy {
        icon("lock", null,
            "M7.6 10.5H16.4A2.6 2.6 0 0 1 19 13.1V17.4A2.6 2.6 0 0 1 16.4 20H7.6A2.6 2.6 0 0 1 5 17.4V13.1A2.6 2.6 0 0 1 7.6 10.5Z",
            "M8.4 10.5V8.2a3.6 3.6 0 0 1 7.2 0v2.3"
        )
    }

    /** `plus` */
    val plus: ImageVector by lazy {
        icon("plus", null,
            "M12 5.4v13.2M5.4 12h13.2"
        )
    }

    /** `check` */
    val check: ImageVector by lazy {
        icon("check", null,
            "M5 12.6 9.8 17.4 19 6.9"
        )
    }

    /** `pause` */
    val pause: ImageVector by lazy {
        icon("pause", null,
            "M9.4 5.6v12.8M14.6 5.6v12.8"
        )
    }

    /** `play` */
    val play: ImageVector by lazy {
        icon("play", null,
            "M8.4 5.6 18 12l-9.6 6.4V5.6Z"
        )
    }

    /** `trash` */
    val trash: ImageVector by lazy {
        icon("trash", null,
            "M4.6 7.2h14.8",
            "M9.4 7.2V5.4a1.4 1.4 0 0 1 1.4-1.4h2.4a1.4 1.4 0 0 1 1.4 1.4v1.8",
            "M6.8 7.2 7.7 19a1.6 1.6 0 0 0 1.6 1.5h5.4a1.6 1.6 0 0 0 1.6-1.5l.9-11.8"
        )
    }

    /** `shield` */
    val shield: ImageVector by lazy {
        icon("shield", null,
            "M12 3.4 19.4 6.1v5.8c0 4.2-3 7.4-7.4 8.7-4.4-1.3-7.4-4.5-7.4-8.7V6.1L12 3.4Z"
        )
    }

    /** `bell` */
    val bell: ImageVector by lazy {
        icon("bell", null,
            "M18 9.8a6 6 0 1 0-12 0c0 4.8-1.7 6.2-1.7 6.2h15.4S18 14.6 18 9.8Z",
            "M13.9 19.2a2.1 2.1 0 0 1-3.8 0"
        )
    }

    /** `globe` */
    val globe: ImageVector by lazy {
        icon("globe", null,
            "M3.6 12a8.4 8.4 0 1 0 16.8 0a8.4 8.4 0 1 0 -16.8 0Z",
            "M3.6 12h16.8",
            "M12 3.6a13.2 13.2 0 0 1 0 16.8 13.2 13.2 0 0 1 0-16.8Z"
        )
    }

    /** `ruler` */
    val ruler: ImageVector by lazy {
        icon("ruler", null,
            "M4.6 8.8H19.4A1.8 1.8 0 0 1 21.2 10.6V13.4A1.8 1.8 0 0 1 19.4 15.2H4.6A1.8 1.8 0 0 1 2.8 13.4V10.6A1.8 1.8 0 0 1 4.6 8.8Z",
            "M7.4 8.8v2.8M11 8.8v4M14.6 8.8v2.8M18.2 8.8v4"
        )
    }

    /** `calendar` */
    val calendar: ImageVector by lazy {
        icon("calendar", null,
            "M6.6 5.6H17.4A2.6 2.6 0 0 1 20 8.2V17.8A2.6 2.6 0 0 1 17.4 20.4H6.6A2.6 2.6 0 0 1 4 17.8V8.2A2.6 2.6 0 0 1 6.6 5.6Z",
            "M8.4 3.6v3.8M15.6 3.6v3.8M4 10.6h16"
        )
    }

    /** `accessibility` */
    val accessibility: ImageVector by lazy {
        icon("accessibility", null,
            "M10.1 4.8a1.9 1.9 0 1 0 3.8 0a1.9 1.9 0 1 0 -3.8 0Z",
            "M4.8 8.6h14.4",
            "M12 8.6v5M12 13.6 9.2 20.4M12 13.6l2.8 6.8"
        )
    }

    /** `info` */
    val info: ImageVector by lazy {
        icon("info", null,
            "M3.6 12a8.4 8.4 0 1 0 16.8 0a8.4 8.4 0 1 0 -16.8 0Z",
            "M12 11.2v5.1",
            "M12 7.9v.1"
        )
    }

    /** `logout` */
    val logout: ImageVector by lazy {
        icon("logout", null,
            "M9.8 20.4H5.8a1.8 1.8 0 0 1-1.8-1.8V5.4a1.8 1.8 0 0 1 1.8-1.8h4",
            "M15.4 16.4 19.8 12l-4.4-4.4",
            "M19.8 12H9.4"
        )
    }

    /** `sync` */
    val sync: ImageVector by lazy {
        icon("sync", null,
            "M20.2 11.2a8.2 8.2 0 0 0-14-4.5L4 8.9",
            "M3.8 12.8a8.2 8.2 0 0 0 14 4.5l2.2-2.2",
            "M4 4.6v4.3h4.3M20 19.4v-4.3h-4.3"
        )
    }

    /** By the website's own name, for icons named in the server's data ("moon", "utensils", …). */
    val byName: Map<String, ImageVector> by lazy {
        mapOf(
        "device" to device,
        "user" to user,
        "user-plus" to userPlus,
        "heart" to heart,
        "community" to community,
        "moon" to moon,
        "utensils" to utensils,
        "bolt" to bolt,
        "dumbbell" to dumbbell,
        "rings" to rings,
        "sliders" to sliders,
        "pulse" to pulse,
        "chart" to chart,
        "flag" to flag,
        "sparkle" to sparkle,
        "ranking" to ranking,
        "award" to award,
        "chevron" to chevron,
        "chevron-down" to chevronDown,
        "chevron-left" to chevronLeft,
        "chevron-right" to chevronRight,
        "lock" to lock,
        "plus" to plus,
        "check" to check,
        "pause" to pause,
        "play" to play,
        "trash" to trash,
        "shield" to shield,
        "bell" to bell,
        "globe" to globe,
        "ruler" to ruler,
        "calendar" to calendar,
        "accessibility" to accessibility,
        "info" to info,
        "logout" to logout,
        "sync" to sync,
        )
    }

    /** The icon the server names, or null for a name this build does not know. */
    fun named(name: String?): ImageVector? = name?.let { byName[it] }
}

/** [scale] about ([cx], [cy]), then back to the grid's centre — `translate(12 12) scale(s) translate(-cx -cy)`. */
internal class Optical(val scale: Float, val cx: Float, val cy: Float)

private const val STROKE = 1.6f

private fun icon(name: String, optical: Optical?, vararg paths: String): ImageVector {
    val builder = ImageVector.Builder(
        name = "ownify.$name",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f
    )

    if (optical != null) {
        builder.addGroup(
            name = "optical",
            pivotX = optical.cx,
            pivotY = optical.cy,
            scaleX = optical.scale,
            scaleY = optical.scale,
            translationX = 12f - optical.cx,
            translationY = 12f - optical.cy
        )
    }

    val width = if (optical != null) STROKE / optical.scale else STROKE

    for (data in paths) {
        builder.addPath(
            pathData = addPathNodes(data),
            fill = null,
            stroke = SolidColor(Color.Black),
            strokeLineWidth = width,
            strokeLineCap = StrokeCap.Round,
            strokeLineJoin = StrokeJoin.Round
        )
    }

    if (optical != null) {
        builder.clearGroup()
    }

    return builder.build()
}
