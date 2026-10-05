@extends('layouts.admin')

@section('title', 'Class Packages | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('owner.settings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Settings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Class Packages</span>
@endsection

@section('content')
<div class="space-y-6 text-sm pb-16" x-data="{ editingClass: null }">
    
    <!-- Top Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Class Packages</h1>
        <p class="text-[#6E6E73] text-sm mt-1">
            Configure base prices, package inclusions, and exclusions for all classes.
        </p>
    </div>

    @if($errors->any())
        <div class="banner banner-error flex items-start gap-3">
            <div>
                <p class="font-bold text-sm">Please correct the errors below:</p>
                <ul class="list-disc list-inside text-xs text-rose-700 mt-1 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('owner.settings.programs.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <input type="hidden" name="dynamic_pricing_cap_percent" value="{{ old('dynamic_pricing_cap_percent', $settings['dynamic_pricing_cap_percent'] ?? 25) }}">

        <!-- Table List Format with Camp Freedive PH Branding -->
        <div class="rounded-xl border border-[#E5E5EA] bg-white overflow-hidden shadow-2xs divide-y divide-[#F2F2F7]">

            <!-- 1. Discovery Freedive -->
            <div class="bg-white transition-colors hover:bg-[#F9F9FB]">
                <div class="flex items-center justify-between px-4 py-3.5 gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="font-bold text-sm text-[#1D1D1F] shrink-0 w-4 text-center">1</span>
                        <div class="min-w-0 flex-1 truncate">
                            <span class="font-semibold text-sm text-[#1D1D1F]">Discovery Freedive</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-xs font-bold text-[#1D1D1F]">
                            ₱{{ number_format(old('base_price_discovery', $settings['base_price_discovery'])) }} / pax
                        </span>
                        <button type="button" 
                                @click="editingClass = (editingClass === 'discovery' ? null : 'discovery')" 
                                class="p-2 rounded-lg text-[#6E6E73] hover:text-[#780000] transition-colors cursor-pointer"
                                :class="editingClass === 'discovery' ? 'text-[#780000]' : ''"
                                title="Edit Discovery Freedive">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                <path d="m15 5 4 4"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Inline Editor Panel for Discovery -->
                <div x-show="editingClass === 'discovery'" x-cloak class="px-6 py-4 bg-white border-t border-[#E5E5EA] space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="space-y-1.5">
                            <label for="base_price_discovery" class="block font-bold text-xs text-[#1D1D1F]">Base Price / Person (₱)</label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                                <input type="number" 
                                       step="50" 
                                       id="base_price_discovery" 
                                       name="base_price_discovery" 
                                       value="{{ old('base_price_discovery', $settings['base_price_discovery']) }}" 
                                       min="0" 
                                       required
                                       class="w-full min-h-[40px] pl-8 pr-3.5 py-2 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="discovery_inclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Inclusions</label>
                            <textarea id="discovery_inclusions" 
                                      name="discovery_inclusions" 
                                      rows="4" 
                                      placeholder="e.g. 2 open water dives&#10;3 full board meals&#10;Gears"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('discovery_inclusions', is_array($settings['discovery_inclusions']) ? implode("\n", $settings['discovery_inclusions']) : '') }}</textarea>
                        </div>

                        <div class="space-y-1.5">
                            <label for="discovery_exclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Exclusions</label>
                            <textarea id="discovery_exclusions" 
                                      name="discovery_exclusions" 
                                      rows="4" 
                                      placeholder="e.g. Transportation&#10;Boat dive (optional)&#10;LGU pass"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('discovery_exclusions', is_array($settings['discovery_exclusions']) ? implode("\n", $settings['discovery_exclusions']) : '') }}</textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="button" 
                                @click="editingClass = null" 
                                class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs transition-colors cursor-pointer">
                            Done
                        </button>
                    </div>
                </div>
            </div>

            <!-- 2. Fun Dive -->
            <div class="bg-white transition-colors hover:bg-[#F9F9FB]">
                <div class="flex items-center justify-between px-4 py-3.5 gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="font-bold text-sm text-[#1D1D1F] shrink-0 w-4 text-center">2</span>
                        <div class="min-w-0 flex-1 truncate">
                            <span class="font-semibold text-sm text-[#1D1D1F]">Fun Dive</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-xs font-bold text-[#1D1D1F]">
                            ₱{{ number_format(old('base_price_fundive_cert', $settings['base_price_fundive_cert'])) }} / ₱{{ number_format(old('base_price_fundive_noncert', $settings['base_price_fundive_noncert'])) }}
                        </span>
                        <button type="button" 
                                @click="editingClass = (editingClass === 'fundive' ? null : 'fundive')" 
                                class="p-2 rounded-lg text-[#6E6E73] hover:text-[#780000] transition-colors cursor-pointer"
                                :class="editingClass === 'fundive' ? 'text-[#780000]' : ''"
                                title="Edit Fun Dive">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                <path d="m15 5 4 4"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Inline Editor Panel for Fun Dive -->
                <div x-show="editingClass === 'fundive'" x-cloak class="px-6 py-4 bg-white border-t border-[#E5E5EA] space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="base_price_fundive_cert" class="block font-bold text-xs text-[#1D1D1F]">Certified Diver Base Price (₱)</label>
                            <p class="text-[11px] text-[#6E6E73]">For verified divers with certification</p>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                                <input type="number" 
                                       step="50" 
                                       id="base_price_fundive_cert" 
                                       name="base_price_fundive_cert" 
                                       value="{{ old('base_price_fundive_cert', $settings['base_price_fundive_cert']) }}" 
                                       min="0" 
                                       required
                                       class="w-full min-h-[40px] pl-8 pr-3.5 py-2 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="base_price_fundive_noncert" class="block font-bold text-xs text-[#1D1D1F]">Non-Certified Diver Base Price (₱)</label>
                            <p class="text-[11px] text-[#6E6E73]">Includes dedicated safety coach supervision</p>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                                <input type="number" 
                                       step="50" 
                                       id="base_price_fundive_noncert" 
                                       name="base_price_fundive_noncert" 
                                       value="{{ old('base_price_fundive_noncert', $settings['base_price_fundive_noncert']) }}" 
                                       min="0" 
                                       required
                                       class="w-full min-h-[40px] pl-8 pr-3.5 py-2 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="fundive_inclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Inclusions</label>
                            <textarea id="fundive_inclusions" 
                                      name="fundive_inclusions" 
                                      rows="4" 
                                      placeholder="e.g. 2 open water dives&#10;Safety coach fee&#10;Gears"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('fundive_inclusions', is_array($settings['fundive_inclusions']) ? implode("\n", $settings['fundive_inclusions']) : '') }}</textarea>
                        </div>

                        <div class="space-y-1.5">
                            <label for="fundive_exclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Exclusions</label>
                            <textarea id="fundive_exclusions" 
                                      name="fundive_exclusions" 
                                      rows="4" 
                                      placeholder="e.g. Transportation&#10;Boat dive (optional)"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('fundive_exclusions', is_array($settings['fundive_exclusions']) ? implode("\n", $settings['fundive_exclusions']) : '') }}</textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="button" 
                                @click="editingClass = null" 
                                class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs transition-colors cursor-pointer">
                            Done
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. Skills Refinement -->
            <div class="bg-white transition-colors hover:bg-[#F9F9FB]">
                <div class="flex items-center justify-between px-4 py-3.5 gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="font-bold text-sm text-[#1D1D1F] shrink-0 w-4 text-center">3</span>
                        <div class="min-w-0 flex-1 truncate">
                            <span class="font-semibold text-sm text-[#1D1D1F]">Skills Refinement</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-xs font-bold text-[#1D1D1F]">
                            ₱{{ number_format(old('base_price_refinement', $settings['base_price_refinement'])) }} / pax
                        </span>
                        <button type="button" 
                                @click="editingClass = (editingClass === 'refinement' ? null : 'refinement')" 
                                class="p-2 rounded-lg text-[#6E6E73] hover:text-[#780000] transition-colors cursor-pointer"
                                :class="editingClass === 'refinement' ? 'text-[#780000]' : ''"
                                title="Edit Skills Refinement">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                <path d="m15 5 4 4"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Inline Editor Panel for Refinement -->
                <div x-show="editingClass === 'refinement'" x-cloak class="px-6 py-4 bg-white border-t border-[#E5E5EA] space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="space-y-1.5">
                            <label for="base_price_refinement" class="block font-bold text-xs text-[#1D1D1F]">Base Price / Person (₱)</label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                                <input type="number" 
                                       step="50" 
                                       id="base_price_refinement" 
                                       name="base_price_refinement" 
                                       value="{{ old('base_price_refinement', $settings['base_price_refinement']) }}" 
                                       min="0" 
                                       required
                                       class="w-full min-h-[40px] pl-8 pr-3.5 py-2 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="refinement_inclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Inclusions</label>
                            <textarea id="refinement_inclusions" 
                                      name="refinement_inclusions" 
                                      rows="4" 
                                      placeholder="e.g. 2 open water dives&#10;Coach fee&#10;Gears"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('refinement_inclusions', is_array($settings['refinement_inclusions']) ? implode("\n", $settings['refinement_inclusions']) : '') }}</textarea>
                        </div>

                        <div class="space-y-1.5">
                            <label for="refinement_exclusions" class="block font-bold text-xs text-[#1D1D1F]">Package Exclusions</label>
                            <textarea id="refinement_exclusions" 
                                      name="refinement_exclusions" 
                                      rows="4" 
                                      placeholder="e.g. Transportation&#10;Boat dive (optional)"
                                      class="w-full p-2.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">{{ old('refinement_exclusions', is_array($settings['refinement_exclusions']) ? implode("\n", $settings['refinement_exclusions']) : '') }}</textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="button" 
                                @click="editingClass = null" 
                                class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs transition-colors cursor-pointer">
                            Done
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Bottom Action Bar -->
        <div class="flex items-center justify-end gap-3 pt-4">
            <a href="{{ route('owner.settings.index') }}" class="px-4 py-2 rounded-xl text-sm font-semibold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all">
                Cancel
            </a>
            <button type="submit" class="btn-primary min-h-[44px] px-6 py-2.5 rounded-xl text-sm font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs active:scale-[0.98] transition-all flex items-center gap-2">
                <span>Save Changes</span>
            </button>
        </div>

    </form>

</div>
@endsection
