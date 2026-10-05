@extends('layouts.admin')

@section('title', 'Create Batch | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('batches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Batches</a>
    <svg class="w-3.5 h-3.5 text-[#6E6E73] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Create Batch</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6 text-sm" x-data="batchCreateForm()">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Create Batch</h1>
        </div>
    </div>

    <!-- Create Batch Form -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-8 shadow-2xs">
        <form action="{{ route('admin.batches.store') }}" method="POST" class="space-y-8">
            @csrf

            <!-- Batch Dates and Identification -->
            <div class="space-y-4">
                <h3 class="text-base font-bold text-[#1D1D1F] pb-2">1. Dive Dates & Batch Identifier</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="start_date" class="block font-bold text-[#1D1D1F] text-sm mb-2">
                            Start Date (Day 1) <span class="text-[#780000]">*</span>
                        </label>
                        <x-date-picker id="start_date" name="start_date" model="startDate" x-on:change="fetchUnbatchedBookings()" required />
                        @error('start_date')
                            <span class="text-sm text-[#D70015] font-semibold mt-1.5 flex items-center gap-1.5">
                                <svg class="w-4 h-4 shrink-0 text-[#D70015]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                <span>{{ $message }}</span>
                            </span>
                        @enderror
                    </div>

                    <div>
                        <label for="end_date" class="block font-bold text-[#1D1D1F] text-sm mb-2">
                            End Date (Day 2) <span class="text-[#780000]">*</span>
                        </label>
                        <x-date-picker id="end_date" name="end_date" model="endDate" required />
                        @error('end_date')
                            <span class="text-sm text-[#D70015] font-semibold mt-1.5 flex items-center gap-1.5">
                                <svg class="w-4 h-4 shrink-0 text-[#D70015]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                <span>{{ $message }}</span>
                            </span>
                        @enderror
                    </div>
                </div>

                <!-- Duplicate Batch Date Warning Alert & Direct Redirect -->
                <template x-if="duplicateBatches.length > 0">
                    <div class="banner banner-warning space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 font-extrabold text-sm text-[#78350F]">
                                <span>Batch Already Exists for this Date Range!</span>
                            </div>
                        </div>
                        
                        <p class="text-sm text-[#92400E] leading-relaxed">
                            To avoid double-scheduling the same weekend, an active batch is already handling <strong x-text="startDate"></strong>. Open the existing batch to manage participants and coaches instead of creating a duplicate:
                        </p>
                        
                        <div class="space-y-2">
                            <template x-for="dup in duplicateBatches" :key="dup.id">
                                <div class="p-3 rounded-xl bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm">
                                    <div>
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <strong class="text-[#1D1D1F] text-sm" x-text="dup.name || dup.batch_number"></strong>
                                        </div>
                                        <div class="text-xs text-[#6E6E73] space-y-0.5 mt-0.5">
                                            <span class="block" x-text="(dup.participants_count || 0) + ' Pax assigned'"></span>
                                            <span class="block" x-text="(dup.coaches_count || 0) + ' Coach(es) staffed'"></span>
                                        </div>
                                    </div>
                                    <a :href="'/admin/batches/' + dup.id" class="btn-primary min-h-[44px] px-4 py-2.5 text-sm font-bold shrink-0 inline-flex items-center justify-center gap-1.5 whitespace-nowrap active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                                        <span>Open Existing Batch</span>
                                    </a>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <div>
                    <label for="batch_number_digits" class="block font-bold text-[#1D1D1F] text-sm mb-2">
                        Batch Number<span class="text-[#780000]">*</span>
                    </label>
                    <div class="relative flex items-center rounded-xl border border-[#D1D1D6] bg-white overflow-hidden focus-within:border-[#780000] focus-within:ring-2 focus-within:ring-[#780000]/20 max-w-xs">
                        <span class="px-4 py-2.5 bg-[#F2F2F7] border-r border-[#D1D1D6] text-sm font-extrabold text-[#1D1D1F] select-none shrink-0">
                            Batch
                        </span>
                        <input type="number" 
                               name="batch_number_digits" 
                               id="batch_number_digits" 
                               x-model="batchNumberOnly" 
                               min="1" 
                               step="1" 
                               required 
                               placeholder="e.g. 10"
                               class="w-full px-3.5 py-2.5 font-mono text-sm font-bold text-[#1D1D1F] bg-transparent border-0 focus:ring-0 focus:outline-none">
                        <input type="hidden" name="batch_number" :value="'Batch ' + (batchNumberOnly || '')">
                    </div>
                </div>

            </div>

            <!-- Auto-Grouping Confirmed Unbatched Bookings -->
            <div class="space-y-4 pt-2">
                <div class="flex items-center justify-between pb-2">
                    <div>
                        <h3 class="text-base font-bold text-[#1D1D1F]">2. Group Confirmed Bookings for this Date</h3>
                        <p class="text-sm text-[#6E6E73] mt-0.5">
                            Auto-suggested bookings matching this dive date. Uncheck any private or custom arrangements.
                        </p>
                    </div>

                    <div class="text-sm text-[#780000] font-bold">
                        <span x-text="selectedBookingIds.length"></span> / <span x-text="unbatchedBookings.length"></span> Bookings Selected
                    </div>
                </div>

                <div x-show="loadingBookings" class="py-6 text-center text-sm text-[#6E6E73]">
                    Scanning confirmed bookings for selected date...
                </div>

                <!-- Bookings Table Container -->
                <div x-show="!loadingBookings && unbatchedBookings.length > 0" class="border border-[#D1D1D6] rounded-xl overflow-hidden">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#F2F2F7] border-b border-[#D1D1D6] text-[#6E6E73] font-bold">
                            <tr>
                                <th scope="col" class="py-1 px-1 w-12 text-center">
                                    <label class="min-w-[44px] min-h-[44px] inline-flex items-center justify-center cursor-pointer m-0">
                                        <input type="checkbox" 
                                               @change="toggleAll($event.target.checked)" 
                                               checked 
                                               aria-label="Select all unbatched bookings"
                                               class="w-4.5 h-4.5 rounded-md text-[#780000] focus:ring-[#780000]">
                                    </label>
                                </th>
                                <th scope="col" class="py-2.5 px-3">Booking #</th>
                                <th scope="col" class="py-2.5 px-3">Lead Contact</th>
                                <th scope="col" class="py-2.5 px-3">Class</th>
                                <th scope="col" class="py-2.5 px-3 text-center">Pax</th>
                                <th scope="col" class="py-2.5 px-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#D1D1D6]">
                            <template x-for="b in paginatedBookings" :key="b.id">
                                <tr class="hover:bg-[#F2F2F7]">
                                    <td class="py-1 px-1 text-center">
                                        <label class="min-w-[44px] min-h-[44px] inline-flex items-center justify-center cursor-pointer m-0">
                                            <input type="checkbox" 
                                                   name="booking_ids[]" 
                                                   :value="b.id" 
                                                   x-model="selectedBookingIds"
                                                   :aria-label="'Select booking ' + b.booking_number"
                                                   class="w-4.5 h-4.5 rounded-md text-[#780000] focus:ring-[#780000]">
                                        </label>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono font-bold text-[#780000]" x-text="b.booking_number"></td>
                                    <td class="py-2.5 px-3 text-[#1D1D1F]" x-text="b.contact_name"></td>
                                    <td class="py-2.5 px-3 text-[#6E6E73]" x-text="b.class_type"></td>
                                    <td class="py-2.5 px-3 text-center font-bold text-[#1D1D1F]" x-text="b.participants_count"></td>
                                    <td class="py-2.5 px-3 text-right font-semibold text-[#1D1D1F]" x-text="'₱' + b.total_amount"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <!-- Pagination Controls -->
                    <div x-show="totalPages > 1" class="bg-[#F2F2F7] px-3.5 py-2.5 border-t border-[#E5E5EA] flex flex-col sm:flex-row items-center justify-between gap-2.5 text-sm text-[#6E6E73] rounded-b-xl select-none">
                        <div class="font-medium text-[#6E6E73] text-center sm:text-left">
                            Showing <span class="font-bold text-[#1D1D1F]" x-text="startItem"></span> to <span class="font-bold text-[#1D1D1F]" x-text="endItem"></span> of <span class="font-bold text-[#1D1D1F]" x-text="unbatchedBookings.length"></span> results
                        </div>
                        <div class="flex items-center justify-between sm:justify-end gap-2 w-full sm:w-auto">
                            <span class="font-medium text-[#6E6E73]">
                                Page <span class="font-bold text-[#1D1D1F]" x-text="currentPage"></span> of <span class="font-bold text-[#1D1D1F]" x-text="totalPages"></span>
                            </span>
                            <div class="inline-flex items-center gap-1.5">
                                <button type="button" 
                                        @click="currentPage--" 
                                        :disabled="currentPage <= 1"
                                        aria-label="Previous page"
                                        class="inline-flex items-center justify-center px-3.5 py-2 min-h-[44px] rounded-xl bg-white border border-[#D1D1D6] text-[#1D1D1F] font-bold text-sm hover:bg-[#F2F2F7] hover:border-[#6E6E73] active:bg-[#E5E5EA] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-[#F2F2F7] disabled:border-[#E5E5EA] disabled:text-[#6E6E73] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                                    <svg class="w-4 h-4 mr-1.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                                    </svg>
                                    <span>Prev</span>
                                </button>
                                <button type="button" 
                                        @click="currentPage++" 
                                        :disabled="currentPage >= totalPages"
                                        aria-label="Next page"
                                        class="inline-flex items-center justify-center px-3.5 py-2 min-h-[44px] rounded-xl bg-white border border-[#D1D1D6] text-[#1D1D1F] font-bold text-sm hover:bg-[#F2F2F7] hover:border-[#6E6E73] active:bg-[#E5E5EA] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-[#F2F2F7] disabled:border-[#E5E5EA] disabled:text-[#6E6E73] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                                    <span>Next</span>
                                    <svg class="w-4 h-4 ml-1.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="!loadingBookings && unbatchedBookings.length === 0" class="py-8 px-4 text-center text-sm text-[#6E6E73] bg-[#F2F2F7] rounded-xl">
                    No unbatched confirmed bookings found for this start date. You can add bookings later from the batch dashboard.
                </div>
            </div>

            <!-- Operational Notes & Overrides -->
            <div class="space-y-4 pt-2">
                <h3 class="text-base font-bold text-[#1D1D1F] pb-2">3. Operational Notes & Overrides</h3>

                <div>
                    <label for="capacity_note" class="block font-bold text-[#1D1D1F] text-sm mb-2">
                        Capacity / Group Structure Tag (Optional)
                    </label>
                    <input type="text" 
                           name="capacity_note" 
                           id="capacity_note" 
                           placeholder="e.g. Discovery Group Batch / Holding 2 slots for late inquiry"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label for="notes" class="block font-bold text-[#1D1D1F] text-sm mb-2">
                        Internal Staff Notes
                    </label>
                    <textarea name="notes" 
                              id="notes" 
                              rows="3" 
                              placeholder="Venue arrangements, resort details, or coach notes..." 
                              class="w-full px-3.5 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white"></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.batches.index') }}" class="btn-secondary min-h-[44px] px-6 py-2.5 text-sm font-bold inline-flex items-center justify-center active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">Cancel</a>
                <button type="submit" class="btn-primary min-h-[44px] px-8 py-2.5 text-sm font-bold inline-flex items-center justify-center active:scale-[0.99] transition-all shadow-md focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    Create Batch
                </button>
            </div>
        </form>
    </div>

</div>

@php
    $initBatchNumberOnly = (string) preg_replace('/^Batch\s*/i', '', (string) old('batch_number', $defaultBatchNumber));
@endphp

<script>
function batchCreateForm() {
    return {
        startDate: @json(old('start_date', $defaultStartDateStr)),
        endDate: @json(old('end_date', $defaultEndDateStr)),
        batchNumberOnly: @json($initBatchNumberOnly),
        unbatchedBookings: @json($initialBookings),
        selectedBookingIds: @json($selectedIds),
        existingBatches: @json($existingBatches ?? []),
        existingBatchesForSelectedDate: [],
        staffingRec: @json($initialStaffingRec ?? null),
        loadingBookings: false,
        perPage: 5,
        currentPage: 1,

        get batchNumber() {
            return 'Batch ' + (this.batchNumberOnly || '');
        },

        get duplicateBatches() {
            if (this.existingBatchesForSelectedDate && this.existingBatchesForSelectedDate.length > 0) {
                return this.existingBatchesForSelectedDate;
            }
            if (!this.startDate) return [];
            return (this.existingBatches || []).filter(b => b.start_date === this.startDate);
        },

        get paginatedBookings() {
            const start = (this.currentPage - 1) * this.perPage;
            return (this.unbatchedBookings || []).slice(start, start + this.perPage);
        },

        get totalPages() {
            return Math.ceil((this.unbatchedBookings || []).length / this.perPage) || 1;
        },

        get startItem() {
            if (!this.unbatchedBookings || this.unbatchedBookings.length === 0) return 0;
            return (this.currentPage - 1) * this.perPage + 1;
        },

        get endItem() {
            if (!this.unbatchedBookings) return 0;
            return Math.min(this.currentPage * this.perPage, this.unbatchedBookings.length);
        },

        fetchUnbatchedBookings() {
            if (!this.startDate) return;

            // Auto update end date (Day 2)
            const parts = this.startDate.split('-');
            if (parts.length === 3) {
                const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                d.setDate(d.getDate() + 1);
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                this.endDate = `${year}-${month}-${day}`;
            }

            this.loadingBookings = true;
            this.currentPage = 1;
            fetch('{{ route('admin.batches.unbatched_bookings') }}?date=' + this.startDate)
                .then(res => res.json())
                .then(data => {
                    this.unbatchedBookings = data.bookings || [];
                    this.selectedBookingIds = (data.bookings || []).map(b => b.id);
                    if (data.suggested_batch_number_only) {
                        this.batchNumberOnly = data.suggested_batch_number_only;
                    } else if (data.suggested_batch_number) {
                        this.batchNumberOnly = data.suggested_batch_number.replace(/^Batch\s*/i, '');
                    }
                    if (data.existing_batches) {
                        this.existingBatchesForSelectedDate = data.existing_batches;
                    }
                    if (data.ml_recommendation) {
                        this.staffingRec = data.ml_recommendation;
                    }
                    this.loadingBookings = false;
                })
                .catch(err => {
                    this.loadingBookings = false;
                });
        },

        toggleAll(checked) {
            if (checked) {
                this.selectedBookingIds = this.unbatchedBookings.map(b => b.id);
            } else {
                this.selectedBookingIds = [];
            }
        }
    };
}
</script>
@endsection
