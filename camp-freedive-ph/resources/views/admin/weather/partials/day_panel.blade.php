{{-- One day of the batch (Day 1 or Day 2). $day comes from BatchSafetyViewModel::day() --}}
<div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="px-2.5 py-1 rounded-lg text-xs font-black bg-[#F8EAEA] text-[#780000] border border-[#F1D5D5]">
                DAY {{ $day['number'] }}
            </span>
            <h2 class="text-base font-extrabold text-[#1D1D1F]">
                {{ $day['assessment']->dive_date->format('F d, Y (l)') }}
            </h2>
        </div>

        <span class="px-3 py-1 rounded-full text-xs font-black uppercase {{ $day['badge'] }}">
            {{ $day['recommendation'] }}
        </span>
    </div>

    <!-- Quick stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
        <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
            <span class="text-[#6E6E73] block uppercase font-bold">Roughest Hour</span>
            <strong class="text-sm font-extrabold text-[#1D1D1F]">
                {{ $day['assessment']->worst_hour ? $day['assessment']->worst_hour->format('g:i A') : 'N/A' }}
            </strong>
        </div>

        <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
            <span class="text-[#6E6E73] block uppercase font-bold">Forecast Made</span>
            <strong class="text-sm font-extrabold text-[#1D1D1F]">{{ $day['forecast_made'] }}</strong>
        </div>

        <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
            <span class="text-[#6E6E73] block uppercase font-bold">Reliability</span>
            <strong class="text-xs font-bold text-[#1D1D1F]">{{ $day['reliability'] }}</strong>
        </div>
    </div>

    <!-- Hourly table -->
    @if(!empty($day['rows']))
    <div x-data="{ showAllHours: false }" class="space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            @if($day['has_full_24h'])
            <button type="button"
                    @click="showAllHours = !showAllHours"
                    class="px-3 py-1.5 rounded-xl border border-[#D1D1D6] hover:border-[#780000] bg-white hover:bg-[#F2F2F7] text-xs font-bold text-[#1D1D1F] flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer">
                <span x-text="showAllHours ? 'Show dive hours only' : 'Show all 24 hours'"></span>
                <svg class="w-3.5 h-3.5 transition-transform" :class="showAllHours ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            @else
            <span class="text-xs text-[#8E8E93] font-semibold bg-[#F2F2F7] px-2 py-0.5 rounded-md">
                Dive hours (morning &amp; afternoon)
            </span>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-[#E5E5EA]">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] uppercase font-extrabold text-[#6E6E73]">
                    <tr>
                        <th class="py-2.5 px-3 whitespace-nowrap">Time</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="Average height of the waves">Wave Height</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="Seconds between waves (longer = smoother)">Time Between Waves</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="Height of rolling swells from far away">Swell</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="How fast the water is moving">Current</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="Expected rain">Rain</th>
                        <th class="py-2.5 px-2 whitespace-nowrap" title="Air pressure (a sudden drop can mean a storm)">Air Pressure</th>
                        <th class="py-2.5 px-3 whitespace-nowrap" title="Wind Speed & Direction">Wind Speed &amp; Gusts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA] bg-white">
                    @foreach($day['rows'] as $row)
                    <tr x-show="showAllHours || {{ ($row['is_am'] || $row['is_pm']) ? 'true' : 'false' }}"
                        class="hover:bg-[#F2F2F7] transition-colors {{ ($row['is_am'] || $row['is_pm']) ? 'bg-[#F8EAEA]/20 font-semibold' : '' }}">
                        <td class="py-2.5 px-3 whitespace-nowrap font-mono text-[#1D1D1F]">
                            <div class="flex items-center gap-2">
                                <span class="w-1.5 h-4.5 rounded-full {{ $row['line'] }} shrink-0" title="Rating: {{ $row['risk'] }}"></span>
                                <div class="flex items-center gap-1.5">
                                    <span>{{ sprintf('%02d:00', $row['hour']) }}</span>
                                    @if($row['is_am'])
                                        <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">AM</span>
                                    @elseif($row['is_pm'])
                                        <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">PM</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">
                            <strong>{{ number_format($row['hs'], 2) }}m</strong>
                            <span class="text-[11px] text-[#8E8E93] block font-mono" title="Expected wave height range from lowest to highest">Between {{ number_format($row['hs_p10'], 2) }} – {{ number_format($row['hs_p90'], 2) }}m</span>
                        </td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($row['tp'], 1) }}s</td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($row['swell'], 2) }}m</td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($row['current'], 2) }}m/s</td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($row['rain'], 1) }}mm</td>
                        <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ round($row['pressure']) }}hPa</td>
                        <td class="py-2 px-3 whitespace-nowrap font-medium text-[#1D1D1F]">
                            <span>{{ round($row['wind']) }} km/h</span>
                            <span class="text-xs text-[#8E8E93] block">Gusts: {{ round($row['gusts']) }} km/h</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <p class="text-xs text-[#6E6E73] italic py-4 text-center">Hourly details are not available yet.</p>
    @endif
</div>
