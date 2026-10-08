@extends('layouts.admin')

@section('title', $batch->display_name . ' - Weather Risk Assessment | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('weather.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Safety Monitoring</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $batch->batch_number }}</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{
    openOverrideModal: false,
    openCancelModal: false,
    openActionsMenu: false,
    cancelReason: '{{ $overallClassification === 'Critical Risk' ? 'Critical Risk sea conditions (strong wind, big waves or currents)' : '' }}'
}">
    
    <!-- Top Header Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                    {{ $batch->batch_number }}
                </h1>
                @if($batch->status === 'cancelled_by_camp')
                    <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-[#FEF2F2] text-[#DC2626]">
                        Cancelled by Camp
                    </span>
                @endif
            </div>
            
            <div class="mt-1 text-sm text-[#6E6E73] space-y-1">
                <div class="flex items-center gap-1 font-medium text-[#1D1D1F]">
                    <span>{{ $batch->start_date->format('F d, Y (l)') }} - {{ $batch->end_date->format('F d, Y (l)') }}</span>
                </div>
            </div>
        </div>

        <!-- Action Controls: Primary Button + 3-Dot More Actions Menu -->
        <div class="flex items-center gap-2.5 flex-wrap">

            @if($vm->isConcluded)
                <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-100 text-slate-700 text-xs font-bold shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-slate-500"></span>
                    <span>Concluded Session · Archived Records</span>
                </div>
            @endif

            <!-- Primary Action: Run Live Assessment -->
            @if(!$vm->isConcluded)
                <form action="{{ route('admin.weather.assess', $batch) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="btn-primary min-h-[44px] px-4 py-2.5 text-sm font-bold shadow-2xs inline-flex items-center justify-center gap-2 active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                        <svg class="w-4 h-4 text-white shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        <span>Run Live Assessment</span>
                    </button>
                </form>
            @endif

            <!-- More actions menu -->
            <div class="relative inline-block text-left" @click.outside="openActionsMenu = false">
                <button type="button" 
                        @click="openActionsMenu = !openActionsMenu"
                        :aria-expanded="openActionsMenu"
                        aria-haspopup="true"
                        aria-label="More batch safety actions"
                        class="min-h-[44px] min-w-[44px] w-11 h-11 inline-flex items-center justify-center rounded-xl bg-white border border-[#E5E5EA] text-[#1D1D1F] hover:bg-[#F2F2F7] hover:border-[#D1D1D6] active:scale-[0.97] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000] cursor-pointer shadow-2xs">
                    <svg class="w-5 h-5 text-[#1D1D1F]" viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="12" cy="12" r="1.75"/>
                        <circle cx="19" cy="12" r="1.75"/>
                        <circle cx="5" cy="12" r="1.75"/>
                    </svg>
                </button>

                <!-- Contextual Menu Dropdown -->
                <div x-show="openActionsMenu" 
                     x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="transform opacity-0 scale-95"
                     x-transition:enter-end="transform opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="transform opacity-100 scale-100"
                     x-transition:leave-end="transform opacity-0 scale-95"
                     class="menu-panel absolute right-0 mt-2">
                    
                    <!-- 1. Manual PAGASA Override -->
                    @if(!$vm->isConcluded)
                        <button type="button"
                                @click="openActionsMenu = false; openOverrideModal = true"
                                class="menu-item">
                            <span>Apply Manual Override</span>
                        </button>
                    @endif

                    <!-- 2. View Batch Profile -->
                    <a href="{{ route('admin.batches.show', $batch) }}" 
                       class="menu-item">
                        <span>View Batch Profile</span>
                    </a>

                    <!-- 3. Destructive: Cancel Batch Trigger -->
                    @if($batch->status !== 'cancelled_by_camp' && !$vm->isConcluded)
                        <div class="menu-divider"></div>

                        <button type="button"
                                @click="openActionsMenu = false; openCancelModal = true"
                                class="menu-item menu-item-danger">
                            <span>Cancel Batch (Weather Risk)</span>
                        </button>
                    @endif
                </div>
            </div>

        </div>
    </div>

    <!-- Main Safety Assessment Summary Banner -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-7 space-y-4 shadow-2xs">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-2">
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#6E6E73] block">
                    Overall Batch Assessment
                </span>
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="inline-flex items-center px-4 py-1.5 rounded-full text-base font-black tracking-wide uppercase {{ $vm->verdictBadgeClass() }}">
                        {{ $vm->verdict }}
                    </span>

                    <!-- 5-Bar Visual Score Gauge -->
                    <div class="flex items-center gap-1 sm:gap-1.5">
                        @for($i = 1; $i <= 5; $i++)
                            <div class="h-2 w-5 sm:w-6 rounded-full transition-all duration-300 {{ $i <= $vm->verdictScore() ? \App\ViewModels\BatchSafetyViewModel::SEGMENT_COLORS[$i - 1] : 'bg-[#E5E5EA]' }}"></div>
                        @endfor
                    </div>
                </div>
            </div>

            <!-- Override Status -->
            <div class="space-y-1 md:text-right shrink-0">
                <span class="text-[10px] uppercase font-extrabold tracking-wider text-[#6E6E73] block">Override Advisory Status</span>
                @if($latestOverride && count($latestOverride->active_advisories) > 0)
                    <span class="text-xs font-bold text-white bg-rose-600 px-3 py-1 rounded-full inline-block shadow-2xs">
                        Active: {{ implode(', ', $latestOverride->active_advisories) }}
                    </span>
                @else
                    <span class="text-xs font-bold text-[#065F46] bg-[#ECFDF5] px-3 py-1 rounded-full inline-block">
                        NOT OVERRIDDEN
                    </span>
                @endif
            </div>
        </div>

        <!-- Recommendation Text -->
        <div>
            <h3 class="text-sm font-bold text-[#1D1D1F]">
                {{ $vm->verdictMeaning() }}
            </h3>
        </div>

    </div>

    <!-- Model Comparison: Historical Model vs Legacy Model -->
    @if(!$vm->isConcluded)
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-extrabold text-[#1D1D1F]">Model Comparison</h2>
                <p class="text-xs text-[#6E6E73] mt-0.5">Two forecasts checked the same dates. Use them together to judge the trip.</p>
            </div>
            @if($modelComparison)
                <span class="inline-flex items-center gap-1.5 self-start px-3 py-1 rounded-full text-xs font-bold {{ $vm->modelsAgree() ? 'bg-[#ECFDF5] text-[#065F46]' : ($vm->bothModelsAvailable() ? 'bg-[#FFFBEB] text-[#92400E]' : 'bg-[#F2F2F7] text-[#6E6E73]') }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $vm->modelsAgree() ? 'bg-[#10B981]' : ($vm->bothModelsAvailable() ? 'bg-[#F59E0B]' : 'bg-[#AEAEB2]') }}"></span>
                    {{ $vm->modelsAgree() ? 'Both models agree' : ($vm->bothModelsAvailable() ? 'Models give different ratings' : 'Only one model available') }}
                </span>
            @endif
        </div>

        @if($modelComparison)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($vm->models() as $model)
                    <div class="rounded-xl border border-[#E5E5EA] p-4 space-y-3">
                        <div>
                            <div class="text-sm font-extrabold text-[#1D1D1F]">{{ $model['name'] }}</div>
                            <p class="text-xs text-[#6E6E73] leading-snug mt-0.5">{{ $model['about'] }}</p>
                        </div>

                        @if($model['available'])
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <span class="text-lg font-black {{ $model['tone'] }}">
                                    {{ $model['overall'] }}@if($model['seasonal'])<span class="text-xs font-semibold text-[#6E6E73]"> · typical season</span>@endif
                                </span>
                                @unless($model['seasonal'])
                                    <div class="flex items-center gap-1" role="img" aria-label="Safety score {{ $model['score'] }} out of 5">
                                        @for($i = 1; $i <= 5; $i++)
                                            <div class="h-1.5 w-5 rounded-full {{ $i <= $model['score'] ? \App\ViewModels\BatchSafetyViewModel::SEGMENT_COLORS[$i - 1] : 'bg-[#E5E5EA]' }}"></div>
                                        @endfor
                                    </div>
                                @endunless
                            </div>

                            <div class="rounded-lg bg-[#F8F9FA] divide-y divide-[#E5E5EA] text-xs">
                                @foreach($model['days'] as $d)
                                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                                        <span class="text-[#6E6E73]">
                                            <strong class="text-[#1D1D1F]">{{ $d['label'] }}</strong> · {{ $d['date'] }}
                                        </span>
                                        <span class="font-bold {{ $d['tone'] }}">{{ $d['classification'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($model['note'])
                            <p class="text-xs text-[#6E6E73] leading-snug">{{ $model['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($vm->bothModelsAvailable() && !$vm->modelsAgree())
                <p class="text-xs text-[#92400E] bg-[#FFFBEB] border border-[#FDE68A] rounded-lg px-3 py-2">
                    The two models don't fully agree. Check the hourly conditions below before confirming or cancelling this trip.
                </p>
            @endif
        @else
            <p class="text-xs text-[#6E6E73]">The model comparison isn't available right now. Try “Run Live Assessment” again in a few minutes.</p>
        @endif
    </div>
    @endif

    <!-- Day 1 & Day 2 Comparative Marine Condition Panels -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        @include('admin.weather.partials.day_panel', ['day' => $vm->day(1)])
        @include('admin.weather.partials.day_panel', ['day' => $vm->day(2)])
    </div>

    <!-- Assessment History & Audit Trail -->
    <div x-data="{ openAuditTrail: false }" class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        <button type="button" 
                @click="openAuditTrail = !openAuditTrail" 
                class="w-full p-5 sm:p-6 flex items-center justify-between text-left hover:bg-[#F2F2F7] transition-colors cursor-pointer select-none">
            <div class="flex items-center gap-3">
                <span class="text-base font-extrabold text-[#1D1D1F]">Assessment History</span>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                    {{ count($assessmentRuns) }} run(s)
                </span>
            </div>
            <div class="flex items-center gap-2 text-xs font-bold text-[#780000]">
                <span x-text="openAuditTrail ? 'Hide History' : 'View Audit History'"></span>
                <svg class="w-4 h-4 transition-transform duration-200" :class="openAuditTrail ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
        </button>

        <div x-show="openAuditTrail" x-cloak class="p-5 sm:p-6 pt-0 border-t border-[#E5E5EA] space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs text-[#6E6E73] pt-4 pb-2 border-b border-[#E5E5EA] gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-[#1D1D1F]">Checked with:</span>
                    <span class="inline-flex items-center gap-1.5 font-medium {{ $vm->isPrimaryActive ? 'text-emerald-700' : 'text-blue-700' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $vm->isPrimaryActive ? 'bg-emerald-500' : 'bg-blue-500' }}"></span>
                        <span>{{ $vm->checkedWithLabel() }}</span>
                    </span>
                </div>
                <span>Chronological assessment history</span>
            </div>

            @forelse($vm->runs() as $run)
            <div class="p-3.5 rounded-xl bg-[#F2F2F7] border border-[#E5E5EA] flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-3">
                    <div class="w-2.5 h-2.5 rounded-full bg-[#780000] shrink-0"></div>
                    <div>
                        <strong class="text-[#1D1D1F] font-bold">
                            Run at {{ $run['time'] }}
                        </strong>
                        <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                            <span class="text-xs text-[#6E6E73]">
                                Assessor: <strong class="text-[#1D1D1F]">{{ $run['assessor'] }}</strong>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 self-end sm:self-center">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[#6E6E73] font-semibold">Day 1:</span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $run['day1'] ? $run['day1']->classification_badge['class'] : 'bg-gray-100 text-gray-600' }}">
                            {{ $run['day1'] ? $run['day1']->overall_classification : 'N/A' }}
                        </span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[#6E6E73] font-semibold">Day 2:</span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $run['day2'] ? $run['day2']->classification_badge['class'] : 'bg-gray-100 text-gray-600' }}">
                            {{ $run['day2'] ? $run['day2']->overall_classification : 'N/A' }}
                        </span>
                    </div>

                    @if($run['overridden'])
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-[#FEF2F2] text-[#991B1B]">
                            Manual Override
                        </span>
                    @endif
                </div>
            </div>
            @empty
            <p class="text-xs text-[#6E6E73] py-2 text-center">No past assessment runs recorded yet.</p>
            @endforelse
        </div>
    </div>

    <!-- Manual Safety Override Modal -->
    <div x-show="openOverrideModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openOverrideModal = false">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-extrabold text-[#1D1D1F]">Apply Manual Override</h3>
                <button type="button" @click="openOverrideModal = false" aria-label="Close override modal" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <p class="text-xs text-[#6E6E73]">
                Forces both <strong>Day 1</strong> and <strong>Day 2</strong> to <strong>Critical Risk</strong> because of a PAGASA weather advisory or another hazard, such as an oil spill or a no-sail order.
            </p>

            <form action="{{ route('admin.weather.override', $batch) }}" method="POST" class="space-y-4 text-xs" x-data="{ otherHazard: '' }">
                @csrf

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1">
                        Tropical Cyclone Wind Signal (TCWS)
                    </label>
                    <select name="tcws_signal" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                        <option value="0">No Active TCWS Signal</option>
                        <option value="1">Signal No. 1 (At least High Risk)</option>
                        <option value="2">Signal No. 2 (At least High Risk)</option>
                        <option value="3" selected>Signal No. 3 (Forces Critical Risk)</option>
                        <option value="4">Signal No. 4 (Forces Critical Risk)</option>
                        <option value="5">Signal No. 5 (Forces Critical Risk)</option>
                    </select>
                </div>

                <div class="space-y-2 p-3.5 rounded-xl">
                    <span class="block font-bold text-[#1D1D1F] mb-1 uppercase tracking-wider text-xs">Active Severe Marine Advisories</span>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="gale_warning" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">PAGASA Marine Gale Warning</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="thunderstorm_advisory" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Severe Thunderstorm / Lightning Advisory</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="typhoon_within_distance" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Typhoon within Safety Distance (Batangas Coast)</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="tsunami_warning" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Tsunami / Severe Marine Hazard Warning</span>
                    </label>
                </div>

                <!-- Other (non-weather) hazard -->
                <div class="space-y-2 p-3.5 rounded-xl">
                    <label for="other-hazard" class="block font-bold text-[#1D1D1F] uppercase tracking-wider text-xs">Other Hazard (Not Weather)</label>
                    <select id="other-hazard" name="other_hazard" x-model="otherHazard" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                        <option value="">None</option>
                        @foreach(\App\Http\Controllers\Admin\WeatherSafetyController::OTHER_HAZARDS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <div x-show="otherHazard === 'other'" x-cloak>
                        <input type="text" name="other_hazard_detail" maxlength="150" :required="otherHazard === 'other'"
                               placeholder="Describe the hazard, e.g. Fish kill reported near Mainit Point"
                               class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white">
                    </div>
                    <p class="text-[#6E6E73]">Choosing a hazard also forces <strong>Critical Risk</strong> for both days.</p>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1">
                        Details / Source <span class="text-[#780000]">*</span>
                    </label>
                    <textarea name="reason" required rows="2" placeholder="e.g. PAGASA Severe Weather Bulletin #4, or Coast Guard advisory on oil spill near Anilao" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white"></textarea>
                </div>

                <div class="banner banner-error">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" name="cancel_batch" value="1" class="rounded border-[#D1D1D6] text-[#780000] mt-0.5">
                        <span class="font-bold text-[#991B1B]">
                            Also cancel this batch now: every guest gets a full refund and the cancellation email.
                        </span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#E5E5EA]">
                    <button type="button" @click="openOverrideModal = false" class="btn-secondary px-3.5 py-1.5 text-xs">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-1.5 text-xs font-bold shadow-2xs">
                        Apply Override
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch Cancellation Panel -->
    @php($impact = $vm->cancelImpact())
    <div x-show="openCancelModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 space-y-5 shadow-2xl border-l border-[#E5E5EA]"
             x-data="{ understood: false }" @click.outside="openCancelModal = false">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-extrabold text-[#1D1D1F]">Cancel this batch</h3>
                    <p class="text-xs text-[#6E6E73] mt-0.5">{{ $batch->batch_number }} &middot; {{ $batch->formatted_date_range }}</p>
                </div>
                <button type="button" @click="openCancelModal = false" aria-label="Close" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">&#10005;</button>
            </div>

            <!-- Who is affected -->
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $impact['bookings'] }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Bookings</div>
                </div>
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $impact['divers'] }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Divers</div>
                </div>
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $impact['refunds'] }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Will get a refund</div>
                </div>
            </div>

            <!-- What happens -->
            <div class="space-y-1.5 text-xs">
                <p class="font-bold text-[#1D1D1F]">What happens when you confirm</p>
                <ol class="list-decimal pl-5 space-y-1 text-[#3A3A3C]">
                    <li>The batch and its {{ $impact['bookings'] }} active booking(s) are marked <strong>Cancelled by Camp</strong>.</li>
                    <li>A <strong>full refund (100%)</strong> of what each guest paid is created and waits for you in <strong>Refunds</strong>.</li>
                    <li>Each guest gets the email below: why it was cancelled, their refund, and that they can ask to move to another date for free instead.</li>
                </ol>
                <p class="banner banner-warning mt-2">This cannot be undone.</p>
            </div>

            <form action="{{ route('admin.weather.cancel', $batch) }}" method="POST" class="space-y-4 text-xs">
                @csrf

                <div class="space-y-2">
                    <label for="cancel-reason" class="block font-bold text-[#1D1D1F]">
                        Why are you cancelling? <span class="text-[#780000]">*</span>
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(\App\ViewModels\BatchSafetyViewModel::CANCEL_REASON_PRESETS as $preset)
                            <button type="button" @click="cancelReason = @js($preset)"
                                    :class="cancelReason === @js($preset) ? 'bg-[#780000] text-white' : 'bg-[#F2F2F7] text-[#3A3A3C] hover:bg-[#E5E5EA]'"
                                    class="px-2.5 py-1 rounded-full text-[11px] font-semibold transition-colors">{{ $preset }}</button>
                        @endforeach
                    </div>
                    <input id="cancel-reason" type="text" name="cancellation_reason" x-model="cancelReason" required maxlength="1000"
                           placeholder="Pick one above or type your own reason"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                    <p class="text-[#6E6E73]">Guests will read this exact sentence, so keep it simple.</p>
                </div>

                <!-- Email preview: mirrors resources/views/emails/batch_weather_cancellation.blade.php -->
                <div class="space-y-1.5">
                    <span class="block font-bold text-[#1D1D1F]">Email each guest will receive</span>
                    <div class="rounded-xl bg-[#F2F2F7] p-2 text-xs text-[#1D1D1F] leading-relaxed">
                        <div class="px-4 py-2 bg-[#780000] text-white font-extrabold rounded-t-lg">Camp FreedivePH</div>
                        <div class="p-4 space-y-2.5 bg-white rounded-b-lg">
                            <p class="text-[11px] text-[#6E6E73]">Subject: Your {{ $batch->start_date->format('M d') }} dive has been cancelled for your safety</p>
                            <p class="text-sm font-extrabold">Your dive has been cancelled for your safety</p>
                            <p>Hello <strong>[Guest name]</strong>,</p>
                            <p>We're sorry, but we had to cancel your freediving trip on <strong>{{ $batch->formatted_date_range }}</strong>. Our safety team decided the sea will not be safe for diving on those dates.</p>
                            <div class="rounded-lg bg-[#FEF2F2] text-[#991B1B] px-3 py-2">
                                <strong class="block">Why we cancelled</strong>
                                <span x-text="cancelReason || '[Your reason]'"></span>
                            </div>
                            <div class="rounded-lg bg-[#ECFDF5] text-[#065F46] px-3 py-2">
                                <strong class="block">You will not lose any money</strong>
                                We have already started a full refund of the <strong>[amount they paid]</strong>. It goes back to the payment method you used. You don't need to do anything.
                            </div>
                            <p><strong>Would you rather dive on another date?</strong> Just reply to this email or message us, and we will move your booking to another available date for free instead of refunding you.</p>
                            <p class="text-[#6E6E73]">Also shows their booking number, PIN and a "View my booking" button.</p>
                        </div>
                    </div>
                </div>

                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" x-model="understood" class="rounded border-[#D1D1D6] text-[#780000] mt-0.5">
                    <span class="text-[#1D1D1F]">I understand this cancels <strong>{{ $impact['bookings'] }} booking(s)</strong>, starts full refunds and emails every guest.</span>
                </label>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-[#E5E5EA]">
                    <button type="button" @click="openCancelModal = false" class="btn-secondary px-3.5 py-1.5 text-xs">Keep batch</button>
                    <button type="submit" :disabled="!understood || !cancelReason.trim()" class="btn-danger px-4 py-2 text-xs font-bold disabled:opacity-40 disabled:cursor-not-allowed">
                        Cancel batch &amp; email guests
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
