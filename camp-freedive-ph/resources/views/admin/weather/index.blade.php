@extends('layouts.admin')

@section('title', 'Safety Monitoring | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Safety Monitoring</h1>
        </div>
    </div>

    <!-- Critical / High Risk Advisory Banner -->
    @if($criticalCount > 0)
    <div class="banner banner-error flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="w-3 h-3 rounded-full bg-rose-600 animate-pulse"></span>
            <div>
                <strong>{{ $criticalCount }} upcoming batch(es)</strong> are currently classified as <strong>High Risk</strong> or <strong>Critical Risk</strong>. Review conditions immediately to confirm go/no-go or initiate cancellation flows.
            </div>
        </div>
        <span class="font-bold uppercase tracking-wider text-xs text-[#780000] shrink-0">Safety Advisory</span>
    </div>
    @endif

    <!-- Scheduled Batches Safety Section -->
    <div class="space-y-4">

        <!-- Risk Filters and Toolbar -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-2.5 sm:p-3 shadow-2xs">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2.5 sm:gap-3">
                
                <!-- Risk Level Filters -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 scrollbar-none -mx-0.5 px-0.5">
                    <a href="{{ request()->fullUrlWithQuery(['risk' => '']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ !request('risk') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Risks
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['risk' => 'very_safe']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ request('risk') === 'very_safe' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Very Safe
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['risk' => 'safe']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ request('risk') === 'safe' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Safe
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['risk' => 'moderate']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ request('risk') === 'moderate' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Moderate
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['risk' => 'high_risk']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ request('risk') === 'high_risk' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        High Risk
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['risk' => 'critical_risk']) }}" 
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 {{ request('risk') === 'critical_risk' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Critical
                    </a>
                </div>

                <!-- Advanced Filters -->
                <div class="flex items-center gap-2 self-end md:self-auto shrink-0" x-data="{ openFilters: false }">
                    @if(request()->anyFilled(['status', 'date_from', 'date_to']))
                        <a href="{{ route('admin.weather.index', ['risk' => request('risk')]) }}" 
                           class="text-xs text-[#6E6E73] hover:text-[#780000] underline font-medium px-2 py-1">
                            Clear Extras
                        </a>
                    @endif

                    <div class="relative shrink-0">
                        <button type="button" 
                                @click="openFilters = !openFilters" 
                                class="btn-secondary flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold whitespace-nowrap shrink-0 cursor-pointer">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4 h-4 object-contain inline-block shrink-0">
                            <span class="whitespace-nowrap">Filter</span>
                            @if(request()->anyFilled(['status', 'date_from', 'date_to']))
                                <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                            @endif
                        </button>

                        <!-- Filter Dropdown Menu -->
                        <div x-show="openFilters" 
                             @click.outside="openFilters = false" 
                             x-cloak 
                             x-transition:enter="transition ease-out duration-150 transform"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100 transform"
                             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                             x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                             class="popover-panel absolute right-0 mt-2 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="popover-title">Filter Batches</h4>
                                <a href="{{ route('admin.weather.index') }}" class="popover-reset">Reset</a>
                            </div>

                            <form action="{{ route('admin.weather.index') }}" method="GET" class="space-y-3 text-sm">
                                <input type="hidden" name="risk" value="{{ request('risk') }}">

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Batch Status</label>
                                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Statuses</option>
                                        <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                                        <option value="rescheduled" {{ request('status') === 'rescheduled' ? 'selected' : '' }}>Rescheduled</option>
                                        <option value="cancelled_by_camp" {{ request('status') === 'cancelled_by_camp' ? 'selected' : '' }}>Cancelled</option>
                                    </select>
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full py-2 text-xs font-bold shadow-2xs">
                                        Apply Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Weekend Batches Forecast List -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5">
            @forelse($batches as $batch)
            @php
                $mlData = $batchMLAssessments[$batch->id] ?? null;
                $day1 = $batch->riskAssessments->where('day_number', 1)->first() ?? $batch->riskAssessments->filter(fn($a) => $a->dive_date?->toDateString() === $batch->start_date?->toDateString())->first();
                $day2 = $batch->riskAssessments->where('day_number', 2)->first() ?? $batch->riskAssessments->filter(fn($a) => $a->dive_date?->toDateString() === $batch->end_date?->toDateString())->first();
                $override = $batch->manualOverrides->first();
                $isConcluded = ($batch->end_date && $batch->end_date->isPast()) || in_array($batch->status, ['completed', 'cancelled_by_camp']);
                $hasML = isset($batchMLAssessments[$batch->id]) && !$isConcluded && $isMLReachable;

                // Overall result (same as show.blade.php)
                $cardRec = $mlData['overall_recommendation'] ?? null;
                if (!$cardRec) {
                    if ($day1 && $day2) {
                        $r1 = \App\Services\WeatherForecastService::RISK_RANK[$day1->overall_classification] ?? 1;
                        $r2 = \App\Services\WeatherForecastService::RISK_RANK[$day2->overall_classification] ?? 1;
                        $cardRec = array_search(max($r1, $r2), \App\Services\WeatherForecastService::RISK_RANK) ?: 'Safe';
                    } else {
                        $cardRec = $batch->risk_badge['label'] ?? 'Safe';
                    }
                }

                $cardBadgeClass = match($cardRec) {
                    'Very Safe', 'Safe' => 'bg-emerald-600 text-white',
                    'Moderate' => 'bg-amber-500 text-white',
                    'High Risk' => 'bg-rose-600 text-white',
                    'Critical Risk', 'Critical' => 'bg-red-700 text-white',
                    default => 'bg-gray-600 text-white',
                };

                $day1Rec = $mlData['day1']['overall_recommendation'] ?? ($day1->overall_classification ?? null);
                $day2Rec = $mlData['day2']['overall_recommendation'] ?? ($day2->overall_classification ?? null);
                $day1LineColor = match($day1Rec) {
                    'Very Safe', 'Safe' => 'bg-emerald-500',
                    'Moderate' => 'bg-amber-500',
                    'High Risk' => 'bg-rose-500',
                    'Critical Risk' => 'bg-red-600',
                    default => 'bg-gray-300',
                };
                $day2LineColor = match($day2Rec) {
                    'Very Safe', 'Safe' => 'bg-emerald-500',
                    'Moderate' => 'bg-amber-500',
                    'High Risk' => 'bg-rose-500',
                    'Critical Risk' => 'bg-red-600',
                    default => 'bg-gray-300',
                };
            @endphp
            
            <div tabindex="0" 
                 role="link" 
                 onclick="window.location='{{ route('admin.weather.show', $batch) }}'" 
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('admin.weather.show', $batch) }}';}" 
                 class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 hover:ring-1 hover:ring-[#780000] focus:outline-none focus:ring-2 focus:ring-[#780000] active:scale-[0.99] cursor-pointer transition-all flex flex-col justify-between space-y-3 shadow-2xs group">
                
                <!-- Batch Information -->
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="font-extrabold text-[#1D1D1F] group-hover:text-[#780000] text-base block leading-tight transition-colors">
                                {{ $batch->batch_number }}
                            </span>
                            <div class="text-xs text-[#6E6E73] mt-0.5 font-medium">
                                {{ $batch->formatted_date_range }}
                            </div>
                            @if(!$isConcluded && isset($mlData['routed_horizon_bucket']))
                                <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA]">
                                        <svg class="w-2.5 h-2.5 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        @if(!empty($mlData['is_beyond_7d']))
                                            <span>Climatology (&gt;168h)</span>
                                        @else
                                            <span>ML Model: H = {{ $mlData['routed_horizon_bucket'] }}h</span>
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </div>
                        <div class="text-right shrink-0 space-y-0.5">
                            <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wide inline-block {{ $cardBadgeClass }}">
                                {{ $cardRec }}
                            </span>
                            @php
                                $peakRank = fn ($a) => \App\Services\WeatherForecastService::RISK_RANK[$a?->peak_classification] ?? 0;
                                $peakDay = $peakRank($day2) > $peakRank($day1) ? $day2 : $day1;
                                $cardPeak = !$isConcluded && $peakDay && $peakRank($peakDay) >= (\App\Services\WeatherForecastService::RISK_RANK[$cardRec] ?? 0)
                                    ? $peakDay->peak_label : null;
                            @endphp
                            @if($cardPeak)
                                <span class="text-[11px] font-semibold text-[#B45309] block max-w-[160px] ml-auto leading-snug">{{ $cardPeak }}</span>
                            @endif
                            @if($isConcluded)
                                <span class="text-[10px] uppercase font-extrabold text-slate-500 block">Concluded</span>
                            @elseif($batch->status === 'cancelled_by_camp')
                                <span class="text-xs text-rose-700 font-bold block">Cancelled</span>
                            @endif
                        </div>
                    </div>

                    <!-- Day 1 & Day 2 Forecast Conditions -->
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        
                        <!-- Day 1 Assessment -->
                        <div class="flex items-stretch gap-2">
                            <div class="w-1 rounded-full {{ $day1LineColor }} shrink-0"></div>
                            <div class="flex-1 min-w-0">
                                <span class="text-xs font-bold text-[#1D1D1F] block truncate">
                                    Day 1 ({{ $batch->start_date->format('D') }})
                                </span>
                                @if($day1)
                                    <div class="text-[11px] text-[#6E6E73] mt-0.5">
                                        Worst: <strong class="text-[#1D1D1F]">{{ $day1->worst_hour ? $day1->worst_hour->format('g:i A') : 'N/A' }}</strong>
                                    </div>
                                @else
                                    <span class="text-[11px] text-[#8E8E93] italic block mt-0.5">Not Assessed</span>
                                @endif
                            </div>
                        </div>

                        <!-- Day 2 Assessment -->
                        <div class="flex items-stretch gap-2">
                            <div class="w-1 rounded-full {{ $day2LineColor }} shrink-0"></div>
                            <div class="flex-1 min-w-0">
                                <span class="text-xs font-bold text-[#1D1D1F] block truncate">
                                    Day 2 ({{ $batch->end_date ? $batch->end_date->format('D') : $batch->start_date->copy()->addDay()->format('D') }})
                                </span>
                                @if($day2)
                                    <div class="text-[11px] text-[#6E6E73] mt-0.5">
                                        Worst: <strong class="text-[#1D1D1F]">{{ $day2->worst_hour ? $day2->worst_hour->format('g:i A') : 'N/A' }}</strong>
                                    </div>
                                @else
                                    <span class="text-[11px] text-[#8E8E93] italic block mt-0.5">Not Assessed</span>
                                @endif
                            </div>
                        </div>

                    </div>

                    <!-- Manual Override Notice -->
                    @if($override)
                        <div class="mt-2.5 p-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-900">
                            <span class="font-bold">Manual Override:</span> {{ !empty($override->active_advisories) ? implode(', ', $override->active_advisories) : 'Advisory Active' }}
                            <span class="text-xs text-amber-800 block mt-0.5">{{ Str::limit($override->reason, 60) }}</span>
                        </div>
                    @endif

                </div>

            </div>
            @empty
            <div class="col-span-full py-10 text-center text-[#6E6E73] bg-white rounded-xl border border-[#E5E5EA]">
                <p class="text-base font-bold text-[#1D1D1F]">No batches found for weather monitoring</p>
                <p class="text-xs text-[#6E6E73] mt-1">Adjust your filters or verify scheduled batches.</p>
            </div>
            @endforelse
        </div>

        <!-- Pagination -->
        <div class="bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] overflow-hidden [&>*]:border-t-0">
            {{ $batches->links() }}
        </div>
    </div>
</div>
@endsection
