@php
    $op = $data['operations'] ?? [];
    $co = $data['coaches'] ?? [];
@endphp

<div class="space-y-6">

    <!-- Top Operational KPI Summary -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 items-stretch gap-4 sm:gap-6 divide-y sm:divide-y-0 divide-[#E5E5EA]">
            
            <!-- Average Occupancy / Fill Rate -->
            <div class="px-2 sm:px-4 py-1">
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Average Camp Fill Rate</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5 break-words">
                    {{ $op['avg_occupancy'] ?? 0 }}%
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $op['total_booked_pax'] ?? 0 }} of {{ $op['total_capacity_slots'] ?? 0 }} slots booked</span>
                    <span class="px-1.5 py-0.5 rounded text-xs font-bold text-[#6E6E73] bg-[#F2F2F7] shrink-0">{{ $op['total_batches'] ?? 0 }} batches</span>
                </div>
            </div>

            <!-- Weekend vs Weekday Bookings -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Weekend vs Weekday</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#780000] mt-0.5 break-words">
                    {{ $op['weekend_occupancy'] ?? 0 }}% <span class="text-xs sm:text-sm font-semibold text-[#8E8E93]">weekend</span>
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>Weekday avg: {{ $op['weekday_occupancy'] ?? 0 }}%</span>
                    @php
                        $diff = ($op['weekend_occupancy'] ?? 0) - ($op['weekday_occupancy'] ?? 0);
                    @endphp
                    @if($diff > 0)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-amber-700 bg-amber-50 shrink-0">+{{ round($diff, 1) }}% Weekend Surge</span>
                    @elseif($diff < 0)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-sky-700 bg-sky-50 shrink-0">Weekday Heavy</span>
                    @else
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-[#6E6E73] bg-[#F2F2F7] shrink-0">Balanced</span>
                    @endif
                </div>
            </div>

            <!-- Coach Ratio Compliance -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Coach Safety Compliance</span>
                <div class="text-xl sm:text-2xl font-extrabold mt-0.5 break-words {{ ($op['safety_compliance_rate'] ?? 100) >= 95 ? 'text-emerald-700' : 'text-amber-600' }}">
                    {{ $op['safety_compliance_rate'] ?? 100 }}%
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $op['compliant_batches_count'] ?? 0 }} of {{ $op['total_batches'] ?? 0 }} batches staffed</span>
                    @php
                        $unstaffed = ($op['total_batches'] ?? 0) - ($op['compliant_batches_count'] ?? 0);
                    @endphp
                    @if($unstaffed <= 0)
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-emerald-700 bg-emerald-50 shrink-0">1:4 Ratio Met</span>
                    @else
                        <span class="px-1.5 py-0.5 rounded text-xs font-bold text-amber-700 bg-amber-50 shrink-0">{{ $unstaffed }} Need Coaches</span>
                    @endif
                </div>
            </div>

            <!-- Total Coach Assignments -->
            <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Total Coach Assignments</span>
                <div class="text-xl sm:text-2xl font-extrabold text-[#00C3D0] mt-0.5 break-words">
                    {{ $co['total_assignments_period'] ?? 0 }} <span class="text-xs sm:text-sm font-semibold text-[#8E8E93]">trips</span>
                </div>
                <div class="text-xs sm:text-sm text-[#8E8E93] flex items-center justify-between gap-2 mt-1">
                    <span>{{ $co['total_active_coaches'] ?? 0 }} active coaches</span>
                    <span class="px-1.5 py-0.5 rounded text-xs font-bold text-[#00C3D0] bg-[#E0F7FA] shrink-0">{{ ($co['total_assignments_period'] ?? 0) * 2 }} dive days</span>
                </div>
            </div>

        </div>
    </div>

    <!-- Coach Workload & Assigned Trips Table (Collapsible) -->
    <div x-data="{ showCoachWorkload: false }" class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs space-y-0">
        
        <!-- Toggle Header -->
        <button type="button" 
                @click="showCoachWorkload = !showCoachWorkload"
                class="w-full p-4 sm:p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F2F2F7]/50 transition-colors cursor-pointer select-none">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Coach Workload &amp; Assigned Trips</h3>
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                        {{ count($co['coaches'] ?? []) }}
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Overview of camp batches and dive days assigned to each coach for the selected period.</p>
            </div>
            
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-bold text-[#780000]" x-text="showCoachWorkload ? 'Hide List' : 'Show List'"></span>
                <svg class="w-4 h-4 text-[#6E6E73] transform transition-transform duration-200" 
                     :class="showCoachWorkload ? 'rotate-180' : ''" 
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
        </button>

        <!-- Collapsible Content -->
        <div x-show="showCoachWorkload" x-collapse x-cloak class="border-t border-[#F2F2F7]">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[650px]">
                    <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                        <tr>
                            <th class="p-3.5 pl-5">Coach Name</th>
                            <th class="p-3.5 text-center">Status</th>
                            <th class="p-3.5 text-center">Assigned Batches</th>
                            <th class="p-3.5 text-center">Total Dive Days</th>
                            <th class="p-3.5 pr-5 text-center">Leave / Swap Requests</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E5EA]">
                        @forelse($co['coaches'] ?? [] as $coach)
                            <tr class="hover:bg-[#F2F2F7] transition-colors text-sm sm:text-sm">
                                <td class="p-3.5 pl-5">
                                    <div>
                                        <span class="font-bold text-[#1D1D1F] block">{{ $coach['name'] }}</span>
                                        <span class="text-sm text-[#8E8E93]">{{ $coach['email'] }}</span>
                                    </div>
                                </td>
                                <td class="p-3.5 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-sm font-bold {{ $coach['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ ucfirst($coach['status']) }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-center font-bold text-[#1D1D1F]">{{ $coach['assignments_count'] }} {{ Str::plural('trip', $coach['assignments_count']) }}</td>
                                <td class="p-3.5 text-center font-medium text-[#6E6E73]">{{ $coach['estimated_dive_days'] }} days</td>
                                <td class="p-3.5 pr-5 text-center">
                                    <span class="font-bold {{ $coach['releases_count'] > 0 ? 'text-rose-600' : 'text-[#8E8E93]' }}">
                                        {{ $coach['releases_count'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-6 text-center text-sm text-[#8E8E93]">No coaches found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Batch Schedule & Assigned Coaches Table (Collapsible) -->
    <div x-data="{ showBatchSchedule: false }" class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs space-y-0">
        
        <!-- Toggle Header -->
        <button type="button" 
                @click="showBatchSchedule = !showBatchSchedule"
                class="w-full p-4 sm:p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F2F2F7]/50 transition-colors cursor-pointer select-none">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Batch Schedule &amp; Assigned Coaches</h3>
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                        {{ count($op['batches_list'] ?? []) }}
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Batch dates, slot capacity, and assigned coaches for each camp trip.</p>
            </div>
            
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-bold text-[#780000]" x-text="showBatchSchedule ? 'Hide List' : 'Show List'"></span>
                <svg class="w-4 h-4 text-[#6E6E73] transform transition-transform duration-200" 
                     :class="showBatchSchedule ? 'rotate-180' : ''" 
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
        </button>

        <!-- Collapsible Content -->
        <div x-show="showBatchSchedule" x-collapse x-cloak class="border-t border-[#F2F2F7]">
            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[900px]">
                    <thead class="bg-[#F2F2F7] border-b border-[#F2F2F7] text-sm uppercase font-bold text-[#6E6E73]">
                        <tr>
                            <th class="p-4 pl-6">Batch / Dates</th>
                            <th class="p-4">Course Type</th>
                            <th class="p-4 text-center">Booked Guests</th>
                            <th class="p-4 min-w-[150px]">Slots Filled</th>
                            <th class="p-4 min-w-[200px]">Assigned Coaches</th>
                            <th class="p-4 text-center">Coach Coverage</th>
                            <th class="p-4 pr-6 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($op['batches_list'] ?? [] as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $cap = $batch->max_capacity ?: 20;
                                $occ = $cap > 0 ? round(($pax / $cap) * 100) : 0;
                                $distinctCoaches = $batch->assigned_coaches;
                                $coachesCnt = $distinctCoaches->count();
                                $required = $pax > 0 ? (int) ceil($pax / 4) : 0;
                            @endphp
                            <tr onclick="window.location='{{ route('admin.batches.show', $batch) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm sm:text-sm group">
                                <td class="p-4 pl-6 font-mono">
                                    <span class="font-bold text-[#780000] block text-sm group-hover:underline">{{ $batch->display_name }}</span>
                                    <span class="text-sm text-[#8E8E93]">
                                        @php 
                                            $bStart = \Carbon\Carbon::parse($batch->start_date); 
                                            $bEnd = \Carbon\Carbon::parse($batch->end_date); 
                                        @endphp
                                        {{ $bStart->year === $bEnd->year ? $bStart->format('M d') . ' - ' . $bEnd->format('M d, Y') : $bStart->format('M d, Y') . ' - ' . $bEnd->format('M d, Y') }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="font-semibold text-[#1D1D1F] block">{{ ucfirst($batch->class_type ?? 'Discovery') }}</span>
                                </td>
                                <td class="p-4 text-center font-bold text-[#1D1D1F]">{{ $pax }} / {{ $cap }}</td>
                                <td class="p-4 min-w-[150px]">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="font-bold {{ $occ >= 90 ? 'text-[#780000]' : 'text-[#3A3A3C]' }}">{{ $occ }}%</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-[#E5E5EA] overflow-hidden">
                                            <div class="h-full {{ $occ >= 90 ? 'bg-[#780000]' : ($occ >= 50 ? 'bg-[#00C3D0]' : 'bg-gray-400') }}" style="width: {{ min(100, $occ) }}%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 min-w-[200px]">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        @forelse($distinctCoaches as $coach)
                                            <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                                                {{ $coach->name }}
                                            </span>
                                        @empty
                                            <span class="text-sm text-rose-600 font-semibold">No coach assigned</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="p-4 text-center">
                                    <div class="text-sm font-bold text-[#1D1D1F]">
                                        {{ $coachesCnt }} Assigned
                                    </div>
                                    <div class="text-sm text-[#8E8E93]">
                                        Rec: {{ $required }} {{ Str::plural('coach', $required) }}
                                    </div>
                                </td>
                                <td class="p-4 pr-6 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-sm font-bold {{ $batch->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : ($batch->status === 'confirmed' ? 'bg-sky-50 text-sky-700' : ($batch->status === 'rescheduled' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-700')) }}">
                                        {{ ucfirst($batch->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-6 text-center text-sm text-[#8E8E93]">No batches found for the selected reporting period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
