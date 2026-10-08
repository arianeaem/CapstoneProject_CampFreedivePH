// Display helpers for the dive safety / weather results.
// Available in every Alpine template as `$safety` (see app.js). Only for display, no safety logic here.

const TONE_STYLES = {
    safe: { surface: 'bg-[#F0FDF4] border-[#BBF7D0]', text: 'text-[#047857]', dot: 'bg-[#10B981]' },
    caution: { surface: 'bg-[#FFFBEB] border-[#FDE68A]', text: 'text-[#B45309]', dot: 'bg-[#F59E0B]' },
    unsafe: { surface: 'bg-[#FEF2F2] border-[#FECACA]', text: 'text-[#B91C1C]', dot: 'bg-[#EF4444]' },
    neutral: { surface: 'bg-[#F5F5F7] border-[#E5E5EA]', text: 'text-[#3A3A3C]', dot: 'bg-[#AEAEB2]' },
};

// Fixed color per indicator line: 1 red, 2 orange, 3 amber, 4 lime, 5 green
const BAR_SEGMENTS = ['bg-[#EF4444]', 'bg-[#F97316]', 'bg-[#F59E0B]', 'bg-[#84CC16]', 'bg-[#10B981]'];

export const DiveSafety = {
    // 'safe' | 'caution' | 'unsafe' | 'neutral' (seasonal estimate / no live rating)
    tone(classification, seasonal = false) {
        if (!classification || seasonal) return 'neutral';
        switch (classification) {
            case 'Very Safe':
            case 'Safe': return 'safe';
            case 'Moderate': return 'caution';
            case 'High Risk':
            case 'Critical Risk': return 'unsafe';
            default: return 'neutral';
        }
    },

    headline(classification, seasonal = false) {
        if (this.tone(classification, seasonal) === 'neutral') return 'Typical season conditions';
        switch (classification) {
            case 'Moderate': return 'Some caution recommended';
            case 'High Risk': return 'Rough conditions expected';
            case 'Critical Risk': return 'Conditions are not recommended';
            default: return 'Safe to dive';
        }
    },

    surface(classification, seasonal = false) { return TONE_STYLES[this.tone(classification, seasonal)].surface; },
    text(classification, seasonal = false) { return TONE_STYLES[this.tone(classification, seasonal)].text; },
    dot(classification, seasonal = false) { return TONE_STYLES[this.tone(classification, seasonal)].dot; },

    score(classification) {
        return { 'Very Safe': 5, 'Safe': 4, 'Moderate': 3, 'High Risk': 2, 'Critical Risk': 1 }[classification] ?? 0;
    },

    bar(i, classification) {
        return i <= this.score(classification) ? BAR_SEGMENTS[i - 1] : 'bg-[#E5E5EA]';
    },

    // "Oct 08, 2026" -> { month: 'OCT', day: '08', weekday: 'Thu' }
    dayParts(dateLabel) {
        const d = dateLabel ? new Date(dateLabel) : null;
        if (!d || isNaN(d)) return { month: '', day: dateLabel || '', weekday: '' };
        return {
            month: d.toLocaleDateString('en-US', { month: 'short' }).toUpperCase(),
            day: String(d.getDate()).padStart(2, '0'),
            weekday: d.toLocaleDateString('en-US', { weekday: 'short' }),
        };
    },

    hasTime(value) {
        return /\d/.test(value || '');
    },

    // Short reasons from the Day 1 / Day 2 readings
    highlights(f) {
        if (!f || !f.day1 || !f.day2) return [];
        const peak = (key) => {
            const vals = [f.day1[key], f.day2[key]].map(Number).filter(v => !isNaN(v));
            return vals.length ? Math.max(...vals) : null;
        };
        const items = [];
        const waves = peak('wave_height_m');
        if (waves !== null) {
            items.push({ ok: waves < 1.0, text: (waves < 0.5 ? 'Calm water' : waves < 1.0 ? 'Light chop' : 'Rough water') + ' · waves up to ' + waves.toFixed(1) + ' m' });
        }
        const wind = peak('wind_speed_kmh');
        if (wind !== null) {
            items.push({ ok: wind < 30, text: (wind < 20 ? 'Light breeze' : wind < 30 ? 'Moderate breeze' : 'Strong wind') + ' · around ' + Math.round(wind) + ' km/h' });
        }
        const rain = peak('rain_daily_mm');
        if (rain !== null) {
            items.push({ ok: rain < 10, text: rain < 2.5 ? 'Little to no rain expected' : rain < 10 ? 'Some rain possible' : 'Heavy rain likely' });
        }
        return items.slice(0, 4);
    },

    // The two forecast models in display order, with simple names
    engines(f) {
        if (!f || !f.engines) return [];
        return [
            Object.assign({ title: 'Historical Model', subtitle: 'Seasonal baseline' }, f.engines.historical || {}),
            Object.assign({ title: 'Legacy Forecast', subtitle: 'Open-Meteo + ONNX' }, f.engines.legacy || {}),
        ];
    },
};
