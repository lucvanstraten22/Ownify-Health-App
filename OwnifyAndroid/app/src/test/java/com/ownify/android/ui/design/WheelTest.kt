package com.ownify.android.ui.design

import com.ownify.android.data.GoalUnit
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The wheel's columns (48.0), as assets/js/wheel-picker.js makes them. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class WheelTest {

    private fun at(cols: List<Wheel.Column>) = cols.map { it.labels[it.index] }

    @Test
    fun `a duration - hours and minutes, each its own column, from 30 minutes`() {
        val spec = WheelSpec(kind = "duration", min = 1.0, max = 1440.0, start = 30.0)
        assertEquals(listOf("0", "30"), at(Wheel.columns(spec, "")))
        val cols = Wheel.columns(spec, "105")
        assertEquals(listOf("1", "45"), at(cols))
        assertEquals("105", Wheel.written(spec, cols, cols.map { it.index }))
    }

    @Test
    fun `a time of day - hours colon minutes, no units`() {
        val spec = WheelSpec.clock("18:00")
        val cols = Wheel.columns(spec, "")
        assertEquals(listOf("18", "00"), at(cols))
        assertEquals(":", cols[0].sep)
        assertEquals("", cols[0].unit + cols[1].unit)
        assertEquals("23:59", Wheel.written(spec, cols, listOf(23, 59)))
    }

    @Test
    fun `a date - day, month, year, never after the last day`() {
        val spec = WheelSpec.date("2025-11-10", "2026-11-10")
        val cols = Wheel.columns(spec, "2026-02-14")
        assertEquals(listOf("14", "feb", "2026"), at(cols))
        assertEquals("February: 28 days", 28, cols[0].values.size)
        assertEquals("2026-11-10", Wheel.written(spec, cols, listOf(30, 11, 1)))     // 31 december 2026: back to today
        assertEquals("10 nov 2026", Wheel.shown(spec, "2026-11-10"))
    }

    @Test
    fun `steps per unit - 500 steps, half kilometres, a tenth of a kilo`() {
        val steps = Wheel.columns(Wheel.forUnit(GoalUnit("steps", "stappen", "stap", "steps", 1.0, 0, false, listOf(500.0, 50000.0, 500.0)), 8000.0), "")
        assertEquals(100, steps[0].values.size)
        assertEquals("8.000", steps[0].labels[steps[0].index])
        val km = Wheel.columns(Wheel.forUnit(GoalUnit("km", "km", "km", "km", 1.0, 1, false, listOf(0.5, 200.0, 0.5)), 5.0), "")
        assertEquals("5,0", km[0].labels[km[0].index])
        val kg = WheelSpec(min = 20.0, max = 250.0, step = 0.1, start = 72.5, unit = "kg")
        val cols = Wheel.columns(kg, "")
        assertEquals(listOf("72", ",5"), at(cols))
        assertEquals("72,5", Wheel.written(kg, cols, cols.map { it.index }))
        val month = Wheel.forUnit(GoalUnit("sessions", "trainingen", "training", "sessions", 1.0, 0, false, listOf(1.0, 30.0, 1.0)), 60.0)
        assertEquals("a total above the top: room to twice it", 120.0, month.max, 0.0)
    }

    @Test
    fun `a list - one column of choices`() {
        val spec = WheelSpec.list(listOf("running" to "Hardlopen", "cycling" to "Fietsen"))
        val cols = Wheel.columns(spec, "cycling")
        assertEquals(listOf("Fietsen"), at(cols))
        assertEquals("running", Wheel.written(spec, cols, listOf(0)))
        assertEquals("Fietsen", Wheel.shown(spec, "cycling"))
    }
}
