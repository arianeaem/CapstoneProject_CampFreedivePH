// Calendar date picker (booking-page style) used by <x-date-picker>.
// Values are 'YYYY-MM-DD' strings in local time, same as <input type="date">.

const pad = (n) => String(n).padStart(2, '0');
const toYmd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseYmd = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
};

export default function datePicker({ value = '', min = '', max = '', yearSelect = false } = {}) {
    return {
        value: value || '',
        min: min || '',
        max: max || '',
        yearSelect,
        open: false,
        viewYear: 0,
        viewMonth: 0,
        monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],

        init() {
            this.resetView();
            this.$watch('value', () => { if (!this.open) this.resetView(); });
        },

        resetView() {
            const base = parseYmd(this.value) || parseYmd(this.max && toYmd(new Date()) > this.max ? this.max : '') || new Date();
            this.viewYear = base.getFullYear();
            this.viewMonth = base.getMonth();
        },

        toggle() {
            if (!this.open) this.resetView();
            this.open = !this.open;
        },

        get label() {
            const d = parseYmd(this.value);
            if (!d) return '';
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
                + ' · ' + d.toLocaleDateString('en-US', { weekday: 'short' });
        },

        get years() {
            const now = new Date().getFullYear();
            const from = parseYmd(this.min)?.getFullYear() ?? now - 90;
            const to = parseYmd(this.max)?.getFullYear() ?? now + 5;
            const list = [];
            for (let y = to; y >= from; y--) list.push(y);
            return list;
        },

        get days() {
            const first = new Date(this.viewYear, this.viewMonth, 1);
            const count = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
            const today = toYmd(new Date());
            const cells = [];
            for (let i = 0; i < first.getDay(); i++) cells.push({ blank: true, key: 'b' + i });
            for (let day = 1; day <= count; day++) {
                const ymd = toYmd(new Date(this.viewYear, this.viewMonth, day));
                cells.push({
                    key: ymd,
                    day,
                    ymd,
                    isToday: ymd === today,
                    isSunday: new Date(this.viewYear, this.viewMonth, day).getDay() === 0,
                    // Must be a real boolean: Alpine keeps `disabled` for '' (only false/null/undefined remove it)
                    disabled: Boolean((this.min && ymd < this.min) || (this.max && ymd > this.max)),
                });
            }
            return cells;
        },

        canPrev() {
            if (!this.min) return true;
            const m = parseYmd(this.min);
            return this.viewYear > m.getFullYear() || (this.viewYear === m.getFullYear() && this.viewMonth > m.getMonth());
        },

        canNext() {
            if (!this.max) return true;
            const m = parseYmd(this.max);
            return this.viewYear < m.getFullYear() || (this.viewYear === m.getFullYear() && this.viewMonth < m.getMonth());
        },

        shiftMonth(step) {
            const d = new Date(this.viewYear, this.viewMonth + step, 1);
            this.viewYear = d.getFullYear();
            this.viewMonth = d.getMonth();
        },

        select(cell) {
            if (!cell || cell.blank || cell.disabled) return;
            this.setValue(cell.ymd);
            this.open = false;
        },

        selectToday() {
            const today = toYmd(new Date());
            if ((this.min && today < this.min) || (this.max && today > this.max)) return;
            this.setValue(today);
            this.open = false;
        },

        clear() {
            this.setValue('');
            this.open = false;
        },

        // Fire change events after x-model updates, so the @change handlers still work
        setValue(v) {
            this.value = v;
            this.$nextTick(() => {
                this.$root.dispatchEvent(new Event('input', { bubbles: true }));
                this.$root.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
    };
}
