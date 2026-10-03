@extends('layouts.admin')

@section('title', 'Record Manual Payment | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Record Manual Payment</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto space-y-6 text-sm" 
     x-data="{
         selectedBookingId: '{{ $selectedBooking ? $selectedBooking->id : '' }}',
         amount: '{{ $selectedBooking ? $selectedBooking->balance_amount : '' }}'
     }">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Record Manual Payment</h1>
        </div>
    </div>

    <!-- Create Payment Record Form -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-8 space-y-6">
        <form action="{{ route('admin.payments.store') }}" method="POST" class="space-y-4">
            @csrf

            <!-- Target Booking -->
            <div>
                <label for="booking_id" class="block font-bold text-[#1D1D1F] mb-2">
                    Select Dive Reservation <span class="text-[#780000]">*</span>
                </label>
                <select name="booking_id" id="booking_id" x-model="selectedBookingId" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white font-medium">
                    <option value="">-- Choose Reservation --</option>
                    @foreach($eligibleBookings as $b)
                        <option value="{{ $b->id }}">
                            #{{ $b->booking_number }} - {{ $b->contact_name }} ({{ $b->formatted_class_type }}, Balance: ₱{{ number_format($b->balance_amount, 2) }})
                        </option>
                    @endforeach
                </select>
                <span class="text-sm text-[#6E6E73] mt-1 block">
                    Listing confirmed bookings with remaining balances.
                </span>
            </div>

            <!-- Amount & Stage -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                <div>
                    <label for="amount" class="block font-bold text-[#1D1D1F] mb-2">
                        Amount Received (PHP) <span class="text-[#780000]">*</span>
                    </label>
                    <input type="number" 
                           name="amount" 
                           id="amount" 
                           x-model="amount" 
                           step="0.01" 
                           min="1" 
                           required 
                           placeholder="e.g. 3000.00"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label for="payment_type" class="block font-bold text-[#1D1D1F] mb-2">
                        Payment Stage <span class="text-[#780000]">*</span>
                    </label>
                    <select name="payment_type" id="payment_type" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="balance_settlement" selected>Balance Settlement</option>
                        <option value="downpayment">Initial Downpayment</option>
                        <option value="full">Full Course Payment</option>
                    </select>
                </div>
            </div>

            <!-- Payment Method & Reference -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                <div>
                    <label for="payment_method" class="block font-bold text-[#1D1D1F] mb-2">
                        Payment Channel / Mode <span class="text-[#780000]">*</span>
                    </label>
                    <select name="payment_method" id="payment_method" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="cash" selected>Cash (Direct at Base)</option>
                        <option value="gcash">GCash (Direct Transfer)</option>
                        <option value="bpi_bank_transfer">BPI Bank Transfer</option>
                        <option value="maya">Maya</option>
                        <option value="other">Other Channel / Cheque</option>
                    </select>
                </div>

                <div>
                    <label for="transaction_id" class="block font-bold text-[#1D1D1F] mb-2">
                        Reference Number (Optional)
                    </label>
                    <input type="text" 
                           name="transaction_id" 
                           id="transaction_id" 
                           placeholder="e.g. CASH-RECEIPT-#101"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>
            </div>

            <!-- Staff Notes -->
            <div class="pt-1">
                <label for="notes" class="block font-bold text-[#1D1D1F] mb-2">
                    Staff Notes / Explanation
                </label>
                <textarea name="notes" id="notes" rows="2" placeholder="e.g. Remaining balance collected during gear fitting" class="w-full px-3.5 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white"></textarea>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.payments.index') }}" class="btn-secondary px-5 py-2.5 text-sm">Cancel</a>
                <button type="submit" class="btn-primary px-7 py-2.5 text-sm font-bold shadow-md">
                    Record Payment & Update Balance
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
