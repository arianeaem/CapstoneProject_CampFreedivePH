@extends('layouts.admin')

@section('title', 'Transaction #' . $payment->transaction_id . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F] font-mono">{{ $payment->transaction_id }}</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ openBalanceModal: false }">
    
    <!-- Header and Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-[#1D1D1F]">Payment Transaction Details</h1>
        </div>

        <div class="flex items-center gap-3">
            @if($payment->booking->balance_amount > 0)
                <button type="button" 
                        @click="openBalanceModal = true"
                        class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-1.5">
                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                    <span>Record Balance Settlement</span>
                </button>
            @endif

            <a href="{{ route('admin.bookings.show', $payment->booking) }}" class="btn-secondary px-4 py-2 text-sm sm:text-sm font-semibold flex items-center gap-1.5">
                <span>View Booking #{{ $payment->booking->booking_number }}</span>
            </a>
        </div>
    </div>

    <!-- Payment Overview Banner -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="space-y-2">
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-full text-sm font-bold {{ $payment->status_badge['class'] }}">
                    {{ $payment->status_badge['label'] }}
                </span>
                <span class="text-sm px-2 py-0.5 rounded font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                    {{ $payment->payment_stage_label }}
                </span>
            </div>
            <h2 class="text-xl font-bold text-[#1D1D1F]">{{ $payment->formatted_payment_method }} Transaction</h2>
            <div class="text-sm text-[#6E6E73]">
                Paid at: <strong class="text-[#1D1D1F]">{{ $payment->paid_at ? $payment->paid_at->format('F d, Y h:i:s A') : 'Pending / Not completed' }}</strong>
            </div>
        </div>

        <div class="p-4 rounded-xl text-right space-y-1 shrink-0">
            <span class="text-sm text-[#6E6E73] block">Gross Payment Received</span>
            <div class="text-2xl font-extrabold text-[#780000]">₱{{ number_format($payment->amount, 2) }}</div>
            @if($payment->amount_refunded > 0)
                <span class="text-sm text-[#3B82F6] font-bold block">Refunded: ₱{{ number_format($payment->amount_refunded, 2) }}</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Transaction and Gateway Details -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Gateway and Reference Identifiers -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-4">
                <h3 class="text-base font-bold text-[#1D1D1F] border-b border-[#E5E5EA] pb-3">Gateway & Reference Identifiers</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm sm:text-sm">
                    <div>
                        <span class="text-sm text-[#6E6E73] block">System Transaction ID</span>
                        <strong class="font-mono text-[#1D1D1F]">{{ $payment->transaction_id }}</strong>
                    </div>

                    <div>
                        <span class="text-sm text-[#6E6E73] block">PayMongo Payment ID</span>
                        <span class="font-mono text-[#780000] font-bold">
                            {{ $payment->paymongo_payment_id ?: 'N/A (Offline Entry)' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-sm text-[#6E6E73] block">PayMongo Resource ID</span>
                        <span class="font-mono text-[#6E6E73]">
                            {{ $payment->paymongo_resource_id ?: 'N/A' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-sm text-[#6E6E73] block">PayMongo Refund ID</span>
                        <span class="font-mono text-[#3B82F6] font-bold">
                            {{ $payment->paymongo_refund_id ?: 'None' }}
                        </span>
                    </div>
                </div>

                @if($payment->createdBy)
                    <div class="p-3 bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] text-sm text-[#6E6E73]">
                        <strong>Manual Entry:</strong> Recorded offline by staff user <strong>{{ $payment->createdBy->name }}</strong> ({{ $payment->createdBy->email }}).
                    </div>
                @endif
            </div>

        </div>

        <!-- Linked Booking Summary -->
        <div class="space-y-6">
            
            <!-- Linked Booking Details -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-[#1D1D1F]">Linked Reservation</h3>
                    <span class="font-mono text-sm font-bold text-[#780000]">{{ $payment->booking->booking_number }}</span>
                </div>

                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-[#6E6E73] block">Class Package:</span>
                        <strong class="text-[#1D1D1F] text-sm">{{ $payment->booking->formatted_class_type }}</strong>
                    </div>

                    <div>
                        <span class="text-[#6E6E73] block">Dive Dates:</span>
                        <span class="text-[#1D1D1F] font-medium">{{ $payment->booking->formatted_date_range }}</span>
                    </div>

                    <div>
                        <span class="text-[#6E6E73] block">Contact:</span>
                        <span class="text-[#1D1D1F] font-medium">{{ $payment->booking->contact_name }} ({{ $payment->booking->contact_phone }})</span>
                    </div>

                    <div>
                        <span class="text-[#6E6E73] block">Participants:</span>
                        <span class="text-[#1D1D1F] font-medium">{{ $payment->booking->participants->count() }} pax</span>
                    </div>

                    <div class="pt-3 space-y-1.5">
                        <div class="flex justify-between">
                            <span class="text-[#6E6E73]">Total Reservation Fee:</span>
                            <strong class="text-[#1D1D1F]">₱{{ number_format($payment->booking->total_amount, 2) }}</strong>
                        </div>
                        <div class="flex justify-between text-[#34C759]">
                            <span>Required Downpayment:</span>
                            <strong>₱{{ number_format($payment->booking->downpayment_amount, 2) }}</strong>
                        </div>
                        <div class="flex justify-between text-sm font-bold text-[#780000]">
                            <span>Remaining Balance:</span>
                            <span>₱{{ number_format($payment->booking->balance_amount, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- Payment history -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
        <h3 class="text-base font-bold text-[#1D1D1F] border-b border-[#E5E5EA] pb-3">Transaction Lifecycle & Audit Trail</h3>

        <div class="divide-y divide-[#E5E5EA]">
            @forelse($payment->statusLogs as $log)
            <div class="py-3 text-sm space-y-1">
                <div class="flex flex-col sm:flex-row sm:items-baseline justify-between gap-1">
                    <span class="font-bold text-[#1D1D1F]">{{ ucfirst(str_replace('_', ' ', $log->old_status)) }} → {{ ucfirst(str_replace('_', ' ', $log->new_status)) }}</span>
                    <span class="text-xs sm:text-sm text-[#8E8E93] shrink-0">{{ $log->created_at->format('M d, Y h:i A') }}</span>
                </div>
                <p class="text-[#6E6E73] break-words">{{ $log->note }}</p>
                <span class="text-xs sm:text-sm text-[#8E8E93] block">Acting user: <strong class="text-[#1D1D1F]">{{ $log->user ? $log->user->name : 'System / PayMongo Webhook' }}</strong></span>
            </div>
            @empty
            <p class="text-sm text-[#6E6E73] py-2">No lifecycle changes recorded.</p>
            @endforelse
        </div>
    </div>

    <!-- Balance Settlement Modal -->
    <div x-show="openBalanceModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openBalanceModal = false">
            <h3 class="text-lg font-bold text-[#1D1D1F]">Record Balance Settlement</h3>
            <p class="text-sm text-[#6E6E73]">
                Collect remaining balance of <strong class="text-[#780000]">₱{{ number_format($payment->booking->balance_amount, 2) }}</strong> for Booking #{{ $payment->booking->booking_number }}.
            </p>

            <form action="{{ route('admin.payments.settle_balance', $payment) }}" method="POST" class="space-y-3 text-sm">
                @csrf

                <div>
                    <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Amount to Settle (PHP) <span class="text-[#780000]">*</span></label>
                    <input type="number" name="amount" value="{{ $payment->booking->balance_amount }}" step="0.01" min="1" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Payment Method <span class="text-[#780000]">*</span></label>
                    <select name="payment_method" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="cash" selected>Cash at Camp</option>
                        <option value="gcash">GCash</option>
                        <option value="bpi_bank_transfer">BPI Bank Transfer</option>
                        <option value="maya">Maya</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] text-sm mb-2">Receipt / Reference (Optional)</label>
                    <input type="text" name="transaction_id" placeholder="e.g. BAL-CASH-101" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div class="flex items-center justify-end gap-3 pt-3">
                    <button type="button" @click="openBalanceModal = false" class="btn-secondary px-4 py-2 text-sm">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2 text-sm font-bold">Save Settlement</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
