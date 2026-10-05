@extends('layouts.admin')

@section('title', 'Dynamic Pricing | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm" x-data="{ deleteModal: false, deleteUrl: '', ruleName: '', triggeredCount: 0 }">
    
    <!-- Top Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Dynamic Pricing</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.pricing.create') }}" 
               class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-1.5 shadow-2xs">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span>Add Pricing Rule</span>
            </a>
        </div>
    </div>

    <!-- Pricing Metrics Summary -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <div class="grid grid-cols-2 lg:grid-cols-4 items-center gap-y-4">
            <!-- Total Rules -->
            <div class="px-4 py-1">
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Total Rules</span>
                <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ number_format($totalRules) }}</div>
            </div>

            <!-- Active Rules -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Active Rules</span>
                <div class="text-2xl font-extrabold text-emerald-700 mt-0.5">{{ number_format($activeRules) }}</div>
            </div>

            <!-- Bookings Triggered -->
            <div class="relative px-4 py-1">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Triggered</span>
                <div class="text-2xl font-extrabold text-[#780000] mt-0.5">{{ number_format($totalTriggered) }}</div>
            </div>

            <!-- Net Price Delta -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Net Delta</span>
                <div class="text-2xl font-extrabold mt-0.5 {{ $netRevenueImpact >= 0 ? 'text-[#1D1D1F]' : 'text-rose-700' }}">
                    {{ $netRevenueImpact >= 0 ? '+' : '−' }}₱{{ number_format(abs($netRevenueImpact), 2) }}
                </div>
            </div>
        </div>
    </div>

    <!-- Pricing Rules Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        
        <!-- Table Toolbar Header -->
        <div class="p-2.5 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-2.5 sm:gap-3">
                
                <!-- Rule Type Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none -mx-0.5 px-0.5">
                    <a href="{{ request()->fullUrlWithQuery(['rule_type' => 'all']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('rule_type', 'all') === 'all' || !request('rule_type') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Rules
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['rule_type' => 'demand']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('rule_type') === 'demand' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Demand Level
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['rule_type' => 'seasonality']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('rule_type') === 'seasonality' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Seasonality
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['rule_type' => 'lead_time']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('rule_type') === 'lead_time' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Lead Time
                    </a>
                </div>

                <!-- Filter Controls -->
                <div class="flex items-center gap-2 self-end lg:self-auto shrink-0" x-data="{ openFilters: false }">
                    <div class="relative shrink-0">
                        <button type="button" 
                                @click="openFilters = !openFilters" 
                                class="btn-secondary flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm font-semibold whitespace-nowrap shrink-0 cursor-pointer">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                            <span class="whitespace-nowrap">Filter</span>
                            @if(request()->anyFilled(['status', 'applies_to', 'sort']))
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
                             class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-4 z-50 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="font-bold text-sm text-[#1D1D1F]">Filter Rules</h4>
                                <a href="{{ route('admin.pricing.index') }}" class="text-sm text-[#780000] hover:underline font-bold">Reset</a>
                            </div>

                            <form method="GET" action="{{ route('admin.pricing.index') }}" class="space-y-3 text-sm">
                                @if(request('rule_type'))
                                    <input type="hidden" name="rule_type" value="{{ request('rule_type') }}">
                                @endif

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Status</label>
                                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="all" {{ request('status') === 'all' || !request('status') ? 'selected' : '' }}>All Statuses</option>
                                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active Only</option>
                                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive Only</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Applies To</label>
                                    <select name="applies_to" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="all" {{ request('applies_to') === 'all' || !request('applies_to') ? 'selected' : '' }}>All Classes</option>
                                        <option value="discovery" {{ request('applies_to') === 'discovery' ? 'selected' : '' }}>Discovery Class</option>
                                        <option value="fundive" {{ request('applies_to') === 'fundive' ? 'selected' : '' }}>Fundive</option>
                                        <option value="refinement" {{ request('applies_to') === 'refinement' ? 'selected' : '' }}>Refinement</option>
                                    </select>
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full py-2 text-sm font-bold shadow-2xs">
                                        Apply Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[900px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr>
                        <th class="p-4 pl-6 text-left">Priority</th>
                        <th class="p-4 text-left">Rule Name</th>
                        <th class="p-4 text-left">Type</th>
                        <th class="p-4 text-left">Trigger Condition</th>
                        <th class="p-4 text-left">Price Adjustment</th>
                        <th class="p-4 text-left">Class Package</th>
                        <th class="p-4 text-left">Status</th>
                        <th class="p-4 text-left">Triggered</th>
                        <th class="p-4 pr-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($rules as $rule)
                    <tr onclick="window.location='{{ route('admin.pricing.edit', $rule) }}'" 
                        class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm group">
                        
                        <!-- Priority Order -->
                        <td class="p-4 pl-6 text-left font-mono font-bold text-sm text-[#6E6E73]">
                            #{{ $rule->priority }}
                        </td>

                        <!-- Rule Name & Description -->
                        <td class="p-4 text-left">
                            <div class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                                {{ $rule->name }}
                            </div>
                            @if($rule->description)
                                <div class="text-sm text-[#8E8E93] truncate max-w-xs mt-0.5">
                                    {{ $rule->description }}
                                </div>
                            @endif
                        </td>

                        <!-- Rule Type Badge -->
                        <td class="p-4 text-left">
                            <span class="px-2.5 py-1 rounded-md text-xs font-bold inline-block {{ $rule->type_badge['class'] }}">
                                {{ $rule->type_badge['label'] }}
                            </span>
                        </td>

                        <!-- Condition -->
                        <td class="p-4 text-left font-mono text-sm text-[#1D1D1F]">
                            {{ $rule->condition_summary }}
                        </td>

                        <!-- Adjustment Value -->
                        <td class="p-4 text-left font-bold text-sm whitespace-nowrap">
                            <span class="{{ $rule->adjustment_type === 'increase' ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $rule->formatted_adjustment }}
                            </span>
                        </td>

                        <!-- Applies To -->
                        <td class="p-4 text-left text-sm font-semibold text-[#6E6E73] capitalize">
                            {{ $rule->formatted_applies_to }}
                        </td>

                        <!-- Status Toggle (AJAX) -->
                        <td class="p-4 text-left" onclick="event.stopPropagation()">
                            <form action="{{ route('admin.pricing.toggle_status', $rule) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <button type="submit" 
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-bold transition-colors cursor-pointer {{ $rule->status === 'active' ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                                    <span>{{ ucfirst($rule->status) }}</span>
                                </button>
                            </form>
                        </td>

                        <!-- Bookings Triggered Count -->
                        <td class="p-4 text-left" onclick="event.stopPropagation()">
                            <a href="{{ route('admin.pricing.triggered', $rule) }}" 
                               class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-[#F2F2F7] hover:bg-[#E5E5EA] border border-[#E5E5EA] text-[#780000] font-bold text-xs transition-colors"
                                title="Click to view triggered bookings">
                                <span>{{ number_format($rule->adjustments_count) }}</span>
                                <svg class="w-3 h-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
                            </a>
                        </td>

                        <!-- 3-Dots Action Menu -->
                        <td class="p-4 pr-6 text-right" onclick="event.stopPropagation()">
                            <div class="relative inline-block text-left" x-data="{ openMenu: false }">
                                <button type="button" 
                                        @click="openMenu = !openMenu" 
                                        class="w-8 h-8 rounded-lg flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-colors cursor-pointer"
                                        title="Actions">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="12" cy="5" r="2"></circle>
                                        <circle cx="12" cy="12" r="2"></circle>
                                        <circle cx="12" cy="19" r="2"></circle>
                                    </svg>
                                </button>

                                <!-- Dropdown Menu -->
                                <div x-show="openMenu" 
                                     @click.outside="openMenu = false" 
                                     x-cloak 
                                     class="absolute right-0 mt-1 w-40 bg-white rounded-xl border border-[#E5E5EA] shadow-lg p-1.5 z-50 space-y-1 text-left">
                                    
                                    <!-- Edit Rule -->
                                    <a href="{{ route('admin.pricing.edit', $rule) }}" 
                                       class="w-full flex items-center gap-2.5 px-3 py-2 text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-lg transition-colors">
                                        <img src="{{ asset('icons/icons8-edit-60.png') }}" alt="Edit" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                                        <span>Edit Rule</span>
                                    </a>

                                    <!-- Delete Rule -->
                                    <button type="button"
                                            @click="openMenu = false; deleteModal = true; deleteUrl = '{{ route('admin.pricing.destroy', $rule) }}'; ruleName = '{{ addslashes($rule->name) }}'; triggeredCount = {{ $rule->adjustments_count }};"
                                            class="w-full flex items-center gap-2 px-3 py-2 text-sm font-semibold text-[#780000] hover:bg-[#FEF2F2] rounded-lg transition-colors text-left cursor-pointer">
                                        <span>Delete Rule</span>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="py-12 text-center text-[#6E6E73]">
                            <div class="max-w-sm mx-auto space-y-3">
                                <div class="w-12 h-12 rounded-full bg-[#F2F2F7] border border-[#E5E5EA] flex items-center justify-center mx-auto text-[#6E6E73]">
                                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                </div>
                                <div class="font-bold text-[#1D1D1F]">No Pricing Rules Found</div>
                                <p class="text-sm">Create dynamic pricing rules to adjust prices automatically based on demand, seasons, or early/late bookings.</p>
                                <a href="{{ route('admin.pricing.create') }}" class="btn-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold">
                                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                    <span>Add Your First Rule</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $rules->links() }}
    </div>

    <!-- Soft Delete Confirmation Modal -->
    <div x-show="deleteModal" 
         x-cloak 
         class="fixed inset-0 z-50 flex justify-end bg-black/40"
         @keydown.escape.window="deleteModal = false">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-xl border-l border-[#E5E5EA]"
             @click.away="deleteModal = false">
            <div class="space-y-1.5">
                <h3 class="text-lg font-bold text-[#1D1D1F]">Delete Pricing Rule?</h3>
                <p class="text-sm text-[#6E6E73]">
                    Are you sure you want to remove rule <strong class="text-[#1D1D1F]" x-text="ruleName"></strong>?
                </p>
                <template x-if="triggeredCount > 0">
                    <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm mt-2">
                        <strong>Notice:</strong> This rule has affected <span x-text="triggeredCount"></span> booking(s). It will be soft-deleted to preserve all past customer receipts and audit histories.
                    </div>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#E5E5EA]">
                <button type="button" @click="deleteModal = false" class="btn-secondary px-3.5 py-1.5 text-sm font-semibold">
                    Cancel
                </button>

                <form :action="deleteUrl" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger px-4 py-1.5 text-sm font-bold">
                        Yes, Delete Rule
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
