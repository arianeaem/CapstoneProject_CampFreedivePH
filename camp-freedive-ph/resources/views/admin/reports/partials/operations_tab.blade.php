@php
    $op = $data['operations'] ?? [];
    $co = $data['coaches'] ?? [];
    $ratio = (int) ($op['coach_ratio'] ?? 4);
    $running = (int) ($op['running_batches_count'] ?? 0);
    $needCoaches = $running - (int) ($op['compliant_batches_count'] ?? 0);
    $batchStatus = fn ($status) => match ($status) {
        'completed' => ['Finished', 'bg-[#00C3D0]/10 text-[#00636A]'],
        'cancelled', 'cancelled_by_camp' => ['Cancelled', 'bg-[#FEF2F2] text-[#B91C1C]'],
        'full' => ['Full', 'bg-[#F8EAEA] text-[#780000]'],
        'confirmed' => ['Confirmed', 'bg-[#F8EAEA] text-[#780000]'],
        default => ['Open for booking', 'bg-[#F2F2F7] text-[#3A3A3C]'],
    };
@endphp

<div class="space-y-5">

    @include('admin.reports.partials.summary_cards', ['cards' => [
        [
            'label' => 'Batches',
            'value' => $op['total_batches'] ?? 0,
            'hint' => ($op['active_batches'] ?? 0) . ' still to run · ' . ($op['completed_batches'] ?? 0) . ' finished · ' . ($op['cancelled_batches'] ?? 0) . ' cancelled',
            'tone' => 'text-[#1D1D1F]',
        ],
        [
            'label' => 'How full batches are',
            'value' => ($op['avg_occupancy'] ?? 0) . '%',
            'hint' => ($op['total_booked_pax'] ?? 0) . ' of ' . ($op['total_capacity_slots'] ?? 0) . ' diver slots booked'
                . (($op['full_capacity_batches'] ?? 0) > 0 ? ' · ' . $op['full_capacity_batches'] . ' almost full' : ''),
        ],
        [
            'label' => 'Enough coaches',
            'value' => ($op['safety_compliance_rate'] ?? 100) . '%',
            'hint' => ($op['compliant_batches_count'] ?? 0) . ' of ' . $running . ' batches have 1 coach for every ' . $ratio . ' divers',
            'tone' => $needCoaches > 0 ? 'text-[#B45309]' : 'text-[#00838C]',
            'badge' => $needCoaches > 0 ? $needCoaches . ' need coaches' : 'All covered',
            'badgeTone' => $needCoaches > 0 ? 'bg-[#FFFBEB] text-[#B45309]' : 'bg-[#00C3D0]/10 text-[#00636A]',
        ],
        [
            'label' => 'Coach trips',
            'value' => $co['total_assignments_period'] ?? 0,
            'hint' => ($co['total_dive_days'] ?? 0) . ' dive days · ' . ($co['total_active_coaches'] ?? 0) . ' active ' . Str::plural('coach', $co['total_active_coaches'] ?? 0),
            'tone' => 'text-[#00838C]',
        ],
    ]])

    @if(($op['weekday_occupancy'] ?? 0) > 0)
        <p class="text-sm text-[#6E6E73]">Weekend batches are <strong class="text-[#1D1D1F]">{{ $op['weekend_occupancy'] }}%</strong> full and weekday batches are <strong class="text-[#1D1D1F]">{{ $op['weekday_occupancy'] }}%</strong> full.</p>
    @endif

    <!-- Coaches -->
    <div x-data="{ showCoachWorkload: false }" class="bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
        <button type="button" @click="showCoachWorkload = !showCoachWorkload"
                class="w-full p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F8F9FA] transition-colors cursor-pointer select-none">
            <div>
                <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>How busy each coach was <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">{{ count($co['coaches'] ?? []) }}</span></h3>
                <p class="text-sm text-[#8E8E93] mt-0.5">Batches and dive days per coach in this period (cancelled batches not counted)</p>
            </div>
            <span class="text-xs font-bold text-[#780000] shrink-0" x-text="showCoachWorkload ? 'Hide list' : 'Show list'"></span>
        </button>

        <div x-show="showCoachWorkload" x-collapse x-cloak class="border-t border-[#F2F2F7]">
            <div class="overflow-x-auto px-5 pb-4">
                <table class="w-full text-left text-sm min-w-[600px]">
                    <thead>
                        <tr class="text-[#8E8E93] text-xs border-b border-[#E5E5EA]">
                            <th class="py-2.5 pr-4 font-semibold">Coach</th>
                            <th class="py-2.5 pr-4 font-semibold">Account</th>
                            <th class="py-2.5 pr-4 font-semibold text-center">Batches</th>
                            <th class="py-2.5 pr-4 font-semibold text-center">Dive days</th>
                            <th class="py-2.5 font-semibold text-center">Asked to be released</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($co['coaches'] ?? [] as $coach)
                            <tr>
                                <td class="py-3 pr-4">
                                    <span class="block font-semibold text-[#1D1D1F]">{{ $coach['name'] }}</span>
                                    <span class="block text-xs text-[#8E8E93]">{{ $coach['email'] }}</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold {{ $coach['status'] === 'active' ? 'bg-[#00C3D0]/10 text-[#00636A]' : 'bg-[#F2F2F7] text-[#6E6E73]' }}">{{ $coach['status'] === 'active' ? 'Active' : ucfirst($coach['status']) }}</span>
                                </td>
                                <td class="py-3 pr-4 text-center font-bold text-[#1D1D1F]">{{ $coach['assignments_count'] }}</td>
                                <td class="py-3 pr-4 text-center text-[#3A3A3C]">{{ $coach['estimated_dive_days'] }}</td>
                                <td class="py-3 text-center font-bold {{ $coach['releases_count'] > 0 ? 'text-[#B91C1C]' : 'text-[#8E8E93]' }}">{{ $coach['releases_count'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-[#8E8E93]">No coaches yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Batches -->
    <div x-data="{ showBatchSchedule: false }" class="bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
        <button type="button" @click="showBatchSchedule = !showBatchSchedule"
                class="w-full p-5 flex items-center justify-between gap-4 text-left hover:bg-[#F8F9FA] transition-colors cursor-pointer select-none">
            <div>
                <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Every batch in this period <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">{{ count($op['batches_list'] ?? []) }}</span></h3>
                <p class="text-sm text-[#8E8E93] mt-0.5">How full each batch is and whether it has enough coaches</p>
            </div>
            <span class="text-xs font-bold text-[#780000] shrink-0" x-text="showBatchSchedule ? 'Hide list' : 'Show list'"></span>
        </button>

        <div x-show="showBatchSchedule" x-collapse x-cloak class="border-t border-[#F2F2F7]">
            <div class="overflow-x-auto px-5 pb-4">
                <table class="w-full text-left text-sm min-w-[760px]">
                    <thead>
                        <tr class="text-[#8E8E93] text-xs border-b border-[#E5E5EA]">
                            <th class="py-2.5 pr-4 font-semibold">Batch</th>
                            <th class="py-2.5 pr-4 font-semibold min-w-[150px]">Divers booked</th>
                            <th class="py-2.5 pr-4 font-semibold">Coaches</th>
                            <th class="py-2.5 pr-4 font-semibold">Enough coaches?</th>
                            <th class="py-2.5 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($op['batches_list'] ?? [] as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $cap = $batch->computed_capacity;
                                $occ = $cap > 0 ? min(100, round($pax / $cap * 100)) : 0;
                                $batchCoaches = $batch->assigned_coaches;
                                $required = $pax > 0 ? (int) ceil($pax / $ratio) : 0;
                                $isCancelled = in_array($batch->status, ['cancelled', 'cancelled_by_camp'], true);
                                [$stLabel, $stTone] = $batchStatus($batch->status);
                            @endphp
                            <tr onclick="window.location='{{ route('admin.batches.show', $batch) }}'" class="hover:bg-[#F8F9FA] cursor-pointer transition-colors">
                                <td class="py-3 pr-4">
                                    <span class="block font-semibold text-[#1D1D1F] whitespace-nowrap">{{ $batch->start_date->format('M d') }} – {{ $batch->end_date?->format('M d, Y') }}</span>
                                    <span class="block text-xs text-[#8E8E93]">{{ $batch->batch_number }}</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="font-bold text-[#1D1D1F]">{{ $pax }} of {{ $cap }}</span>
                                        <span class="text-[#8E8E93]">{{ $occ }}%</span>
                                    </div>
                                    <div class="w-full h-1.5 rounded-full bg-[#F2F2F7] overflow-hidden">
                                        <div class="h-full {{ $occ >= 90 ? 'bg-[#780000]' : 'bg-[#00C3D0]' }}" style="width: {{ $occ }}%;"></div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-1 flex-wrap">
                                        @forelse($batchCoaches as $c)
                                            <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-[#F2F2F7] text-[#1D1D1F]">{{ $c->name }}</span>
                                        @empty
                                            <span class="text-xs text-[#8E8E93]">None yet</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="py-3 pr-4 whitespace-nowrap">
                                    @if($isCancelled)
                                        <span class="text-[#8E8E93]">—</span>
                                    @elseif($pax === 0)
                                        <span class="text-[#8E8E93]">No divers yet</span>
                                    @elseif($batchCoaches->count() >= $required)
                                        <span class="font-semibold text-[#00838C]">Yes &middot; {{ $batchCoaches->count() }} of {{ $required }}</span>
                                    @else
                                        <span class="font-semibold text-[#B45309]">Needs {{ $required - $batchCoaches->count() }} more</span>
                                    @endif
                                </td>
                                <td class="py-3">
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold whitespace-nowrap {{ $stTone }}">{{ $stLabel }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-[#8E8E93]">No batches in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
