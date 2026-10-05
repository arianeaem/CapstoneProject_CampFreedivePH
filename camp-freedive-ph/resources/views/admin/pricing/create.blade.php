@extends('layouts.admin')

@section('title', 'Create Pricing Rule | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('pricing.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Dynamic Pricing</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Create Rule</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6 text-sm"
     x-data="{
         name: '{{ old('name', '') }}',
         rule_type: '{{ old('rule_type', 'seasonality') }}',
         condition_operator: '{{ old('condition_operator', '<=') }}',
         condition_value: '{{ old('condition_value', 'peak') }}',
         applies_to: '{{ old('applies_to', 'all') }}',
         adjustment_type: '{{ old('adjustment_type', 'increase') }}',
         adjustment_method: '{{ old('adjustment_method', 'percentage') }}',
         adjustment_value: '{{ old('adjustment_value', '10') }}',
         priority: '{{ old('priority', '1') }}',
         status: '{{ old('status', 'active') }}',

         get previewText() {
             let condText = '';
             if (this.rule_type === 'demand') {
                 condText = 'Demand Level is ' + (this.condition_value ? this.condition_value.toUpperCase() : 'HIGH');
             } else if (this.rule_type === 'seasonality') {
                 let s = this.condition_value || 'peak';
                 condText = 'Season is ' + s.replace('_', '-').toUpperCase() + ' SEASON';
             } else {
                 let op = this.condition_operator === '<=' ? '3 days or less' : (this.condition_operator === '>=' ? 'at least ' + (this.condition_value || '30') + ' days' : this.condition_operator + ' ' + (this.condition_value || '0') + ' days');
                 condText = 'Lead time is ' + op + ' before dive date';
             }

             let scopeText = this.applies_to === 'all' ? 'All Freediving Classes' : this.applies_to.toUpperCase() + ' Class';
             let adjText = (this.adjustment_type === 'increase' ? 'increase by ' : 'decrease by ') + 
                           (this.adjustment_method === 'percentage' ? (this.adjustment_value || '0') + '%' : '₱' + (this.adjustment_value || '0'));

             return 'When ' + condText + ' for ' + scopeText + ', the price per person will ' + adjText + '.';
         }
     }">

    <!-- Top Navigation Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Create Pricing Rule</h1>
        </div>
    </div>

    @if ($errors->any())
    <div class="banner banner-error space-y-1">
        <div class="font-bold">Please correct the following errors:</div>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Rule form -->
    <form action="{{ route('admin.pricing.store') }}" method="POST">
        @csrf

        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-8 shadow-2xs space-y-8">
            
            <!-- 1. Rule Identification & Scope -->
            <div class="space-y-4">
                <h2 class="text-base font-extrabold text-[#1D1D1F]">1. Rule Identification & Scope</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Rule Name -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Rule Name <span class="text-[#780000]">*</span></label>
                        <input type="text" name="name" x-model="name" required placeholder="e.g. Peak Season Discovery Bump"
                               class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white text-[#1D1D1F] focus:border-[#780000]">
                    </div>

                    <!-- Description -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Description (Optional)</label>
                        <textarea name="description" rows="2" placeholder="e.g. Applies during Peak months to smooth capacity"
                                  class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white text-[#1D1D1F] focus:border-[#780000]">{{ old('description') }}</textarea>
                    </div>

                    <!-- Applies To -->
                    <div>
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Applies To <span class="text-[#780000]">*</span></label>
                        <select name="applies_to" x-model="applies_to" required
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                            <option value="all">All Classes (Discovery, Fundive, Refinement)</option>
                            <option value="discovery">Discovery Class Only (₱4,250 base)</option>
                            <option value="fundive">Fundive Only (₱2,500 - ₱3,300 base)</option>
                            <option value="refinement">Refinement Class Only (₱4,100 base)</option>
                        </select>
                    </div>

                    <!-- Execution Priority -->
                    <div>
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Execution Priority (1 = Highest)</label>
                        <input type="number" name="priority" x-model="priority" min="1" max="999"
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                        <span class="text-xs sm:text-sm text-[#6E6E73] mt-1 block">Determines stacking sequence when multiple rules trigger.</span>
                    </div>
                </div>
            </div>

            <!-- 2. Rule Trigger & Condition -->
            <div class="space-y-4">
                <h2 class="text-base font-extrabold text-[#1D1D1F]">2. Rule Trigger & Condition</h2>

                <!-- Select Rule Type Radio Cards -->
                <div>
                    <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-2">Rule Type <span class="text-[#780000]">*</span></label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" role="radiogroup" aria-label="Rule Type">
                        <label tabindex="0"
                               role="radio"
                               :aria-checked="rule_type === 'seasonality'"
                               @keydown.enter.prevent="rule_type = 'seasonality'; condition_value = 'peak'"
                               @keydown.space.prevent="rule_type = 'seasonality'; condition_value = 'peak'"
                               @click="rule_type = 'seasonality'; condition_value = 'peak'"
                               class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex flex-col justify-between select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                               :class="rule_type === 'seasonality' ? 'ring-2 ring-[#780000]' : ''">
                            <input type="radio" name="rule_type" value="seasonality" x-model="rule_type" class="hidden">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">Seasonality</span>
                                    <span x-show="rule_type === 'seasonality'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                </div>
                                <span class="text-xs sm:text-sm text-[#6E6E73] block leading-snug">Calendar periods (Peak, Shoulder, Off-Peak)</span>
                            </div>
                        </label>

                        <label tabindex="0"
                               role="radio"
                               :aria-checked="rule_type === 'demand'"
                               @keydown.enter.prevent="rule_type = 'demand'; condition_value = 'high'"
                               @keydown.space.prevent="rule_type = 'demand'; condition_value = 'high'"
                               @click="rule_type = 'demand'; condition_value = 'high'"
                               class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex flex-col justify-between select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                               :class="rule_type === 'demand' ? 'ring-2 ring-[#780000]' : ''">
                            <input type="radio" name="rule_type" value="demand" x-model="rule_type" class="hidden">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">Demand Level</span>
                                    <span x-show="rule_type === 'demand'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                </div>
                                <span class="text-xs sm:text-sm text-[#6E6E73] block leading-snug">Booking occupancy & slot utilization</span>
                            </div>
                        </label>

                        <label tabindex="0"
                               role="radio"
                               :aria-checked="rule_type === 'lead_time'"
                               @keydown.enter.prevent="rule_type = 'lead_time'; condition_value = '3'; condition_operator = '<='"
                               @keydown.space.prevent="rule_type = 'lead_time'; condition_value = '3'; condition_operator = '<='"
                               @click="rule_type = 'lead_time'; condition_value = '3'; condition_operator = '<='"
                               class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex flex-col justify-between select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                               :class="rule_type === 'lead_time' ? 'ring-2 ring-[#780000]' : ''">
                            <input type="radio" name="rule_type" value="lead_time" x-model="rule_type" class="hidden">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">Lead Time</span>
                                    <span x-show="rule_type === 'lead_time'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                </div>
                                <span class="text-xs sm:text-sm text-[#6E6E73] block leading-snug">Days booked in advance (Early-bird / Last-minute)</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Dynamic Condition Fields -->
                <div class="pt-2">
                    <!-- Condition: Seasonality -->
                    <div x-show="rule_type === 'seasonality'" class="space-y-2">
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F]">When Season is:</label>
                        <select name="condition_value" x-model="condition_value" :disabled="rule_type !== 'seasonality'"
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                            <option value="peak">Peak Season ({{ \App\Support\DemandRules::monthsLabel('Peak') }} &middot; from actual booking history)</option>
                            <option value="shoulder">Shoulder Season ({{ \App\Support\DemandRules::monthsLabel('Shoulder') }} &middot; from actual booking history)</option>
                            <option value="off_peak">Off-Peak Season ({{ \App\Support\DemandRules::monthsLabel('Off-Peak') }} &middot; from actual booking history)</option>
                        </select>
                    </div>

                    <!-- Condition: Demand -->
                    <div x-show="rule_type === 'demand'" class="space-y-2">
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F]">When Demand Level is:</label>
                        <select name="condition_value" x-model="condition_value" :disabled="rule_type !== 'demand'"
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                            <option value="high">High Demand (Over 60% Batch Capacity Booked)</option>
                            <option value="medium">Medium Demand (25% to 60% Capacity Booked)</option>
                            <option value="low">Low Demand (Under 25% Capacity Booked)</option>
                        </select>
                    </div>

                    <!-- Condition: Lead Time -->
                    <div x-show="rule_type === 'lead_time'" class="space-y-2">
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F]">When Days Before Dive Date is:</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <select name="condition_operator" x-model="condition_operator" :disabled="rule_type !== 'lead_time'"
                                    class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                                <option value="<=">Less than or equal to (e.g. Last-minute)</option>
                                <option value=">=">More than or equal to (e.g. Early-bird)</option>
                                <option value="<">Less than</option>
                                <option value=">">More than</option>
                                <option value="==">Exactly equal to</option>
                            </select>

                            <div class="relative">
                                <input type="number" name="condition_value" x-model="condition_value" :disabled="rule_type !== 'lead_time'" min="0" max="365" placeholder="e.g. 3"
                                       class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000] pr-14">
                                <span class="absolute right-3.5 top-2.5 text-sm text-[#6E6E73] font-semibold">days</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Price Adjustment Calculation -->
            <div class="space-y-4">
                <h2 class="text-base font-extrabold text-[#1D1D1F]">3. Price Adjustment Calculation</h2>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- Adjustment Type -->
                    <div>
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Direction <span class="text-[#780000]">*</span></label>
                        <select name="adjustment_type" x-model="adjustment_type" required
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                            <option value="increase">Increase (+ Surcharge / Peak Bump)</option>
                            <option value="decrease">Decrease (− Discount / Off-peak Incentive)</option>
                        </select>
                    </div>

                    <!-- Adjustment Method -->
                    <div>
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Calculation Method <span class="text-[#780000]">*</span></label>
                        <select name="adjustment_method" x-model="adjustment_method" required
                                class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000]">
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Peso Amount (₱)</option>
                        </select>
                    </div>

                    <!-- Adjustment Value -->
                    <div>
                        <label class="block text-xs sm:text-sm font-bold text-[#1D1D1F] mb-1.5">Adjustment Value <span class="text-[#780000]">*</span></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0.01" name="adjustment_value" x-model="adjustment_value" required placeholder="e.g. 15"
                                   class="w-full text-sm rounded-xl border border-[#D1D1D6] px-3.5 py-2.5 bg-white font-medium text-[#1D1D1F] focus:border-[#780000] pr-10">
                            <span class="absolute right-3.5 top-2.5 text-sm text-[#6E6E73] font-bold" x-text="adjustment_method === 'percentage' ? '%' : '₱'"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Status (on/off) -->
            <div class="space-y-4">
                <h2 class="text-base font-extrabold text-[#1D1D1F]">4. Initial Rule Status</h2>

                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-bold text-sm text-[#1D1D1F]">Rule Status</div>
                        <div class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Inactive rules can be prepared ahead of time without affecting live pricing quotes.</div>
                    </div>

                    <div class="flex items-center">
                        <input type="hidden" name="status" :value="status === 'active' ? 'active' : 'inactive'">
                        <button type="button" 
                                role="switch"
                                :aria-checked="status === 'active'"
                                @click="status = status === 'active' ? 'inactive' : 'active'"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[#780000]"
                                :class="status === 'active' ? 'bg-[#780000]' : 'bg-[#D1D1D6]'">
                            <span class="sr-only">Toggle rule status</span>
                            <span aria-hidden="true" 
                                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out"
                                  :class="status === 'active' ? 'translate-x-5' : 'translate-x-0'"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.pricing.index') }}" class="btn-secondary min-h-[44px] px-6 py-2.5 text-sm font-bold inline-flex items-center justify-center active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    Cancel
                </a>
                <button type="submit" class="btn-primary min-h-[44px] px-8 py-2.5 text-sm font-bold inline-flex items-center justify-center active:scale-[0.99] transition-all shadow-md focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    Save & Create Rule
                </button>
            </div>
        </div>
    </form>

</div>
@endsection
