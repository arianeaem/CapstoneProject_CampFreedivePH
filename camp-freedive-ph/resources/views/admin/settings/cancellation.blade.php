@extends('layouts.admin')

@section('title', 'Cancellation Policies | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('owner.settings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Settings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Cancellation Policies</span>
@endsection

@section('content')
<div class="space-y-6 text-sm pb-16">
    
    <!-- Top Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Cancellation Policies</h1>
        <p class="text-[#6E6E73] text-sm mt-1">
            Configure lead-time day thresholds that govern customer refund eligibility and self-service rescheduling.
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

    <form method="POST" action="{{ route('owner.settings.cancellation.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Full Refund Threshold -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Full Refund Window</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Cancellations submitted <strong>more than N days</strong> before batch departure are eligible for 100% deposit refund.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="full_refund_threshold_days" class="block font-bold text-xs text-[#1D1D1F]">
                        Lead Time (Days before departure)
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="number" 
                               id="full_refund_threshold_days" 
                               name="full_refund_threshold_days" 
                               value="{{ old('full_refund_threshold_days', $settings['full_refund_threshold_days']) }}" 
                               min="1" 
                               max="90" 
                               required
                               class="w-full min-h-[44px] px-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                        <span class="text-xs text-[#6E6E73] font-medium shrink-0">days</span>
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Standard policy is 14 days.</p>
                </div>
            </div>

            <!-- Reschedule-Only Threshold -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Reschedule-Only Window</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Requests made between the Full Refund window and <strong>N days</strong> allow free reschedule (deposit non-refundable).
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="reschedule_only_threshold_days" class="block font-bold text-xs text-[#1D1D1F]">
                        Lead Time (Days before departure)
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="number" 
                               id="reschedule_only_threshold_days" 
                               name="reschedule_only_threshold_days" 
                               value="{{ old('reschedule_only_threshold_days', $settings['reschedule_only_threshold_days']) }}" 
                               min="0" 
                               max="90" 
                               required
                               class="w-full min-h-[44px] px-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                        <span class="text-xs text-[#6E6E73] font-medium shrink-0">days</span>
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Standard policy is 7 days.</p>
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
