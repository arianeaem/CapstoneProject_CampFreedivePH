@extends('layouts.admin')

@section('title', 'Add-on Services | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('owner.settings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Settings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Add-on Services</span>
@endsection

@section('content')
<div class="space-y-6 text-sm pb-16" x-data="{
    locations: {{ json_encode(old('pickup_locations', is_array($settings['pickup_locations']) ? $settings['pickup_locations'] : [])) }},
    editingIndex: null,
    addLocation() {
        const id = 'hub_' + Date.now();
        this.locations.push({
            id: id,
            name: '',
            time: '3:00 AM',
            address: ''
        });
        this.editingIndex = this.locations.length - 1;
    },
    removeLocation(index) {
        this.locations.splice(index, 1);
        if (this.editingIndex === index) {
            this.editingIndex = null;
        } else if (this.editingIndex > index) {
            this.editingIndex--;
        }
    },
    moveUp(index) {
        if (index > 0) {
            const temp = this.locations.splice(index, 1)[0];
            this.locations.splice(index - 1, 0, temp);
            if (this.editingIndex === index) {
                this.editingIndex = index - 1;
            } else if (this.editingIndex === index - 1) {
                this.editingIndex = index;
            }
        }
    },
    moveDown(index) {
        if (index < this.locations.length - 1) {
            const temp = this.locations.splice(index, 1)[0];
            this.locations.splice(index + 1, 0, temp);
            if (this.editingIndex === index) {
                this.editingIndex = index + 1;
            } else if (this.editingIndex === index + 1) {
                this.editingIndex = index;
            }
        }
    }
}">
    
    <!-- Top Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Add-on Services</h1>
        <p class="text-[#6E6E73] text-sm mt-1">
            Configure carpool transport fees, boat dive charges, environmental fees, and customize Manila pickup hub locations and schedules.
        </p>
    </div>

    @if($errors->any())
        <div class="p-4 rounded-xl bg-rose-50 text-rose-800 flex items-start gap-3 shadow-2xs">
            <svg class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
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

    <form method="POST" action="{{ route('owner.settings.addons.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <!-- Add-on Fees Grid -->
        <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-6">
            <div>
                <h2 class="text-lg font-bold text-[#1D1D1F]">Logistics & Add-on Fee Rates</h2>
                <p class="text-xs text-[#6E6E73] mt-0.5">Rates charged per head during online booking and invoice calculation</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Carpool Fee -->
                <div class="space-y-1.5">
                    <label for="carpool_fee_per_head" class="block font-bold text-xs text-[#1D1D1F]">
                        Carpool Fee / Person (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="50" 
                               id="carpool_fee_per_head" 
                               name="carpool_fee_per_head" 
                               value="{{ old('carpool_fee_per_head', $settings['carpool_fee_per_head']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Roundtrip van transportation.</p>
                </div>

                <!-- Boat Dive Fee -->
                <div class="space-y-1.5">
                    <label for="boat_dive_fee_per_head" class="block font-bold text-xs text-[#1D1D1F]">
                        Boat Dive Fee / Person (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="50" 
                               id="boat_dive_fee_per_head" 
                               name="boat_dive_fee_per_head" 
                               value="{{ old('boat_dive_fee_per_head', $settings['boat_dive_fee_per_head']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Optional banca offshore charter.</p>
                </div>

                <!-- LGU Tourism Pass -->
                <div class="space-y-1.5">
                    <label for="lgu_tourism_pass_fee" class="block font-bold text-xs text-[#1D1D1F]">
                        Mabini LGU Pass / Person (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="10" 
                               id="lgu_tourism_pass_fee" 
                               name="lgu_tourism_pass_fee" 
                               value="{{ old('lgu_tourism_pass_fee', $settings['lgu_tourism_pass_fee']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Mandatory municipal pass.</p>
                </div>

                <!-- Environmental Fee -->
                <div class="space-y-1.5">
                    <label for="environmental_fee" class="block font-bold text-xs text-[#1D1D1F]">
                        Sanctuary Fee / Person (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="10" 
                               id="environmental_fee" 
                               name="environmental_fee" 
                               value="{{ old('environmental_fee', $settings['environmental_fee']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Marine conservation fee.</p>
                </div>
            </div>
        </div>

        <!-- Configurable Pickup Hub Locations Table Format -->
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-[#1D1D1F]">Carpool Pickup Hubs & Schedule</h2>
                    <p class="text-xs text-[#6E6E73] mt-0.5">Customize the meeting points and departure times available on the booking checkout form</p>
                </div>
                <button type="button" 
                        @click="addLocation()" 
                        class="btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold text-[#780000] bg-[#780000]/10 hover:bg-[#780000]/20 transition-all self-start sm:self-auto cursor-pointer">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>Add Pickup Hub</span>
                </button>
            </div>

            <!-- Table List Format with Camp Freedive PH Branding -->
            <div class="rounded-xl border border-[#E5E5EA] bg-white overflow-hidden shadow-2xs divide-y divide-[#F2F2F7]">
                <template x-for="(loc, index) in locations" :key="loc.id || index">
                    <div class="bg-white transition-colors hover:bg-[#F9F9FB]">
                        
                        <!-- Hidden form inputs always bound to keep state in sync -->
                        <input type="hidden" :name="'pickup_locations[' + index + '][id]'" :value="loc.id || ('hub_' + index)">

                        <!-- Row Display -->
                        <div class="flex items-center justify-between px-4 py-3.5 gap-3">
                            <!-- Left: Reorder arrows + Sequence number + Title -->
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                
                                <!-- Reorder Arrows (Up/Down) -->
                                <div class="flex flex-col gap-0.5 opacity-60 hover:opacity-100 transition-opacity shrink-0">
                                    <button type="button" 
                                            @click.stop="moveUp(index)" 
                                            :disabled="index === 0"
                                            class="text-[#8E8E93] hover:text-[#780000] disabled:opacity-20 cursor-pointer disabled:cursor-not-allowed p-0.5" 
                                            title="Move Up">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m18 15-6-6-6 6"/></svg>
                                    </button>
                                    <button type="button" 
                                            @click.stop="moveDown(index)" 
                                            :disabled="index === locations.length - 1"
                                            class="text-[#8E8E93] hover:text-[#780000] disabled:opacity-20 cursor-pointer disabled:cursor-not-allowed p-0.5" 
                                            title="Move Down">
                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </div>

                                <!-- Sequence Number -->
                                <span class="font-bold text-sm text-[#1D1D1F] shrink-0 w-4 text-center" x-text="index + 1"></span>

                                <!-- Hub Name & Info -->
                                <div class="min-w-0 flex-1 truncate">
                                    <span class="font-semibold text-sm text-[#1D1D1F]" x-text="loc.name || 'Unnamed Pickup Location'"></span>
                                    <span x-show="loc.address" class="text-xs text-[#8E8E93] block truncate" x-text="loc.address"></span>
                                </div>
                            </div>

                            <!-- Right: Action Icons (Pencil & Trash) -->
                            <div class="flex items-center gap-1 shrink-0">
                                <!-- Edit Button (No Background Color) -->
                                <button type="button" 
                                        @click="editingIndex = (editingIndex === index ? null : index)" 
                                        class="p-2 rounded-lg text-[#6E6E73] hover:text-[#780000] transition-colors cursor-pointer"
                                        :class="editingIndex === index ? 'text-[#780000]' : ''"
                                        title="Edit Hub">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                        <path d="m15 5 4 4"/>
                                    </svg>
                                </button>

                                <!-- Delete Button (No Background Color) -->
                                <button type="button" 
                                        @click="removeLocation(index)" 
                                        class="p-2 rounded-lg text-[#6E6E73] hover:text-[#D70015] transition-colors cursor-pointer"
                                        title="Delete Hub">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18"/>
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                        <line x1="10" y1="11" x2="10" y2="17"/>
                                        <line x1="14" y1="11" x2="14" y2="17"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Inline Editor Panel (White Background) -->
                        <div x-show="editingIndex === index" x-cloak class="px-6 py-4 bg-white border-t border-[#E5E5EA] space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div class="sm:col-span-2 space-y-1">
                                    <label class="block text-[11px] font-bold text-[#1D1D1F]">Hub Label & Schedule (e.g. Monumento Hypermarket at 2:30 AM)</label>
                                    <input type="text" 
                                           :name="'pickup_locations[' + index + '][name]'" 
                                           x-model="loc.name" 
                                           placeholder="e.g. Monumento Hypermarket at 2:30 AM" 
                                           required
                                           class="w-full min-h-[38px] px-3 py-1.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-[11px] font-bold text-[#1D1D1F]">Departure Time</label>
                                    <input type="text" 
                                           :name="'pickup_locations[' + index + '][time]'" 
                                           x-model="loc.time" 
                                           placeholder="e.g. 2:30 AM" 
                                           required
                                           class="w-full min-h-[38px] px-3 py-1.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                                </div>
                                <div class="sm:col-span-3 space-y-1">
                                    <label class="block text-[11px] font-bold text-[#1D1D1F]">Landmark / Complete Address (Optional)</label>
                                    <input type="text" 
                                           :name="'pickup_locations[' + index + '][address]'" 
                                           x-model="loc.address" 
                                           placeholder="e.g. Monumento Hypermarket, EDSA, Caloocan City" 
                                           class="w-full min-h-[38px] px-3 py-1.5 text-xs rounded-lg border border-[#D1D1D6] bg-white font-medium text-[#1D1D1F] focus:border-[#780000] focus:ring-1 focus:ring-[#780000] focus:outline-none">
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" 
                                        @click="editingIndex = null" 
                                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs transition-colors cursor-pointer">
                                    Done
                                </button>
                            </div>
                        </div>

                    </div>
                </template>

                <div x-show="locations.length === 0" class="p-8 text-center bg-[#F8F9FA]">
                    <p class="text-xs text-[#6E6E73]">No pickup locations configured. Click "Add Pickup Hub" above.</p>
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
