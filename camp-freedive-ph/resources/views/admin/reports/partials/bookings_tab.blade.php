@php
    $b = $data['bookings'] ?? [];
    $totalBookings = max(1, $b['total_bookings'] ?? 1);
    $totalDiscoveryPax = max(1, $b['swimmer_ability']['total'] ?? 1);
@endphp

<div class="space-y-6">

    <!-- Top Bookings KPI Summary -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 items-stretch gap-4 sm:gap-6 divide-y sm:divide-y-0 divide-[#F2F2F7]">
            
            <!-- Total Bookings -->
            <div class="px-2 sm:px-4 py-1">
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Total Bookings</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5 break-words">
                    {{ number_format($b['total_bookings'] ?? 0) }}
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $b['confirmed_bookings'] ?? 0 }} confirmed{{ ($b['pending_bookings'] ?? 0) > 0 ? ' · ' . ($b['pending_bookings'] ?? 0) . ' pending' : '' }}</span>
                    <span class="px-1.5 py-0.5 rounded text-xs font-bold shrink-0 {{ ($b['booking_delta'] ?? 0) >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                        {{ ($b['booking_delta'] ?? 0) >= 0 ? '+' : '' }}{{ $b['booking_delta'] ?? 0 }}%
                    </span>
                </div>
            </div>

            <!-- Total Guests (Participants) -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#F2F2F7]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Total Guests (Participants)</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#780000] mt-0.5 break-words">
                    {{ number_format($b['total_participants'] ?? 0) }} guests
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $b['confirmed_participants'] ?? 0 }} confirmed guests</span>
                    <span class="text-xs font-medium text-[#6E6E73] shrink-0">Avg {{ $totalBookings > 0 ? round(($b['total_participants'] ?? 0) / $totalBookings, 1) : 0 }}/booking</span>
                </div>
            </div>

            <!-- Deposit Payment Rate -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#F2F2F7]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Deposit Payment Rate</span>
                <div class="text-xl sm:text-2xl font-extrabold text-emerald-700 mt-0.5 break-words">
                    {{ $b['conversion_rate'] ?? 0 }}%
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $b['confirmed_bookings'] ?? 0 }} of {{ $b['total_bookings'] ?? 0 }} paid</span>
                    @if(($b['conversion_rate'] ?? 0) >= 60)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-emerald-700 bg-emerald-50 shrink-0">Healthy</span>
                    @elseif(($b['conversion_rate'] ?? 0) >= 30)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-amber-700 bg-amber-50 shrink-0">Moderate</span>
                    @else
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-rose-700 bg-rose-50 shrink-0">Follow-up</span>
                    @endif
                </div>
            </div>

            <!-- Cancellations & Reschedules -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#F2F2F7]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Cancellations & Reschedules</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#780000] mt-0.5 break-words">
                    {{ $b['cancellation_rate'] ?? 0 }}%
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $b['cancellation_count'] ?? 0 }} Cancelled · {{ $b['reschedule_count'] ?? 0 }} Rescheduled</span>
                    @if(($b['cancellation_rate'] ?? 0) == 0 && ($b['reschedule_count'] ?? 0) == 0)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-emerald-700 bg-emerald-50 shrink-0">Optimal</span>
                    @elseif(($b['cancellation_rate'] ?? 0) <= 8)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-[#6E6E73] bg-[#F2F2F7] shrink-0">Normal</span>
                    @else
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-rose-700 bg-rose-50 shrink-0">Attention</span>
                    @endif
                </div>
            </div>

        </div>
    </div>

    <!-- Middle: Group Sizes, Swimming Comfort & Advance Notice -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left 2 Cols: Group Sizes & Swimming Ability -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-[#E5E5EA] p-3.5 sm:p-5 shadow-2xs space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Guest Group Sizes & Swimming Comfort</h3>
                    <p class="text-sm text-[#6E6E73]">Distribution of party sizes (solo vs group) and swimming ability of discovery guests.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <!-- Group Size Breakdown -->
                <div class="space-y-3">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#6E6E73]">Party Size Breakdown</h4>
                    <div class="space-y-3 text-sm">
                        @php
                            $groups = [
                                ['label' => 'Solo Travelers (1 Guest)', 'count' => $b['group_sizes']['solo'] ?? 0, 'color' => 'bg-[#780000]'],
                                ['label' => 'Pairs / Duos (2 Guests)', 'count' => $b['group_sizes']['duo'] ?? 0, 'color' => 'bg-[#00C3D0]'],
                                ['label' => 'Small Groups (3–4 Guests)', 'count' => $b['group_sizes']['small_group'] ?? 0, 'color' => 'bg-[#D45D5D]'],
                                ['label' => 'Large Groups (5+ Guests)', 'count' => $b['group_sizes']['large_group'] ?? 0, 'color' => 'bg-[#2C2C2E]'],
                            ];
                        @endphp

                        @foreach($groups as $g)
                            @php $pct = round(($g['count'] / $totalBookings) * 100, 1); @endphp
                            <div class="space-y-1">
                                <div class="flex items-center justify-between font-semibold">
                                    <span class="text-[#1D1D1F]">{{ $g['label'] }}</span>
                                    <span class="text-[#6E6E73]">{{ $g['count'] }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-[#E5E5EA] overflow-hidden">
                                    <div class="h-full {{ $g['color'] }} transition-all" style="width: {{ $pct }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Swimmer Ability in Discovery Class -->
                <div class="space-y-3">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#6E6E73]">Swimming Ability (Discovery Course)</h4>
                    <div class="space-y-3 text-sm">
                        @php
                            $skills = [
                                ['label' => 'Non-Swimmers (Needs extra coach attention)', 'count' => $b['swimmer_ability']['non_swimmer'] ?? 0, 'color' => 'bg-[#780000]'],
                                ['label' => 'Casual Swimmers (Comfortable in shallow water)', 'count' => $b['swimmer_ability']['casual_swimmer'] ?? 0, 'color' => 'bg-[#00C3D0]'],
                                ['label' => 'Confident Swimmers (Comfortable in deep water)', 'count' => $b['swimmer_ability']['confident_swimmer'] ?? 0, 'color' => 'bg-emerald-600'],
                            ];
                        @endphp

                        @foreach($skills as $s)
                            @php $pct = round(($s['count'] / $totalDiscoveryPax) * 100, 1); @endphp
                            <div class="space-y-1">
                                <div class="flex items-center justify-between font-semibold">
                                    <span class="text-[#1D1D1F]">{{ $s['label'] }}</span>
                                    <span class="text-[#6E6E73]">{{ $s['count'] }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-[#E5E5EA] overflow-hidden">
                                    <div class="h-full {{ $s['color'] }} transition-all" style="width: {{ $pct }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Right 1 Col: Booking Advance Notice Semi-Circle Gauge -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-3.5 sm:p-5 shadow-2xs flex flex-col justify-between">
            <div>
                <div class="pb-1">
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Booking Advance Notice</h3>
                    <p class="text-xs text-[#6E6E73]">How many days in advance guests book before the trip starts.</p>
                </div>

                @php
                    $under4 = (int)($b['lead_times']['under_3_days'] ?? 0);
                    $days4to7 = (int)($b['lead_times']['4_to_7_days'] ?? 0);
                    $days8to14 = (int)($b['lead_times']['8_to_14_days'] ?? 0);
                    $days15plus = (int)(($b['lead_times']['15_to_30_days'] ?? 0) + ($b['lead_times']['over_30_days'] ?? 0));
                    $totalLeadCount = $under4 + $days4to7 + $days8to14 + $days15plus;
                    
                    // Brand colors for the 4 categories
                    $leadCategories = [
                        [
                            'key' => 'under_4',
                            'label' => '< 4 Days',
                            'sub' => 'Last-minute',
                            'count' => $under4,
                            'color' => '#780000', // Brand Deep Crimson
                        ],
                        [
                            'key' => '4_to_7',
                            'label' => '4–7 Days',
                            'sub' => 'Week-of',
                            'count' => $days4to7,
                            'color' => '#D45D5D', // Coral Rose
                        ],
                        [
                            'key' => '8_to_14',
                            'label' => '8–14 Days',
                            'sub' => '1–2 Weeks',
                            'count' => $days8to14,
                            'color' => '#00C3D0', // Turquoise Aqua
                        ],
                        [
                            'key' => '15_plus',
                            'label' => '15+ Days',
                            'sub' => 'Advance',
                            'count' => $days15plus,
                            'color' => '#F59E0B', // Sun Amber
                        ],
                    ];

                    // Calculate average lead days from bookings_list
                    $leadDaysSum = 0;
                    $leadDaysCount = 0;
                    foreach ($b['bookings_list'] ?? [] as $bkItem) {
                        if ($bkItem->start_date && $bkItem->created_at) {
                            $diff = $bkItem->created_at->diffInDays($bkItem->start_date, false);
                            if ($diff >= 0) {
                                $leadDaysSum += $diff;
                                $leadDaysCount++;
                            }
                        }
                    }
                    $avgLeadDays = $leadDaysCount > 0 ? round($leadDaysSum / $leadDaysCount, 1) : 0;

                    // SVG Gauge metrics: R = 108, Center = (145, 128)
                    $radius = 108;
                    $pi = 3.14159265;
                    $circumference = 2 * $pi * $radius;
                    $semiCircumference = $pi * $radius;
                    
                    $activeCategoriesCount = collect($leadCategories)->where('count', '>', 0)->count();
                    $gap = $activeCategoriesCount > 1 ? 3.5 : 0;
                @endphp

                <!-- 4 Top Stat Columns with colored vertical left bar -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 sm:gap-3 pt-3 pb-2">
                    @foreach($leadCategories as $cat)
                        <div class="border-l-[3px] pl-2.5 sm:pl-3" style="border-color: {{ $cat['color'] }};">
                            <div class="text-lg sm:text-xl font-extrabold text-[#1D1D1F] leading-tight">
                                {{ number_format($cat['count']) }}
                            </div>
                            <div class="text-xs font-semibold text-[#6E6E73] truncate mt-0.5">
                                {{ $cat['label'] }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Semi-Circle Gauge Chart matching the design (Enlarged) -->
                <div class="relative flex items-center justify-center pt-3 pb-1">
                    <svg viewBox="0 0 290 148" class="w-full max-w-[320px] sm:max-w-[350px] overflow-visible">
                        <!-- Background track arc -->
                        <circle cx="145" cy="128" r="{{ $radius }}"
                                fill="none"
                                stroke="#F2F2F7"
                                stroke-width="24"
                                stroke-dasharray="{{ round($semiCircumference, 2) }} {{ round($circumference, 2) }}"
                                stroke-dashoffset="0"
                                transform="rotate(180 145 128)" />

                        @if($totalLeadCount > 0)
                            @php
                                $accumulatedOffset = 0;
                            @endphp
                            @foreach($leadCategories as $cat)
                                @if($cat['count'] > 0)
                                    @php
                                        $fraction = $cat['count'] / $totalLeadCount;
                                        $segmentRawLen = $fraction * $semiCircumference;
                                        $visibleLen = max(1, $segmentRawLen - $gap);
                                        $pct = round($fraction * 100, 1);
                                    @endphp
                                    <!-- Segment: {{ $cat['label'] }} -->
                                    <circle cx="145" cy="128" r="{{ $radius }}"
                                            fill="none"
                                            stroke="{{ $cat['color'] }}"
                                            stroke-width="24"
                                            stroke-dasharray="{{ round($visibleLen, 2) }} {{ round($circumference, 2) }}"
                                            stroke-dashoffset="{{ round(-$accumulatedOffset, 2) }}"
                                            transform="rotate(180 145 128)"
                                            class="transition-all duration-500 ease-out hover:opacity-85 cursor-pointer">
                                        <title>{{ $cat['label'] }} ({{ $cat['sub'] }}): {{ $cat['count'] }} bookings ({{ $pct }}%)</title>
                                    </circle>
                                    @php
                                        $accumulatedOffset += $segmentRawLen;
                                    @endphp
                                 @endif
                            @endforeach
                        @endif
                    </svg>

                    <!-- Center KPI in the Semi-Circle Gauge -->
                    <div class="absolute inset-x-0 bottom-2 flex flex-col items-center justify-center text-center pointer-events-none">
                        <span class="text-3xl sm:text-4xl font-black text-[#1D1D1F] tracking-tight leading-none">
                            {{ number_format($totalLeadCount) }}
                        </span>
                        <span class="text-xs sm:text-sm font-bold text-[#8E8E93] uppercase tracking-wider mt-1">
                            Total Bookings
                        </span>
                    </div>
                </div>
            </div>

            <!-- Bottom Insight Pill -->
            <div class="mt-3 pt-2.5 border-t border-[#F2F2F7] flex items-center justify-between text-xs text-[#6E6E73]">
                <span class="flex items-center gap-1 font-medium">
                    Average Notice: <strong class="text-[#1D1D1F] font-bold">{{ $avgLeadDays }} days in advance</strong>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                    @if($avgLeadDays >= 14)
                        Early Bookers
                    @elseif($avgLeadDays >= 7)
                        Moderate
                    @else
                        Spontaneous
                    @endif
                </span>
            </div>
        </div>

    </div>

    <!-- Bottom: Bookings & Guest List Table (Collapsible) -->
    <div x-data="{ showBookingsList: false }" class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs space-y-0">
        
        <!-- Toggle Header -->
        <button type="button" 
                @click="showBookingsList = !showBookingsList"
                class="w-full p-4 sm:p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F2F2F7]/50 transition-colors cursor-pointer select-none">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Bookings &amp; Guest List</h3>
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                        {{ count($b['bookings_list'] ?? []) }}
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">List of customer reservations, package selections, group sizes, and batch assignments.</p>
            </div>
            
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-bold text-[#780000]" x-text="showBookingsList ? 'Hide List' : 'Show List'"></span>
                <svg class="w-4 h-4 text-[#6E6E73] transform transition-transform duration-200" 
                     :class="showBookingsList ? 'rotate-180' : ''" 
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
        </button>

        <!-- Collapsible Content -->
        <div x-show="showBookingsList" x-collapse x-cloak class="border-t border-[#F2F2F7]">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[900px]">
                    <thead class="bg-[#F2F2F7] border-b border-[#F2F2F7] text-sm uppercase font-bold text-[#6E6E73]">
                        <tr>
                            <th class="p-4 pl-6">Booking # / Date</th>
                            <th class="p-4">Guest Name & Email</th>
                            <th class="p-4">Course Package</th>
                            <th class="p-4 text-center">Group Size</th>
                            <th class="p-4">Assigned Batch</th>
                            <th class="p-4 pr-6 text-center">Booking Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($b['bookings_list'] ?? [] as $bk)
                            <tr onclick="window.location='{{ route('admin.bookings.show', $bk) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm sm:text-sm group">
                                <td class="p-4 pl-6 font-mono">
                                    <span class="font-bold text-[#780000] block text-sm group-hover:underline">{{ $bk->booking_number }}</span>
                                    <span class="text-sm text-[#8E8E93]">{{ $bk->created_at?->format('M d, Y') }}</span>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-[#1D1D1F]">{{ $bk->contact_name }}</div>
                                    <div class="text-sm text-[#6E6E73]">{{ $bk->contact_email }}</div>
                                </td>
                                <td class="p-4">
                                    <span class="font-semibold text-[#1D1D1F] block">{{ ucfirst($bk->class_type) }}</span>
                                    <span class="text-sm text-[#6E6E73]">{{ $bk->pickup_option === 'carpool' ? 'Carpool' : 'Own Transport' }}</span>
                                </td>
                                <td class="p-4 text-center">
                                    <span class="font-bold text-[#780000]">{{ $bk->participants->count() }} guests</span>
                                </td>
                                <td class="p-4">
                                    @if($bk->batch)
                                        <span class="font-bold text-[#1D1D1F] block">{{ $bk->batch->display_name }}</span>
                                        <span class="text-sm text-[#8E8E93]">
                                            {{ $bk->batch->formatted_date_range }}
                                        </span>
                                    @else
                                        <span class="text-sm text-[#8E8E93] italic">Unassigned</span>
                                    @endif
                                </td>
                                <td class="p-4 pr-6 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-sm font-bold {{ $bk->status === 'confirmed' ? 'bg-emerald-50 text-emerald-700' : ($bk->status === 'completed' ? 'bg-sky-50 text-sky-700' : ($bk->status === 'pending_downpayment' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700')) }}">
                                        {{ ucfirst(str_replace('_', ' ', $bk->status)) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-6 text-center text-sm text-[#8E8E93]">No bookings found for the selected reporting period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
