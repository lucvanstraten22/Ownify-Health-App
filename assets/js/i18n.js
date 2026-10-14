/**
 * Language and units in the browser — the same rules as includes/i18n.php
 * (docs/I18N.md), for the few sentences, numbers and dates the scripts write
 * themselves. Everything else arrives from the server already in the
 * person's language and units.
 *
 *   OwnifyI18n.t('Opslaan')                 'Save' in English
 *   OwnifyI18n.tf('Nog %d dagen', 3)        sprintf over t()
 *   OwnifyI18n.tn(n, '%d dag', '%d dagen')  one or many
 *   OwnifyI18n.number(72.5, 1)              '72,5' / '72.5'
 *   OwnifyI18n.date(d, 'day_mon')           '5 okt' / 'Oct 5'
 *   OwnifyI18n.units.out(72, 'kg')          72 / 158.73…
 *
 * The language is <html lang>, the units <html data-units>, both written by
 * the server for this request. The English catalogue is assets/js/lang-en.js
 * (generated from lang/en.php by tools/i18n-build.php), loaded before this
 * file only when the page is English.
 */
(function () {
    'use strict';

    var root   = document.documentElement;
    var locale = root.getAttribute('lang') === 'en' ? 'en' : 'nl';
    var system = root.getAttribute('data-units') === 'imperial' ? 'imperial' : 'metric';

    function t(dutch) {
        if (locale === 'nl' || !dutch) { return dutch; }
        var catalog = window.OWNIFY_LANG || {};
        return Object.prototype.hasOwnProperty.call(catalog, dutch) ? catalog[dutch] : dutch;
    }

    /* %s, %d and %.1f in order, or %1$s by position — as PHP's sprintf. */
    function format(text, args) {
        var next = 0;
        return text.replace(/%(?:(\d+)\$)?(\.\d+)?([sdf%])/g, function (all, position, precision, type) {
            if (type === '%') { return '%'; }
            var value = args[position ? Number(position) - 1 : next++];
            if (type === 'd') { return String(Math.round(Number(value))); }
            if (type === 'f') { return number(Number(value), precision ? Number(precision.slice(1)) : 6, false); }
            return value === undefined || value === null ? '' : String(value);
        });
    }

    function tf(dutch) {
        return format(t(dutch), Array.prototype.slice.call(arguments, 1));
    }

    function tn(n, one, many) {
        return format(t(Math.abs(n) === 1 ? one : many), [n]);
    }

    /* ---------------------------------------------------------- numbers */

    function number(value, decimals, grouping) {
        var d = decimals === undefined ? 0 : decimals;
        try {
            return Number(value).toLocaleString(locale === 'en' ? 'en-US' : 'nl-NL', {
                minimumFractionDigits: d,
                maximumFractionDigits: d,
                useGrouping: grouping !== false
            });
        } catch (e) {
            var fixed = Number(value).toFixed(d);
            return locale === 'en' ? fixed : fixed.replace('.', ',');
        }
    }

    /* As number(), without trailing zeros: 5,0 → "5". */
    function numberTrim(value, decimals, grouping) {
        var d = decimals === undefined ? 1 : decimals;
        try {
            return Number(value).toLocaleString(locale === 'en' ? 'en-US' : 'nl-NL', {
                maximumFractionDigits: d,
                useGrouping: grouping !== false
            });
        } catch (e) {
            var text = String(Math.round(Number(value) * Math.pow(10, d)) / Math.pow(10, d));
            return locale === 'en' ? text : text.replace('.', ',');
        }
    }

    /* Typed by a person either way: "72,5", "72.5", "1.234,5", "1,234.5". */
    function parseNumber(text) {
        if (typeof text === 'number') { return isFinite(text) ? text : null; }
        var s = String(text === undefined || text === null ? '' : text).replace(/[\s ]/g, '');
        if (s === '' || !/^-?[0-9.,]+$/.test(s)) { return null; }
        var comma = s.lastIndexOf(','), dot = s.lastIndexOf('.');
        if (comma !== -1 && dot !== -1) {
            var decimal = comma > dot ? ',' : '.';
            s = s.split(decimal === ',' ? '.' : ',').join('').replace(',', '.');
        } else if (comma !== -1) {
            s = s.replace(/,/g, '.');
        }
        var n = Number(s);
        return isFinite(n) ? n : null;
    }

    /* ------------------------------------------------------------ dates */

    var MONTHS = {
        nl: [['januari', 'jan'], ['februari', 'feb'], ['maart', 'mrt'], ['april', 'apr'], ['mei', 'mei'], ['juni', 'jun'],
             ['juli', 'jul'], ['augustus', 'aug'], ['september', 'sep'], ['oktober', 'okt'], ['november', 'nov'], ['december', 'dec']],
        en: [['January', 'Jan'], ['February', 'Feb'], ['March', 'Mar'], ['April', 'Apr'], ['May', 'May'], ['June', 'Jun'],
             ['July', 'Jul'], ['August', 'Aug'], ['September', 'Sep'], ['October', 'Oct'], ['November', 'Nov'], ['December', 'Dec']]
    };
    var WEEKDAYS = {
        nl: [['zondag', 'zo'], ['maandag', 'ma'], ['dinsdag', 'di'], ['woensdag', 'wo'], ['donderdag', 'do'], ['vrijdag', 'vr'], ['zaterdag', 'za']],
        en: [['Sunday', 'Sun'], ['Monday', 'Mon'], ['Tuesday', 'Tue'], ['Wednesday', 'Wed'], ['Thursday', 'Thu'], ['Friday', 'Fri'], ['Saturday', 'Sat']]
    };

    function month(m, short) { return MONTHS[locale][Math.max(1, Math.min(12, m)) - 1][short ? 1 : 0]; }
    function weekday(d, short) { return WEEKDAYS[locale][((d % 7) + 7) % 7][short ? 1 : 0]; }

    /* The formats of i18n_date(): day_month, day_mon, weekday_day_month,
       day_month_year, day_mon_year, month_year, month, mon. */
    function date(d, fmt) {
        var day = d.getDate(), m = d.getMonth() + 1, y = d.getFullYear(), w = d.getDay();
        var en = locale === 'en';
        switch (fmt) {
            case 'day_mon':           return en ? month(m, true) + ' ' + day : day + ' ' + month(m, true);
            case 'weekday_day_month': return en ? weekday(w) + ', ' + month(m) + ' ' + day : weekday(w) + ' ' + day + ' ' + month(m);
            case 'day_month_year':    return en ? month(m) + ' ' + day + ', ' + y : day + ' ' + month(m) + ' ' + y;
            case 'day_mon_year':      return en ? month(m, true) + ' ' + day + ', ' + y : day + ' ' + month(m, true) + ' ' + y;
            case 'month_year':        return month(m) + ' ' + y;
            case 'month':             return month(m);
            case 'mon':               return month(m, true);
            default:                  return en ? month(m) + ' ' + day : day + ' ' + month(m);
        }
    }

    /* ------------------------------------------------------------ units */

    /* units_spec() in includes/i18n.php: what a stored unit is shown as. */
    var IMPERIAL = {
        'kg':     { unit: 'lb',     factor: 2.20462262185, offset: 0,  decimals: 1 },
        'km':     { unit: 'mi',     factor: 1 / 1.609344,  offset: 0,  decimals: 1 },
        'm':      { unit: 'ft',     factor: 1 / 0.3048,    offset: 0,  decimals: 0 },
        'cm':     { unit: 'in',     factor: 1 / 2.54,      offset: 0,  decimals: 1 },
        '°C':     { unit: '°F',     factor: 9 / 5,         offset: 32, decimals: 1 },
        'km/u':   { unit: 'mph',    factor: 1 / 1.609344,  offset: 0,  decimals: 1 },
        'km/h':   { unit: 'mph',    factor: 1 / 1.609344,  offset: 0,  decimals: 1 },
        'min/km': { unit: 'min/mi', factor: 1.609344,      offset: 0,  decimals: 0 }
    };

    function spec(stored) {
        return system === 'imperial' && IMPERIAL[stored]
            ? IMPERIAL[stored]
            : { unit: stored, factor: 1, offset: 0, decimals: 1 };
    }

    var units = {
        system: system,
        imperial: system === 'imperial',
        spec: spec,
        label: function (stored) { return spec(stored).unit; },
        out: function (value, stored) {
            if (value === null || value === undefined || value === '') { return null; }
            var s = spec(stored);
            return Number(value) * s.factor + s.offset;
        },
        in: function (value, stored) {
            if (value === null || value === undefined || value === '') { return null; }
            var s = spec(stored);
            return (Number(value) - s.offset) / s.factor;
        },
        feetInches: function (cm) {
            var inches = Math.round(cm / 2.54);
            return Math.floor(inches / 12) + ' ft ' + (inches % 12) + ' in';
        },
        /* "5'11", "5 ft 11 in", "5 11" or plain inches, in cm. */
        parseFeetInches: function (text) {
            var s = String(text || '').trim().toLowerCase()
                .replace(/[′’]/g, "'").replace(/[″”"]/g, '');
            /* A lone number is inches ("71"), but a whole 2 to 8 is feet ("6"). */
            var m = /^(\d+(?:[.,]\d+)?)\s*(in|inch|inches)?$/.exec(s);
            if (m) {
                var inches = Number(m[1].replace(',', '.'));
                if (!m[2] && /^\d+$/.test(m[1]) && inches >= 2 && inches <= 8) { return inches * 12 * 2.54; }
                return inches > 0 && inches < 120 ? inches * 2.54 : null;
            }
            m = /^(\d+)\s*(?:'|ft|feet|foot)?\s*(\d+(?:[.,]\d+)?)?\s*(?:in|inch|inches)?$/.exec(s);
            if (!m) { return null; }
            var feet = Number(m[1]), rest = m[2] ? Number(m[2].replace(',', '.')) : 0;
            if (feet < 2 || feet > 8 || rest >= 12) { return null; }
            return (feet * 12 + rest) * 2.54;
        },
        format: function (value, stored, decimals, withUnit) {
            if (value === null || value === undefined) { return null; }
            if (stored === 'cm' && system === 'imperial' && value >= 60) { return units.feetInches(value); }
            var s = spec(stored);
            var shown = numberTrim(Number(value) * s.factor + s.offset, decimals === undefined ? s.decimals : decimals);
            return withUnit === false ? shown : shown + ' ' + s.unit;
        }
    };

    window.OwnifyI18n = {
        locale: locale,
        t: t,
        tf: tf,
        tn: tn,
        number: number,
        numberTrim: numberTrim,
        parseNumber: parseNumber,
        month: month,
        weekday: weekday,
        date: date,
        units: units
    };
})();
