{{--
    Calendar date picker (booking-page style). Drop-in replacement for <input type="date">.

    Plain form field:   <x-date-picker name="date_from" :value="request('date_from')" />
    Alpine-bound:       <x-date-picker name="start_date" model="startDate" @change="onStartDateChange()" />
    Dynamic name:       <x-date-picker name-expr="'participants[' + index + '][birthdate]'" model="p.birthdate" year-select />
    Read-only display:  <x-date-picker name="end_date" model="endDate" readonly />

    Values are 'YYYY-MM-DD' (same as <input type="date">). Logic: resources/js/date-picker.js
--}}
@props([
    'name' => null,
    'nameExpr' => null,
    'value' => null,
    'model' => null,
    'min' => null,
    'max' => null,
    'placeholder' => 'Select a date',
    'yearSelect' => false,
    'readonly' => false,
    'required' => false,
    'invalid' => null,
    'id' => null,
])
@php
    $config = json_encode(['value' => (string) $value, 'min' => (string) $min, 'max' => (string) $max, 'yearSelect' => (bool) $yearSelect]);
@endphp
<div x-data="datePicker({{ $config }})"
     @if($model) x-modelable="value" x-model="{{ $model }}" @endif
     @keydown.escape.stop="open = false"
     @click.outside="open = false"
     {{ $attributes->merge(['class' => 'relative']) }}>

    @if($name || $nameExpr)
        <input type="hidden" @if($nameExpr) :name="{{ $nameExpr }}" @else name="{{ $name }}" @endif :value="value">
    @endif

    <button type="button"
            @if($id) id="{{ $id }}" @endif
            @unless($readonly) @click="toggle()" @endunless
            @if($readonly) disabled aria-readonly="true" @endif
            aria-haspopup="dialog"
            :aria-expanded="open"
            @if($required) aria-required="true" @endif
            class="w-full min-h-[44px] flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border text-sm text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]
                   {{ $readonly ? 'bg-[#F2F2F7] border-[#E5E5EA] text-[#6E6E73] cursor-default' : 'bg-white text-[#1D1D1F] cursor-pointer hover:border-[#780000]' }}"
            :class="[{{ $invalid ?: 'false' }} ? 'border-[#D70015] bg-red-50/20' : '{{ $readonly ? '' : 'border-[#D1D1D6]' }}', open ? 'border-[#780000]' : '']">
        <svg class="w-4 h-4 shrink-0 {{ $readonly ? 'text-[#AEAEB2]' : 'text-[#780000]' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <span class="flex-1 truncate font-medium" :class="value ? '' : 'text-[#8E8E93] font-normal'" x-text="label || '{{ $placeholder }}'"></span>
        @unless($readonly)
            <svg class="w-4 h-4 shrink-0 text-[#8E8E93] transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        @endunless
    </button>

    @unless($readonly)
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         role="dialog" aria-label="Choose date"
         class="absolute left-0 top-full mt-2 z-50 w-72 max-w-[calc(100vw-2rem)] bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-3 space-y-2">

        <!-- Month navigation -->
        <div class="flex items-center justify-between gap-1">
            <button type="button" @click="shiftMonth(-1)" :disabled="!canPrev()" aria-label="Previous month"
                    class="w-9 h-9 rounded-full flex items-center justify-center text-[#1D1D1F] hover:bg-[#F2F2F7] disabled:opacity-20 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <template x-if="!yearSelect">
                <span class="font-extrabold text-sm text-[#1D1D1F]" x-text="monthNames[viewMonth] + ' ' + viewYear"></span>
            </template>
            <template x-if="yearSelect">
                <div class="flex items-center gap-1">
                    <select x-model.number="viewMonth" aria-label="Month" class="text-sm font-bold text-[#1D1D1F] rounded-lg border border-[#E5E5EA] px-1.5 py-1 bg-white">
                        <template x-for="(m, i) in monthNames" :key="m"><option :value="i" x-text="m.slice(0, 3)" :selected="i === viewMonth"></option></template>
                    </select>
                    <select x-model.number="viewYear" aria-label="Year" class="text-sm font-bold text-[#1D1D1F] rounded-lg border border-[#E5E5EA] px-1.5 py-1 bg-white">
                        <template x-for="y in years" :key="y"><option :value="y" x-text="y" :selected="y === viewYear"></option></template>
                    </select>
                </div>
            </template>
            <button type="button" @click="shiftMonth(1)" :disabled="!canNext()" aria-label="Next month"
                    class="w-9 h-9 rounded-full flex items-center justify-center text-[#1D1D1F] hover:bg-[#F2F2F7] disabled:opacity-20 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>

        <!-- Weekday headers -->
        <div class="grid grid-cols-7 text-center text-xs font-semibold text-[#6E6E73]">
            <template x-for="w in weekdays" :key="w"><span :class="w === 'Sun' ? 'text-[#780000] font-bold' : ''" x-text="w"></span></template>
        </div>

        <!-- Days -->
        <div class="grid grid-cols-7 gap-y-1 text-center">
            <template x-for="cell in days" :key="cell.key">
                <div class="h-9 flex items-center justify-center">
                    <template x-if="!cell.blank">
                        <button type="button" @click="select(cell)" :disabled="cell.disabled"
                                :aria-label="cell.ymd" :aria-pressed="cell.ymd === value"
                                class="w-9 h-9 rounded-full text-xs sm:text-sm font-semibold flex items-center justify-center transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                :class="{
                                    'bg-[#780000] text-white font-bold': cell.ymd === value,
                                    'text-[#AEAEB2] cursor-not-allowed': cell.disabled,
                                    'ring-1 ring-[#780000]/40': cell.isToday && cell.ymd !== value,
                                    'hover:bg-[#F2F2F7] hover:text-[#780000] text-[#1D1D1F] cursor-pointer': !cell.disabled && cell.ymd !== value && !cell.isSunday,
                                    'hover:bg-[#F2F2F7] text-[#780000] cursor-pointer': !cell.disabled && cell.ymd !== value && cell.isSunday,
                                }"
                                x-text="cell.day"></button>
                    </template>
                </div>
            </template>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between pt-1 border-t border-[#F2F2F7] text-xs font-semibold">
            <button type="button" @click="clear()" class="px-2 py-1.5 rounded-lg text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7]">Clear</button>
            <button type="button" @click="selectToday()" class="px-2 py-1.5 rounded-lg text-[#780000] hover:bg-[#F8EAEA]">Today</button>
        </div>
    </div>
    @endunless
</div>
