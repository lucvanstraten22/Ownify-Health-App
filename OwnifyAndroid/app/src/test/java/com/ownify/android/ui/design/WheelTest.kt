package com.ownify.android.ui.design

import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The wheel's columns (47.0), as assets/js/wheel-picker.js makes them. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class WheelTest {

    @Test
    fun `a duration - hours and minutes, each its own column, from 30 minutes`() {
        val spec = WheelSpec(kind = "duration", min = 1.0, max = 1440.0, start = 30.0)
        val empty = Wheel.columns(spec, null)
        assertEquals(listOf(0, 30), empty.map { it.values[it.index] })
        assertEquals(25, empty[0].values.size)        // 0 to 24 hours
        assertEquals(60, empty[1].values.size)
        val cols = Wheel.columns(spec, 105.0)
        assertEquals(listOf(1, 45), cols.map { it.values[it.index] })
        assertEquals("105", Wheel.written(spec, Wheel.valueOf(spec, cols, cols.map { it.index })))
        assertEquals("1440 at most", 1440.0, Wheel.valueOf(spec, cols, listOf(24, 30)), 0.0)
    }

    @Test
    fun `a number - the whole and its decimal, a long range in bigger steps`() {
        val weight = WheelSpec(min = 20.0, max = 400.0, decimals = 1, start = 70.0, unit = "kg")
        val cols = Wheel.columns(weight, 72.5)
        assertEquals(listOf(72, 5), cols.map { it.values[it.index] })
        assertEquals(72.5, Wheel.valueOf(weight, cols, cols.map { it.index }), 1e-9)
        assertEquals("kg", cols[1].unit)

        val kcal = Wheel.columns(WheelSpec(min = 0.0, max = 5000.0, step = 10.0, start = 300.0), null)
        assertEquals(501, kcal[0].values.size)
        assertEquals(300, kcal[0].values[kcal[0].index])

        val steps = Wheel.columns(WheelSpec(min = 0.0, max = 30000.0, start = 8000.0), null)
        assertEquals("steps of 100, never more than a thousand rows", 301, steps[0].values.size)
        assertEquals(8000, steps[0].values[steps[0].index])
    }
}
