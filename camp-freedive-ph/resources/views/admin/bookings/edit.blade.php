@extends('layouts.admin')

@section('title', 'Edit Booking #' . $booking->booking_number . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('bookings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Bookings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <a href="{{ portal_route('bookings.show', $booking) }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">{{ $booking->booking_number }}</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Edit</span>
@endsection

@section('content')
@php
    $suffixList = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'Jr', 'Sr'];
    $parseName = function($fullName) use ($suffixList) {
        $rawParts = preg_split('/\s+/', trim($fullName ?? ''));
        $suffix = '';
        if (count($rawParts) > 1 && in_array(end($rawParts), $suffixList, true)) {
            $suffix = array_pop($rawParts);
        }
        $first = $rawParts[0] ?? '';
        $middle = '';
        $last = '';
        if (count($rawParts) === 2) {
            $last = $rawParts[1];
        } elseif (count($rawParts) > 2) {
            $last = array_pop($rawParts);
            array_shift($rawParts);
            $middle = implode(' ', $rawParts);
        }
        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'no_middle_name' => false,
            'last_name' => $last,
            'suffix' => in_array($suffix, ['Jr', 'Sr']) ? $suffix . '.' : $suffix,
            'name' => $fullName,
        ];
    };
    $contactParsed = $parseName($booking->contact_name);
@endphp
<div class="max-w-4xl mx-auto space-y-6 text-sm"
     x-data="{
         participants: {{ json_encode($booking->participants->map(function($p) use ($parseName) {
             $parsed = $parseName($p->name);
             return [
                 'id' => $p->id,
                 'name' => $p->name,
                 'first_name' => $parsed['first_name'],
                 'middle_name' => $parsed['middle_name'],
                 'no_middle_name' => false,
                 'last_name' => $parsed['last_name'],
                 'suffix' => $parsed['suffix'],
                 'birthdate' => optional($p->birthdate)->format('Y-m-d'),
                 'gender' => $p->gender,
                 'age' => $p->age,
                 'swimmer_status' => $p->swimmer_status,
                 'health_condition' => $p->health_condition,
             ];
         })) }},
         pickupPoints: {{ json_encode($pickupPoints) }},
         contact_first_name: '{{ addslashes($contactParsed['first_name']) }}',
         contact_middle_name: '{{ addslashes($contactParsed['middle_name']) }}',
         contact_no_middle_name: false,
         contact_last_name: '{{ addslashes($contactParsed['last_name']) }}',
         contact_suffix: '{{ addslashes($contactParsed['suffix']) }}',
         contact_name: '{{ addslashes($booking->contact_name) }}',
         assembleParticipantName(p) {
             const parts = [
                 p.first_name || '',
                 (!p.no_middle_name && p.middle_name) ? p.middle_name : '',
                 p.last_name || '',
                 p.suffix || ''
             ].filter(s => s.trim().length > 0);
             p.name = parts.join(' ');
             return p.name;
         },
         assembleContactName() {
             const parts = [
                 this.contact_first_name || '',
                 (!this.contact_no_middle_name && this.contact_middle_name) ? this.contact_middle_name : '',
                 this.contact_last_name || '',
                 this.contact_suffix || ''
             ].filter(s => s.trim().length > 0);
             this.contact_name = parts.join(' ');
             return this.contact_name;
         },
         cleanNameInput(val) {
             if (!val) return '';
             return val.toString().replace(/[^\p{L}\s.'-]/gu, '');
         }
     }">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Edit Booking &amp; Participant Details</h1>
        </div>
    </div>

    <form action="{{ route('admin.bookings.update', $booking) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- SECTION 1: TRIP DATES & LOGISTICS -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-4">
            <h3 class="text-base font-bold text-[#1D1D1F] pb-2">1. Dive Dates & Transportation Hub</h3>

            <!-- Hidden locked fields -->
            <input type="hidden" name="pickup_option" value="{{ $booking->pickup_option }}">
            <input type="hidden" name="boat_dive" value="{{ $booking->boat_dive ? 1 : 0 }}">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-2">Start Date (Day 1) <span class="text-[#780000]">*</span></label>
                    <x-date-picker name="start_date" :value="old('start_date', $booking->start_date->format('Y-m-d'))" required />
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-2">End Date (Day 2) <span class="text-[#780000]">*</span></label>
                    <x-date-picker name="end_date" :value="old('end_date', $booking->end_date->format('Y-m-d'))" required />
                </div>
            </div>

            <!-- Carpool pickup (can only change if they have carpool) -->
            <div class="pt-2">
                @if($booking->pickup_option === 'carpool')
                    <div>
                        <label class="block font-bold text-[#1D1D1F] mb-2">Carpool Pickup Location & Schedule</label>
                        <select name="pickup_location" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                            @foreach($pickupPoints as $pt)
                                <option value="{{ $pt['name'] }}" {{ $booking->pickup_location === $pt['name'] ? 'selected' : '' }}>
                                    {{ $pt['name'] }}
                                </option>
                            @endforeach
                        </select>
                        <span class="text-sm text-[#6E6E73] block mt-1">Manila Carpool Service (₱1,200/person roundtrip)</span>
                    </div>
                @else
                    <div class="p-3.5 bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] text-sm space-y-1">
                        <span class="text-[#6E6E73] block">Transportation Mode:</span>
                        <strong class="text-[#1D1D1F] text-sm block">Own Transportation (Direct to Anilao Resort)</strong>
                        <span class="text-sm text-[#8E8E93] block">Transportation mode is fixed to preserve original downpayment breakdown.</span>
                    </div>
                @endif
            </div>

            <!-- Boat dive (read only) -->
            <div class="pt-1 text-sm text-[#6E6E73] flex items-center gap-2">
                <span>Sanctuary Boat Dive Add-on:</span>
                <strong class="text-[#1D1D1F]">{{ $booking->boat_dive ? 'Yes (+₱600/person included)' : 'No (Shore Sanctuary Dives Only)' }}</strong>
            </div>
        </div>

        <!-- SECTION 2: PARTICIPANTS & HEALTH NOTES -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-4">
            <div class="flex items-center justify-between pb-2">
                <div>
                    <h3 class="text-base font-bold text-[#1D1D1F]">2. Divers & Participants ({{ $booking->participants->count() }} pax)</h3>
                    <p class="text-sm text-[#6E6E73]">Update participant medical and roster information for this reservation.</p>
                </div>
                <span class="text-sm text-[#6E6E73] font-semibold bg-[#F2F2F7] px-2 py-0.5 rounded">Fixed Participant Count</span>
            </div>

            <div class="space-y-4">
                <template x-for="(p, index) in participants" :key="index">
                    <div class="p-4 rounded-xl space-y-3">
                        <input type="hidden" :name="'participants[' + index + '][id]'" :value="p.id">

                        <div class="flex items-center justify-between">
                            <span class="font-bold text-[#780000]" x-text="'Participant #' + (index + 1)"></span>
                            <span class="text-sm font-mono text-[#8E8E93]" x-text="'ID: ' + (p.id || 'Existing')"></span>
                        </div>

                        <input type="hidden" :name="'participants[' + index + '][name]'" :value="assembleParticipantName(p)">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">First Name <span class="text-[#780000]">*</span></label>
                                <input type="text"
                                       x-model="p.first_name"
                                       @input="p.first_name = cleanNameInput(p.first_name); assembleParticipantName(p)"
                                       placeholder="e.g. Maria Ma."
                                       required
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                    <label class="inline-flex items-center gap-1.5 text-xs text-[#6E6E73] cursor-pointer">
                                        <input type="checkbox"
                                               x-model="p.no_middle_name"
                                               @change="if(p.no_middle_name) p.middle_name = ''; assembleParticipantName(p)"
                                               class="rounded border-gray-300 text-[#780000] focus:ring-[#780000]/30 h-3.5 w-3.5">
                                        <span class="text-[11px]">No middle name</span>
                                    </label>
                                </div>
                                <input type="text"
                                       x-model="p.middle_name"
                                       :disabled="p.no_middle_name"
                                       @input="p.middle_name = cleanNameInput(p.middle_name); assembleParticipantName(p)"
                                       placeholder="Full middle name"
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] transition-colors disabled:bg-gray-100 disabled:text-gray-400">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Last Name <span class="text-[#780000]">*</span></label>
                                <input type="text"
                                       x-model="p.last_name"
                                       @input="p.last_name = cleanNameInput(p.last_name); assembleParticipantName(p)"
                                       placeholder="e.g. Santos-Concepcion or De la Cruz"
                                       required
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                            </div>

                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                <select x-model="p.suffix"
                                        @change="assembleParticipantName(p)"
                                        class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium focus:border-[#780000]">
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Birthdate <span class="text-[#780000]">*</span></label>
                                <x-date-picker name-expr="'participants[' + index + '][birthdate]'" model="p.birthdate" :max="now()->toDateString()" year-select placeholder="Select birthdate" required />
                                <select :name="'participants[' + index + '][gender]'" x-model="p.gender" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                                    <option value="">Select gender</option>
                                    <option value="female">Female</option>
                                    <option value="male">Male</option>
                                    <option value="non_binary">Non-binary</option>
                                    <option value="prefer_not_to_say">Prefer not to say</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Swimming Status</label>
                                <select :name="'participants[' + index + '][swimmer_status]'" x-model="p.swimmer_status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                    <option value="non_swimmer">Non-Swimmer</option>
                                    <option value="casual_swimmer">Casual / Beginner Swimmer</option>
                                    <option value="swimmer">Confident Swimmer</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Health Condition Notes</label>
                            <input type="text" :name="'participants[' + index + '][health_condition]'" x-model="p.health_condition" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- SECTION 3: PRIMARY CONTACT -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-4">
            <h3 class="text-base font-bold text-[#1D1D1F] pb-2">3. Primary Contact</h3>

            <input type="hidden" name="contact_name" :value="assembleContactName()">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Lead First Name <span class="text-[#780000]">*</span></label>
                    <input type="text"
                           x-model="contact_first_name"
                           @input="contact_first_name = cleanNameInput(contact_first_name); assembleContactName()"
                           placeholder="Juan"
                           required
                           class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">Lead Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-[#6E6E73] cursor-pointer">
                            <input type="checkbox"
                                   x-model="contact_no_middle_name"
                                   @change="if(contact_no_middle_name) contact_middle_name = ''; assembleContactName()"
                                   class="rounded border-gray-300 text-[#780000] focus:ring-[#780000]/30 h-3.5 w-3.5">
                            <span class="text-[11px]">No middle name</span>
                        </label>
                    </div>
                    <input type="text"
                           x-model="contact_middle_name"
                           :disabled="contact_no_middle_name"
                           @input="contact_middle_name = cleanNameInput(contact_middle_name); assembleContactName()"
                           placeholder="Full middle name"
                           class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] transition-colors disabled:bg-gray-100 disabled:text-gray-400">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Lead Last Name <span class="text-[#780000]">*</span></label>
                    <input type="text"
                           x-model="contact_last_name"
                           @input="contact_last_name = cleanNameInput(contact_last_name); assembleContactName()"
                           placeholder="Dela Cruz"
                           required
                           class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                    <select x-model="contact_suffix"
                            @change="assembleContactName()"
                            class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium focus:border-[#780000]">
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

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Email <span class="text-[#780000]">*</span></label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', $booking->contact_email) }}" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>
                <div>
                    <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Mobile Phone <span class="text-[#780000]">*</span></label>
                    <input type="tel" name="contact_phone" value="{{ old('contact_phone', $booking->contact_phone) }}" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>
            </div>
        </div>

        <!-- 4. Reason for the edit (required, RA 10173 audit) -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-3">
            <div class="flex items-center justify-between pb-2">
                <h3 class="text-base font-bold text-[#1D1D1F]">4. System Audit Log Note</h3>
                <span class="text-sm text-[#780000] font-bold">Mandatory</span>
            </div>

            <div>
                <label class="block font-bold text-[#1D1D1F] text-sm mb-2">
                    Reason for Modifying Booking & Participant Details <span class="text-[#780000]">*</span>
                </label>
                <textarea name="edit_reason" rows="2" required placeholder="e.g. Corrected spelling of participant name per customer WhatsApp request" class="w-full px-3.5 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white"></textarea>
                <span class="text-sm text-[#6E6E73] block mt-1">
                    This note and the exact changes will be recorded immutably in the system audit logs alongside your account username and IP address.
                </span>
            </div>
        </div>

        <!-- Customer notification notice -->
        <div class="rounded-xl border border-[#F5D0D0] bg-[#FFF5F5] p-4 text-sm text-[#1D1D1F]">
            <span class="font-bold text-[#780000]">Note:</span>
            These changes will be sent to the customer. Once saved, an email listing the updated details will be sent to
            <strong>{{ $booking->contact_email }}</strong> (and to the new address if you changed the contact email).
        </div>

        <!-- Controls -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="{{ route('admin.bookings.show', $booking) }}" class="btn-secondary px-5 py-2.5 text-sm">Cancel</a>
            <button type="submit" class="btn-primary px-7 py-2.5 text-sm font-bold shadow-md">
                Save Changes & Update Audit Trail
            </button>
        </div>
    </form>
</div>
@endsection
