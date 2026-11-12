package com.ownify.android.ui

import com.ownify.android.ui.design.evenTicks
import com.ownify.android.ui.screens.health.splitUnit
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 50.0 chart conventions (docs/CHARTS.md): dates never collide; units apart from their number. */
class ChartConventionsTest {

    private fun lefts(n: Int, step: Float) = (0 until n).map { it * step }

    @Test fun everyDateWhereTheyAllFit() {
        val shown = evenTicks(lefts(5, 60f), List(5) { 40 }, 4f)
        assertArrayEquals(BooleanArray(5) { true }, shown)
    }

    @Test fun everySecondDateWhereTheyDoNotBothEndsStand() {
        // 7 dates 30 apart, 40 wide: each would touch the next.
        val shown = evenTicks(lefts(7, 30f), List(7) { 40 }, 4f)
        assertArrayEquals(booleanArrayOf(true, false, true, false, true, false, true), shown)
    }

    @Test fun theOneBeforeTheEndStepsAsideWhenOnlyItIsTooClose() {
        // 0, 50, 100, 120 (the end pushed in): every date clears its neighbour but 100 and 120.
        val shown = evenTicks(listOf(0f, 50f, 100f, 120f), List(4) { 40 }, 4f)
        assertArrayEquals(booleanArrayOf(true, true, false, true), shown)
    }

    @Test fun aDaysHoursThinFromTheFirstWithoutAFixedEnd() {
        // 8 times (00:00 … 21:00), 40 apart, 36 wide: every second, 21:00 off the stride.
        val shown = evenTicks(lefts(8, 40f), List(8) { 36 }, 8f, freeEnd = true)
        assertArrayEquals(booleanArrayOf(true, false, true, false, true, false, true, false), shown)
    }

    @Test fun theUnitStandsApartFromItsNumber() {
        assertEquals("9.956" to "stappen", splitUnit("9.956 stappen"))
        assertEquals("7,5" to "km", splitUnit("7,5 km"))
        assertEquals("7:32" to "u", splitUnit("7:32 u"))
        assertNull(splitUnit("83%"))
        assertNull(splitUnit("—"))
    }
}
