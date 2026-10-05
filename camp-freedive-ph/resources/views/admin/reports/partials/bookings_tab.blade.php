@php
    $b = $data['bookings'] ?? [];
    $total = (int) ($b['total_bookings'] ?? 0);
    $totalBookings = max(1, $total);
    $totalDiscoveryPax = max(1, $b['swimmer_ability']['total'] ?? 1);
    $delta = (float) ($b['booking_delta'] ?? 0);
    $groups = $b['status_groups'] ?? [];

    // Where every booking stands: these five always add up to the total
    $standing = [
        ['label' => 'Paid & going', 'hint' => 'Downpayment paid, trip still ahead', 'count' => $groups['going'] ?? 0, 'color' => '#780000'],
        ['label' => 'Finished trip', 'hint' => 'Already dived with us', 'count' => $groups['completed'] ?? 0, 'color' => '#00C3D0'],
        ['label' => 'Waiting for downpayment', 'hint' => 'Started booking, not paid yet', 'count' => $groups['waiting'] ?? 0, 'color' => '#F59E0B'],
        ['label' => 'Cancelled', 'hint' => 'By the guest or by the camp', 'count' => $groups['cancelled'] ?? 0, 'color' => '#D45D5D'],
        ['label' => 'No-show', 'hint' => "Didn't arrive on the day", 'count' => $groups['no_show'] ?? 0, 'color' => '#6E6E73'],
    ];
    $statusLabel = fn ($status) => match (\App\Services\Analytics\AnalyticsService::bookingGroup($status)) {
        'completed' => ['Finished trip', 'bg-[#00C3D0]/10 text-[#00636A]'],
        'waiting' => ['Waiting for downpayment', 'bg-[#FFFBEB] text-[#B45309]'],
        'cancelled' => ['Cancelled', 'bg-[#FEF2F2] text-[#B91C1C]'],
        'no_show' => ['No-show', 'bg-[#F2F2F7] text-[#6E6E73]'],
        default => match ($status) {
            'reschedule_requested' => ['Asked to reschedule', 'bg-[#F8EAEA] text-[#780000]'],
            'cancellation_requested' => ['Asked to cancel', 'bg-[#F8EAEA] text-[#780000]'],
            default => ['Paid & going', 'bg-[#F8EAEA] text-[#780000]'],
        },
    };
@endphp

<div class="space-y-5">

    @include('admin.reports.partials.summary_cards', ['cards' => [
        [
            'label' => 'Bookings made',
            'value' => number_format($total),
            'hint' => 'New bookings in this period',
            'badge' => ($delta >= 0 ? '+' : '') . $delta . '% vs before',
            'badgeTone' => $delta >= 0 ? 'bg-[#00C3D0]/10 text-[#00636A]' : 'bg-[#FEF2F2] text-[#B91C1C]',
        ],
        [
            'label' => 'Divers',
            'value' => number_format($b['total_participants'] ?? 0),
            'hint' => ($b['confirmed_participants'] ?? 0) . ' on paid or finished trips · about ' . ($total > 0 ? round(($b['total_participants'] ?? 0) / $total, 1) : 0) . ' per booking',
            'tone' => 'text-[#1D1D1F]',
        ],
        [
            'label' => 'Paid the downpayment',
            'value' => ($b['conversion_rate'] ?? 0) . '%',
            'hint' => ($b['paid_bookings'] ?? 0) . ' of ' . $total . ' bookings paid',
            'tone' => 'text-[#00838C]',
        ],
        [
            'label' => 'Cancelled',
            'value' => ($b['cancellation_rate'] ?? 0) . '%',
            'hint' => ($b['cancelled_bookings'] ?? 0) . ' cancelled · ' . ($b['reschedule_count'] ?? 0) . ' reschedule and ' . ($b['cancellation_count'] ?? 0) . ' cancel ' . Str::plural('request', ($b['cancellation_count'] ?? 0)),
            'tone' => 'text-[#B91C1C]',
        ],
    ]])

    <!-- Where every booking stands -->
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
        <div>
            <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Where every booking stands</h3>
            <p class="text-sm text-[#8E8E93]">All {{ $total }} {{ Str::plural('booking', $total) }} made in this period, each counted once</p>
        </div>
        <div class="flex h-4 rounded-full overflow-hidden bg-[#F2F2F7]" role="img" aria-label="Booking status breakdown">
            @foreach($standing as $s)
                @if($s['count'] > 0)
                    <div style="width: {{ $s['count'] / $totalBookings * 100 }}%; background: {{ $s['color'] }};" title="{{ $s['label'] }}: {{ $s['count'] }}"></div>
                @endif
            @endforeach
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            @foreach($standing as $s)
                <div class="border-l-[3px] pl-3" style="border-color: {{ $s['color'] }};">
                    <div class="text-xl font-black text-[#1D1D1F]">{{ $s['count'] }} <span class="text-xs font-bold text-[#8E8E93]">{{ round($s['count'] / $totalBookings * 100) }}%</span></div>
                    <div class="text-sm font-semibold text-[#1D1D1F]">{{ $s['label'] }}</div>
                    <div class="text-xs text-[#8E8E93]">{{ $s['hint'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Group size + swimming -->
        <div class="lg:col-span-7 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Who books together</h3>
                        <p class="text-sm text-[#8E8E93]">Out of {{ $total }} {{ Str::plural('booking', $total) }}</p>
                    </div>
                    @foreach([
                        ['label' => 'Alone (1 diver)', 'count' => $b['group_sizes']['solo'] ?? 0, 'color' => 'bg-[#780000]'],
                        ['label' => 'Pairs (2 divers)', 'count' => $b['group_sizes']['duo'] ?? 0, 'color' => 'bg-[#00C3D0]'],
                        ['label' => 'Small groups (3–4)', 'count' => $b['group_sizes']['small_group'] ?? 0, 'color' => 'bg-[#D45D5D]'],
                        ['label' => 'Big groups (5 or more)', 'count' => $b['group_sizes']['large_group'] ?? 0, 'color' => 'bg-[#3A3A3C]'],
                    ] as $g)
                        @php $pct = round($g['count'] / $totalBookings * 100); @endphp
                        <div class="space-y-1 text-sm">
                            <div class="flex items-center justify-between font-semibold">
                                <span class="text-[#1D1D1F]">{{ $g['label'] }}</span>
                                <span class="text-[#6E6E73]">{{ $g['count'] }} &middot; {{ $pct }}%</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-[#F2F2F7] overflow-hidden"><div class="h-full {{ $g['color'] }}" style="width: {{ $pct }}%;"></div></div>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Can Discovery divers swim?</h3>
                        <p class="text-sm text-[#8E8E93]">Out of {{ $b['swimmer_ability']['total'] ?? 0 }} Discovery {{ Str::plural('diver', $b['swimmer_ability']['total'] ?? 0) }}</p>
                    </div>
                    @foreach([
                        ['label' => "Can't swim", 'note' => 'Needs extra coach attention', 'count' => $b['swimmer_ability']['cannot_swim'] ?? 0, 'color' => 'bg-[#780000]'],
                        ['label' => 'Can swim', 'note' => 'Comfortable in the water', 'count' => $b['swimmer_ability']['can_swim'] ?? 0, 'color' => 'bg-[#00C3D0]'],
                        ['label' => 'Strong swimmer', 'note' => 'Comfortable in deep water', 'count' => $b['swimmer_ability']['strong'] ?? 0, 'color' => 'bg-[#047857]'],
                    ] as $s)
                        @php $pct = round($s['count'] / $totalDiscoveryPax * 100); @endphp
                        <div class="space-y-1 text-sm">
                            <div class="flex items-center justify-between font-semibold">
                                <span class="text-[#1D1D1F]">{{ $s['label'] }} <span class="font-normal text-xs text-[#8E8E93]">{{ $s['note'] }}</span></span>
                                <span class="text-[#6E6E73] shrink-0">{{ $s['count'] }} &middot; {{ $pct }}%</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-[#F2F2F7] overflow-hidden"><div class="h-full {{ $s['color'] }}" style="width: {{ $pct }}%;"></div></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- How early guests book -->
        <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] p-5 flex flex-col justify-between">
            <div>
                <div class="pb-1">
                    <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>How early guests book</h3>
                    <p class="text-sm text-[#8E8E93]">Days between booking and the dive</p>
                </div>

                @php
                    $under4 = (int)($b['lead_times']['under_3_days'] ?? 0);
                    $days4to7 = (int)($b['lead_times']['4_to_7_days'] ?? 0);
                    $days8to14 = (int)($b['lead_times']['8_to_14_days'] ?? 0);
                    $days15plus = (int)(($b['lead_times']['15_to_30_days'] ?? 0) + ($b['lead_times']['over_30_days'] ?? 0));
                    $totalLeadCount = $under4 + $days4to7 + $days8to14 + $days15plus;

                    $leadCategories = [
                        ['key' => 'under_4', 'label' => 'Under 4 days', 'sub' => 'Last minute', 'count' => $under4, 'color' => '#780000'],
                        ['key' => '4_to_7', 'label' => '4–7 days', 'sub' => 'Same week', 'count' => $days4to7, 'color' => '#D45D5D'],
                        ['key' => '8_to_14', 'label' => '1–2 weeks', 'sub' => '8–14 days', 'count' => $days8to14, 'color' => '#00C3D0'],
                        ['key' => '15_plus', 'label' => '15+ days', 'sub' => 'Planned ahead', 'count' => $days15plus, 'color' => '#F59E0B'],
                    ];

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

                    $radius = 108;
                    $pi = 3.14159265;
                    $circumference = 2 * $pi * $radius;
                    $semiCircumference = $pi * $radius;
                    $gap = collect($leadCategories)->where('count', '>', 0)->count() > 1 ? 3.5 : 0;
                @endphp

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-3 pb-2">
                    @foreach($leadCategories as $cat)
                        <div class="border-l-[3px] pl-2.5" style="border-color: {{ $cat['color'] }};">
                            <div class="text-lg font-extrabold text-[#1D1D1F] leading-tight">{{ number_format($cat['count']) }}</div>
                            <div class="text-xs font-semibold text-[#6E6E73] truncate mt-0.5">{{ $cat['label'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="relative flex items-center justify-center pt-3 pb-1">
                    <svg viewBox="0 0 290 148" class="w-full max-w-[320px] overflow-visible">
                        <circle cx="145" cy="128" r="{{ $radius }}" fill="none" stroke="#F2F2F7" stroke-width="24"
                                stroke-dasharray="{{ round($semiCircumference, 2) }} {{ round($circumference, 2) }}" transform="rotate(180 145 128)" />
                        @if($totalLeadCount > 0)
                            @php $accumulatedOffset = 0; @endphp
                            @foreach($leadCategories as $cat)
                                @if($cat['count'] > 0)
                                    @php
                                        $fraction = $cat['count'] / $totalLeadCount;
                                        $segmentRawLen = $fraction * $semiCircumference;
                                        $visibleLen = max(1, $segmentRawLen - $gap);
                                    @endphp
                                    <circle cx="145" cy="128" r="{{ $radius }}" fill="none" stroke="{{ $cat['color'] }}" stroke-width="24"
                                            stroke-dasharray="{{ round($visibleLen, 2) }} {{ round($circumference, 2) }}"
                                            stroke-dashoffset="{{ round(-$accumulatedOffset, 2) }}" transform="rotate(180 145 128)">
                                        <title>{{ $cat['label'] }} ({{ $cat['sub'] }}): {{ $cat['count'] }} bookings ({{ round($fraction * 100) }}%)</title>
                                    </circle>
                                    @php $accumulatedOffset += $segmentRawLen; @endphp
                                @endif
                            @endforeach
                        @endif
                    </svg>
                    <div class="absolute inset-x-0 bottom-2 flex flex-col items-center text-center pointer-events-none">
                        <span class="text-3xl font-black text-[#1D1D1F] leading-none">{{ $avgLeadDays }}</span>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider mt-1">days ahead on average</span>
                    </div>
                </div>
            </div>
            <p class="mt-3 pt-2.5 border-t border-[#F2F2F7] text-xs text-[#6E6E73]">
                @if($avgLeadDays >= 14)
                    Most guests plan early, so promos 2+ weeks before a batch work well.
                @elseif($avgLeadDays >= 7)
                    Most guests book about a week before the dive.
                @else
                    Most guests book last minute, so keep a few slots open close to the date.
                @endif
            </p>
        </div>
    </div>

    <!-- Bookings and guest list (can be collapsed) -->
    <div x-data="{ showBookingsList: false }" class="bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
        
        <!-- Toggle Header -->
        <button type="button" 
                @click="showBookingsList = !showBookingsList"
                class="w-full p-4 sm:p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F2F2F7]/50 transition-colors cursor-pointer select-none">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>All bookings in this period</h3>
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                        {{ count($b['bookings_list'] ?? []) }}
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Who booked, which package, how many divers and which batch</p>
            </div>
            
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-bold text-[#780000]" x-text="showBookingsList ? 'Hide list' : 'Show list'"></span>
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
                    <thead class="border-b border-[#E5E5EA] text-xs font-semibold text-[#8E8E93]">
                        <tr>
                            <th class="p-4 pl-6">Booking / booked on</th>
                            <th class="p-4">Guest</th>
                            <th class="p-4">Package</th>
                            <th class="p-4 text-center">Divers</th>
                            <th class="p-4">Batch</th>
                            <th class="p-4 pr-6 text-center">Status</th>
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
                                    <span class="font-bold text-[#1D1D1F]">{{ $bk->participants->count() }}</span>
                                </td>
                                <td class="p-4">
                                    @if($bk->batch)
                                        <span class="font-bold text-[#1D1D1F] block">{{ $bk->batch->display_name }}</span>
                                        <span class="text-sm text-[#8E8E93]">
                                            {{ $bk->batch->formatted_date_range }}
                                        </span>
                                    @else
                                        <span class="text-sm text-[#8E8E93]">No batch yet</span>
                                    @endif
                                </td>
                                <td class="p-4 pr-6 text-center">
                                    @php [$stLabel, $stTone] = $statusLabel($bk->status); @endphp
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold whitespace-nowrap {{ $stTone }}">{{ $stLabel }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-6 text-center text-sm text-[#8E8E93]">No bookings in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
