@extends('layouts.admin')

@section('title', 'Create Booking | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('bookings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Bookings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Create Booking</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6 text-sm" 
     x-data="adminBookingCreate({
         pickupPoints: {{ json_encode($pickupPoints) }},
         pricingConfig: {{ json_encode($pricingConfig ?? null) }},
         csrfToken: '{{ csrf_token() }}',
         checkWeatherUrl: '{{ route('api.weather.check') }}'
     })"
     x-init="initForm()">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Create Booking</h1>
        </div>
    </div>

    <!-- Create booking form -->
    <form action="{{ route('admin.bookings.store') }}" method="POST">
        @csrf

        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-8 shadow-2xs space-y-8">
            
            <!-- 1. Class Package & Dive Dates -->
            <div class="space-y-4">
                <h3 class="text-base font-extrabold text-[#1D1D1F]">1. Class Package & Dive Dates</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Class Package -->
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Class Type <span class="text-[#780000]">*</span></label>
                        <select name="class_type" x-model="classType" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option value="discovery">Discovery (Beginner Class - ₱4,250)</option>
                            <option value="fundive">Fundive (₱2,500 Certified / ₱3,300 Non-Certified)</option>
                            <option value="refinement">Refinement (Practice Dive - ₱4,100)</option>
                        </select>
                    </div>

                    <!-- Certified Diver Flag for Fundive -->
                    <div x-show="classType === 'fundive'" x-cloak>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Fundive Diver Certification</label>
                        <select name="is_certified_diver" x-model="isCertified" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option :value="false">Non-Certified Diver (₱3,300)</option>
                            <option :value="true">Certified Freediver (₱2,500)</option>
                        </select>
                    </div>
                </div>

                <!-- 2D1N Dates -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Trip Start Date <span class="text-[#780000]">*</span></label>
                        <x-date-picker name="start_date" model="startDate" x-on:change="onStartDateChange()" required />
                    </div>

                    <div>
                        <label class="block font-bold text-[#6E6E73] text-xs sm:text-sm mb-1.5">Trip End Date</label>
                        <x-date-picker name="end_date" model="endDate" readonly required />
                    </div>
                </div>

                <!-- Weather Forecast -->
                <div class="pt-2">
                    <!-- Loading State -->
                    <div x-show="weatherLoading" x-cloak class="p-4 rounded-xl bg-[#F2F2F7] border border-[#E5E5EA] space-y-2">
                        <div class="flex items-center justify-between text-xs sm:text-sm">
                            <span class="font-semibold text-[#1D1D1F]">Evaluating weather & marine conditions...</span>
                            <span class="font-bold text-[#00C3D0]">Checking</span>
                        </div>
                        <div class="w-full bg-[#E5E5EA] h-1.5 rounded-full overflow-hidden">
                            <div class="bg-[#00C3D0] h-full w-2/3 animate-pulse"></div>
                        </div>
                    </div>

                    <!-- Weather result (uses components/dive-safety/live-evaluation) -->
                    <template x-if="forecast && forecast.day1 && !weatherLoading && !forecast.is_benchmark">
                        <x-dive-safety.live-evaluation forecast="forecast" class="pt-2" />
                    </template>

                    <!-- Empty / Beyond 16 days state -->
                    <template x-if="startDate && !weatherLoading && (!forecast || forecast.is_benchmark)">
                        <div class="p-3.5 rounded-xl border border-[#E5E5EA] bg-[#FAFAFA] text-xs text-[#6E6E73]">
                            Marine condition evaluations are unavailable for dates beyond 16 days. Reservation may proceed; conditions will be verified prior to camp.
                        </div>
                    </template>
                </div>
            </div>

            <!-- 2. Divers & Participants -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-[#1D1D1F]">2. Divers & Participants</h3>
                    <button type="button" 
                            @click="addParticipant()"
                            class="btn-primary text-xs sm:text-sm px-3.5 py-1.5 font-bold flex items-center gap-1.5 shadow-2xs">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Add Participant</span>
                    </button>
                </div>

                <div class="space-y-4">
                    <template x-for="(p, index) in participants" :key="index">
                        <div class="rounded-xl border border-[#E5E5EA] p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-[#780000] text-xs uppercase tracking-wider" x-text="'Participant #' + (index + 1)"></span>
                                <button type="button" 
                                        x-show="participants.length > 1" 
                                        @click="removeParticipant(index)"
                                        class="text-xs text-[#FF3B3C] font-bold hover:underline">
                                    Remove
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">First Name <span class="text-[#780000]">*</span></label>
                                    <input type="text" :name="'participants[' + index + '][first_name]'" x-model="p.first_name" @input="assembleParticipantName(p)" required placeholder="e.g. Maria Ma." class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                    <input type="text" :name="'participants[' + index + '][middle_name]'" x-model="p.middle_name" @input="assembleParticipantName(p)" :disabled="p.no_middle_name" placeholder="Full middle name" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white disabled:bg-gray-100 disabled:text-gray-400">
                                    <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73] cursor-pointer">
                                        <input type="checkbox" :name="'participants[' + index + '][no_middle_name]'" value="1" x-model="p.no_middle_name" @change="if(p.no_middle_name) p.middle_name = ''; assembleParticipantName(p)" class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000]">
                                        <span>I do not have a legal middle name</span>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-3">
                                <div class="sm:col-span-2">
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Last Name <span class="text-[#780000]">*</span></label>
                                    <input type="text" :name="'participants[' + index + '][last_name]'" x-model="p.last_name" @input="assembleParticipantName(p)" required placeholder="e.g. Santos-Concepcion" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                                </div>
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                    <select :name="'participants[' + index + '][suffix]'" x-model="p.suffix" @change="assembleParticipantName(p)" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                        <option value="">None</option>
                                        <option value="Jr.">Jr.</option>
                                        <option value="Sr.">Sr.</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                        <option value="IV">IV</option>
                                        <option value="V">V</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Birthdate <span class="text-[#780000]">*</span></label>
                                    <x-date-picker name-expr="'participants[' + index + '][birthdate]'" model="p.birthdate" :max="now()->toDateString()" year-select placeholder="Select birthdate" required />
                                </div>
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Gender <span class="text-[#780000]">*</span></label>
                                    <select :name="'participants[' + index + '][gender]'" x-model="p.gender" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                        <option value="">Select gender</option>
                                        <option value="female">Female</option>
                                        <option value="male">Male</option>
                                        <option value="non_binary">Non-binary</option>
                                        <option value="prefer_not_to_say">Prefer not to say</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Swimming Status</label>
                                    <select :name="'participants[' + index + '][swimmer_status]'" x-model="p.swimmer_status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                        <option value="non_swimmer">Non-Swimmer</option>
                                        <option value="casual_swimmer">Casual / Beginner Swimmer</option>
                                        <option value="swimmer">Confident Swimmer</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block font-bold text-[#1D1D1F] text-xs mb-1.5">Health Condition Notes (Free-text)</label>
                                    <input type="text" :name="'participants[' + index + '][health_condition]'" x-model="p.health_condition" placeholder="e.g. Asthma, allergies, ear issues, or None" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- 3. Primary Contact & Transportation -->
            <div class="space-y-4">
                <div class="flex items-center justify-between pb-1 flex-wrap gap-2">
                    <h3 class="text-base font-extrabold text-[#1D1D1F]">3. Primary Contact & Transportation</h3>
                    <span class="text-xs text-[#6E6E73]">Primary Booker / Coordinator</span>
                </div>

                <div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">
                                    Lead First Name <span class="text-[#780000]">*</span>
                                </label>
                                <template x-if="selectedLeadIndex === 'custom'">
                                    <button type="button" 
                                            @click="selectedLeadIndex = 0; syncLeadContactFromParticipant()" 
                                            class="text-xs text-[#780000] hover:underline font-semibold">
                                        Select from participants
                                    </button>
                                </template>
                            </div>

                            <!-- Lead first name dropdown (pick from participants) -->
                            <div x-show="selectedLeadIndex !== 'custom'">
                                <select x-model="selectedLeadIndex" 
                                        @change="onLeadSelectChange()"
                                        class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium transition-colors focus:border-[#780000]">
                                    <template x-for="(p, idx) in participants" :key="idx">
                                        <option :value="idx" x-text="(p.first_name ? p.first_name : ('Participant #' + (idx + 1))) + (p.last_name ? ' (' + p.name + ')' : '')"></option>
                                    </template>
                                    <option value="custom">Other (Enter custom name...)</option>
                                </select>
                                <input type="hidden" name="first_name" :value="leadFirstName">
                            </div>

                            <!-- Lead first name text box (when custom is picked) -->
                            <div x-show="selectedLeadIndex === 'custom'" x-cloak class="space-y-1">
                                <input type="text" 
                                       name="first_name" 
                                       x-model="leadFirstName" 
                                       :required="selectedLeadIndex === 'custom'"
                                       placeholder="e.g. Juan" 
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white transition-colors focus:border-[#780000]">
                            </div>
                        </div>
                        <div>
                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Lead Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                            <input type="text" 
                                   name="middle_name" 
                                   x-model="leadMiddleName" 
                                   :disabled="leadNoMiddleName || selectedLeadIndex !== 'custom'" 
                                   placeholder="Full middle name" 
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white disabled:bg-gray-100 disabled:text-gray-400">
                            <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73]" :class="selectedLeadIndex !== 'custom' ? 'cursor-default pointer-events-none' : 'cursor-pointer'">
                                <input type="checkbox" 
                                       name="no_middle_name" 
                                       value="1" 
                                       x-model="leadNoMiddleName" 
                                       :disabled="selectedLeadIndex !== 'custom'"
                                       @change="if(leadNoMiddleName) leadMiddleName = ''" 
                                       class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000] disabled:opacity-60">
                                <span>I do not have a legal middle name</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Lead Last Name <span class="text-[#780000]">*</span></label>
                            <input type="text" 
                                   name="last_name" 
                                   x-model="leadLastName" 
                                   :readonly="selectedLeadIndex !== 'custom'"
                                   required 
                                   placeholder="e.g. Dela Cruz or Santos-Concepcion" 
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] transition-colors focus:border-[#780000]"
                                   :class="selectedLeadIndex !== 'custom' ? 'bg-[#F9FAFB] cursor-default' : 'bg-white'">
                        </div>
                        <div>
                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                            <select name="suffix" 
                                    x-model="leadSuffix" 
                                    :disabled="selectedLeadIndex !== 'custom'"
                                    class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] font-medium disabled:bg-gray-100 disabled:text-gray-400"
                                    :class="selectedLeadIndex !== 'custom' ? 'bg-[#F9FAFB]' : 'bg-white'">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Contact Email <span class="text-[#780000]">*</span></label>
                        <input type="email" name="contact_email" required placeholder="email@example.com" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                    </div>
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Mobile Number <span class="text-[#780000]">*</span></label>
                        <input type="tel" name="contact_phone" required placeholder="0917 123 4567" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                    </div>
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Facebook / Messenger Profile</label>
                        <input type="text" name="contact_facebook" placeholder="fb.com/username (Optional)" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                    </div>
                </div>

                <!-- Transportation Choice -->
                <div class="space-y-3 pt-1">
                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">Transportation Option <span class="text-[#780000]">*</span></label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="Transportation Option">
                        <label tabindex="0"
                               role="radio"
                               :aria-checked="pickupOption === 'carpool'"
                               @keydown.enter.prevent="pickupOption = 'carpool'"
                               @keydown.space.prevent="pickupOption = 'carpool'"
                               class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex flex-col justify-between select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                               :class="pickupOption === 'carpool' ? 'ring-2 ring-[#780000]' : ''">
                            <input type="radio" name="pickup_option" value="carpool" x-model="pickupOption" class="hidden">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">Manila Carpool Van</span>
                                    <span x-show="pickupOption === 'carpool'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                </div>
                                <span class="font-bold text-[#780000] text-xs sm:text-sm block">₱1,200 / person</span>
                                <span class="text-xs sm:text-sm text-[#780000] font-semibold block">(DP: ₱3,000 / head)</span>
                            </div>
                        </label>

                        <label tabindex="0"
                               role="radio"
                               :aria-checked="pickupOption === 'own'"
                               @keydown.enter.prevent="pickupOption = 'own'"
                               @keydown.space.prevent="pickupOption = 'own'"
                               class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex flex-col justify-between select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                               :class="pickupOption === 'own' ? 'ring-2 ring-[#780000]' : ''">
                            <input type="radio" name="pickup_option" value="own" x-model="pickupOption" class="hidden">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">Own Vehicle / Commute</span>
                                    <span x-show="pickupOption === 'own'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                </div>
                                <span class="font-bold text-[#1D1D1F] text-xs sm:text-sm block">₱0 (Self-arranged)</span>
                                <span class="text-xs sm:text-sm text-[#6E6E73] font-semibold block">(DP: ₱2,000 / head)</span>
                            </div>
                        </label>
                    </div>

                    <!-- Carpool Pickup Location & Schedule -->
                    <div x-show="pickupOption === 'carpool'" x-cloak class="pt-1">
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Carpool Pickup Location & Schedule <span class="text-[#780000]">*</span></label>
                        <select name="pickup_location" 
                                x-model="pickupLocation"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-transparent text-sm text-[#1D1D1F] bg-transparent font-medium transition-all cursor-pointer hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus:ring-2 focus:ring-[#780000]"
                                :class="pickupLocation ? 'ring-2 ring-[#780000]' : 'ring-1 ring-[#D1D1D6]'">
                            <option value="" disabled selected class="bg-white text-[#1D1D1F]">-- Select Carpool Pickup Hub & Schedule --</option>
                            <template x-for="p in pickupPoints" :key="p.id">
                                <option :value="p.name" x-text="p.name" class="bg-white text-[#1D1D1F]"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Optional Boat Dive -->
                <div class="pt-1">
                    <label tabindex="0"
                           role="checkbox"
                           :aria-checked="boatDive"
                           @keydown.enter.prevent="boatDive = !boatDive"
                           @keydown.space.prevent="boatDive = !boatDive"
                           class="p-3.5 rounded-xl border border-transparent transition-all cursor-pointer flex items-start justify-between gap-3 select-none bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                           :class="boatDive ? 'ring-2 ring-[#780000]' : ''">
                        <input type="checkbox" name="boat_dive" value="1" x-model="boatDive" class="hidden">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-[#1D1D1F] block">Boat Dive (Optional)</span>
                                <span x-show="boatDive" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                            </div>
                            <span class="text-xs sm:text-sm text-[#6E6E73] block leading-snug">Enjoy the freedive site hopping experience across Sepoc Island, Sombrero Island and Dari Laut.</span>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="font-extrabold text-[#780000] text-xs sm:text-sm">+₱600</span>
                            <span class="text-[11px] sm:text-xs text-[#6E6E73] block">/ person</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Booking Cost & Live Fee Summary -->
            <div class="p-5 sm:p-6 rounded-2xl space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-extrabold text-[#1D1D1F] text-base">Booking Cost &amp; Fee Breakdown</h4>
                    <span class="text-xs font-bold text-[#780000] bg-red-50 px-2.5 py-0.5 rounded-full" x-text="participants.length + ' Diver' + (participants.length > 1 ? 's' : '')"></span>
                </div>

                <div class="space-y-2.5 text-xs sm:text-sm border-t border-[#E5E5EA] pt-3">
                    <!-- Course Fee -->
                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <div>
                            <span class="font-medium text-[#1D1D1F]" x-text="classType === 'discovery' ? 'Discovery Freediving Course' : (classType === 'refinement' ? 'Refinement Freediving Course' : (isCertified ? 'Fundive (Certified)' : 'Fundive (Non-Certified)'))"></span>
                            <span class="block text-xs text-[#8E8E93]" x-text="'(₱' + formatNumber(calculateBasePriceUnit()) + ' / head × ' + participants.length + ' pax)'"></span>
                        </div>
                        <span class="font-bold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateSubtotal())"></span>
                    </div>

                    <!-- Carpool Transportation -->
                    <div x-show="pickupOption === 'carpool'" class="flex justify-between items-center text-[#6E6E73]">
                        <div>
                            <span class="font-medium text-[#1D1D1F]">Transportation (Manila Carpool Service)</span>
                            <span class="block text-xs text-[#8E8E93]" x-text="'(₱1,200 / head × ' + participants.length + ' pax)'"></span>
                        </div>
                        <span class="font-bold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateCarpoolFee())"></span>
                    </div>

                    <!-- Sanctuary Boat Dive -->
                    <div x-show="boatDive" class="flex justify-between items-center text-[#6E6E73]">
                        <div>
                            <span class="font-medium text-[#1D1D1F]">Sanctuary Boat Dive</span>
                            <span class="block text-xs text-[#8E8E93]" x-text="'(₱600 / head × ' + participants.length + ' pax)'"></span>
                        </div>
                        <span class="font-bold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateBoatFee())"></span>
                    </div>

                    <!-- Mabini LGU Pass & Env. Fee -->
                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <div>
                            <span class="font-medium text-[#1D1D1F]">Mabini LGU Pass &amp; Env. Fee</span>
                            <span class="block text-xs text-[#8E8E93]" x-text="'(₱350 / head × ' + participants.length + ' pax: ₱300 LGU Pass + ₱50 Env. Fee)'"></span>
                        </div>
                        <strong class="font-bold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateLguAndEnvFee())"></strong>
                    </div>

                    <!-- Total Amount -->
                    <div class="flex justify-between items-center text-sm sm:text-base pt-3 border-t border-[#E5E5EA]">
                        <span class="font-extrabold text-[#1D1D1F]">Total Trip Amount:</span>
                        <strong class="font-black text-lg text-[#780000]" x-text="'₱' + formatNumber(calculateTotal())"></strong>
                    </div>

                    <!-- Downpayment vs Balance breakdown -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 text-xs sm:text-sm">
                        <div class="p-3 rounded-xl bg-white border border-[#E5E5EA]">
                            <span class="text-[#6E6E73] block mb-0.5">Required Downpayment:</span>
                            <strong class="text-[#065F46] font-bold text-sm block" x-text="'₱' + formatNumber(calculateDownpayment())"></strong>
                            <span class="text-[11px] text-[#6E6E73]" x-text="'(' + (pickupOption === 'carpool' ? '₱3,000' : '₱2,000') + ' / head × ' + participants.length + ' pax)'"></span>
                        </div>
                        <div class="p-3 rounded-xl bg-white border border-[#E5E5EA]">
                            <span class="text-[#6E6E73] block mb-0.5">Balance Due at Camp:</span>
                            <strong class="text-[#780000] font-bold text-sm block" x-text="'₱' + formatNumber(paymentStage === 'full' ? 0 : (calculateTotal() - calculateDownpayment()))"></strong>
                            <span class="text-[11px] text-[#6E6E73]" x-text="paymentStage === 'full' ? 'Settled in full' : 'Payable on arrival'"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Payment (offline) -->
            <div class="space-y-4">
                <h3 class="text-base font-extrabold text-[#1D1D1F]">4. Payment Recording</h3>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Payment Channel <span class="text-[#780000]">*</span></label>
                        <select name="payment_method" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option value="gcash">GCash (Direct Transfer)</option>
                            <option value="bpi_bank_transfer">BPI Bank Transfer</option>
                            <option value="maya">Maya / PayMaya</option>
                            <option value="bdo">BDO Unibank</option>
                            <option value="unionbank">UnionBank</option>
                            <option value="cash">Cash (On-Site / Reception)</option>
                            <option value="other">Other Bank / E-Wallet</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Payment Stage <span class="text-[#780000]">*</span></label>
                        <select name="payment_stage" x-model="paymentStage" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option value="downpayment">Required Downpayment Only</option>
                            <option value="full">Full Settlement (100%)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Payment Status <span class="text-[#780000]">*</span></label>
                        <select name="payment_status" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option value="completed">Payment Verified (Completed)</option>
                            <option value="pending">Pending Verification</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Admin / Coordinator Note</label>
                    <textarea name="admin_notes" rows="2" placeholder="e.g. Phone reservation confirmed via WhatsApp" class="w-full px-3.5 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white"></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.bookings.index') }}" class="btn-secondary min-h-[44px] px-6 py-2.5 text-sm font-bold inline-flex items-center justify-center active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    Cancel
                </a>

                <button type="submit" class="btn-primary min-h-[44px] px-8 py-2.5 text-sm font-bold shadow-md inline-flex items-center justify-center active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    Create Booking
                </button>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function adminBookingCreate(config) {
    return {
        config: config,
        pricingConfig: config.pricingConfig || {},
        classType: 'discovery',
        isCertified: false,
        pickupOption: 'carpool',
        pickupLocation: config.pickupPoints && config.pickupPoints.length > 0 ? config.pickupPoints[0].name : '',
        boatDive: false,
        paymentStage: 'downpayment',
        pickupPoints: config.pickupPoints || [],
        startDate: '',
        endDate: '',
        forecast: null,
        weatherLoading: false,
        selectedLeadIndex: 0,
        leadFirstName: '',
        leadMiddleName: '',
        leadNoMiddleName: false,
        leadLastName: '',
        leadSuffix: '',
        participants: [
            { first_name: '', middle_name: '', no_middle_name: false, last_name: '', suffix: '', name: '', birthdate: '', gender: '', age: '', swimmer_status: 'non_swimmer', health_condition: '' }
        ],

        initForm() {
            this.syncLeadContactFromParticipant();
        },

        assembleParticipantName(p) {
            const parts = [
                p.first_name || '',
                (!p.no_middle_name && p.middle_name) ? p.middle_name : '',
                p.last_name || '',
                p.suffix || ''
            ].filter(s => s.trim().length > 0);
            p.name = parts.join(' ');

            const pIdx = this.participants.indexOf(p);
            if (this.participants.length === 1 || parseInt(this.selectedLeadIndex, 10) === pIdx) {
                this.syncLeadContactFromParticipant();
            }
            return p.name;
        },

        syncLeadContactFromParticipant() {
            if (this.participants.length === 1) {
                this.selectedLeadIndex = 0;
            }
            if (this.selectedLeadIndex === 'custom') {
                return;
            }
            const idx = parseInt(this.selectedLeadIndex, 10);
            if (!isNaN(idx) && this.participants[idx]) {
                const p = this.participants[idx];
                this.leadFirstName = p.first_name || '';
                this.leadMiddleName = p.middle_name || '';
                this.leadNoMiddleName = !!p.no_middle_name;
                this.leadLastName = p.last_name || '';
                this.leadSuffix = p.suffix || '';
            }
        },

        onLeadSelectChange() {
            if (this.selectedLeadIndex === 'custom') {
                return;
            }
            this.syncLeadContactFromParticipant();
        },

        async onStartDateChange() {
            if (!this.startDate) {
                this.endDate = '';
                this.forecast = null;
                return;
            }
            const start = new Date(this.startDate);
            const end = new Date(start);
            end.setDate(start.getDate() + 1);
            this.endDate = end.toISOString().split('T')[0];

            this.weatherLoading = true;
            try {
                const response = await fetch(config.checkWeatherUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken
                    },
                    body: JSON.stringify({
                        start_date: this.startDate,
                        end_date: this.endDate
                    })
                });
                const data = await response.json();
                this.forecast = data;
            } catch (e) {
                console.error('Weather check error:', e);
            } finally {
                this.weatherLoading = false;
            }
        },

        getSafetyScore(classification) {
            switch (classification) {
                case 'Very Safe': return 5;
                case 'Safe': return 4;
                case 'Moderate': return 3;
                case 'High Risk': return 2;
                case 'Critical Risk': return 1;
                default: return 4;
            }
        },

        getSafetyBarClass(classification) {
            switch (classification) {
                case 'Very Safe':
                case 'Safe':
                    return 'bg-[#10B981]';
                case 'Moderate':
                    return 'bg-[#F59E0B]';
                case 'High Risk':
                    return 'bg-[#F43F5E]';
                case 'Critical Risk':
                    return 'bg-[#EF4444]';
                default:
                    return 'bg-[#10B981]';
            }
        },

        getSafetyTextClass(classification) {
            switch (classification) {
                case 'Very Safe':
                case 'Safe':
                    return 'text-[#10B981]';
                case 'Moderate':
                    return 'text-[#F59E0B]';
                case 'High Risk':
                    return 'text-[#F43F5E]';
                case 'Critical Risk':
                    return 'text-[#EF4444]';
                default:
                    return 'text-[#10B981]';
            }
        },

        addParticipant() {
            this.participants.push({
                first_name: '',
                middle_name: '',
                no_middle_name: false,
                last_name: '',
                suffix: '',
                name: '',
                age: '',
                birthdate: '',
                gender: '',
                swimmer_status: 'non_swimmer',
                health_condition: ''
            });
            if (this.participants.length === 1) {
                this.selectedLeadIndex = 0;
                this.syncLeadContactFromParticipant();
            }
        },

        removeParticipant(index) {
            if (this.participants.length > 1) {
                this.participants.splice(index, 1);
                if (this.participants.length === 1 || parseInt(this.selectedLeadIndex, 10) === index) {
                    this.selectedLeadIndex = 0;
                    this.syncLeadContactFromParticipant();
                } else if (this.selectedLeadIndex > index && this.selectedLeadIndex !== 'custom') {
                    this.selectedLeadIndex--;
                }
            }
        },

        calculateBasePriceUnit() {
            const basePrices = (this.pricingConfig && this.pricingConfig.basePrices) ? this.pricingConfig.basePrices : {
                discovery: 4250,
                fundive_cert: 2500,
                fundive_noncert: 3300,
                refinement: 4100
            };
            let price = basePrices.discovery || 4250;
            if (this.classType === 'fundive') {
                price = this.isCertified ? (basePrices.fundive_cert || 2500) : (basePrices.fundive_noncert || 3300);
            } else if (this.classType === 'refinement') {
                price = basePrices.refinement || 4100;
            }
            return price;
        },

        calculateSubtotal() {
            return this.calculateBasePriceUnit() * this.participants.length;
        },

        calculateCarpoolFee() {
            const carpoolFee = (this.pricingConfig && this.pricingConfig.fees && this.pricingConfig.fees.carpool) ? this.pricingConfig.fees.carpool : 1200;
            return this.pickupOption === 'carpool' ? (carpoolFee * this.participants.length) : 0;
        },

        calculateBoatFee() {
            const boatFee = (this.pricingConfig && this.pricingConfig.fees && this.pricingConfig.fees.boat_dive) ? this.pricingConfig.fees.boat_dive : 600;
            return this.boatDive ? (boatFee * this.participants.length) : 0;
        },

        calculateLguFee() {
            const lguFee = (this.pricingConfig && this.pricingConfig.fees && this.pricingConfig.fees.lgu_pass) ? this.pricingConfig.fees.lgu_pass : 300;
            return lguFee * this.participants.length;
        },

        calculateEnvironmentalFee() {
            const envFee = (this.pricingConfig && this.pricingConfig.fees && this.pricingConfig.fees.environmental) ? this.pricingConfig.fees.environmental : 50;
            return envFee * this.participants.length;
        },

        calculateLguAndEnvFee() {
            return this.calculateLguFee() + this.calculateEnvironmentalFee();
        },

        calculateTotal() {
            return this.calculateSubtotal() + this.calculateCarpoolFee() + this.calculateBoatFee() + this.calculateLguAndEnvFee();
        },

        calculateDownpayment() {
            const total = this.calculateTotal();
            const count = this.participants.length;
            const carpoolDp = (this.pricingConfig && this.pricingConfig.downpayments && this.pricingConfig.downpayments.carpool) ? this.pricingConfig.downpayments.carpool : 3000;
            const ownTranspoDp = (this.pricingConfig && this.pricingConfig.downpayments && this.pricingConfig.downpayments.own_transpo) ? this.pricingConfig.downpayments.own_transpo : 2000;
            const dpPerHead = (this.pickupOption === 'carpool') ? carpoolDp : ownTranspoDp;
            return Math.min(dpPerHead * count, total);
        },

        formatNumber(num) {
            return (num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
@endpush
