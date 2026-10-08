@extends('layouts.admin')

@section('title', 'Deposit Rules | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('owner.settings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Settings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Deposit Rules</span>
@endsection

@section('content')
<div class="space-y-6 text-sm pb-16">
    
    <!-- Top Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Deposit Rules</h1>
        <p class="text-[#6E6E73] text-sm mt-1">
            Configure required upfront downpayment rates and amounts per participant for online participant bookings.
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

    <form method="POST" action="{{ route('owner.settings.deposits.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Carpool Downpayment Deposit -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Carpool Joiners Downpayment</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Required deposit per participant when participant chooses camp-arranged van transportation from Metro Manila.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="downpayment_carpool" class="block font-bold text-xs text-[#1D1D1F]">
                        Deposit Amount per Participant (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="100" 
                               id="downpayment_carpool" 
                               name="downpayment_carpool" 
                               value="{{ old('downpayment_carpool', $settings['downpayment_carpool']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Standard rate is ₱3,000 to cover van fleet seat reservations.</p>
                </div>
            </div>

            <!-- Own Transportation Downpayment Deposit -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Own Transportation Downpayment</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Required deposit per participant when participant brings their own vehicle or commutes directly to camp resort.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="downpayment_own_transpo" class="block font-bold text-xs text-[#1D1D1F]">
                        Deposit Amount per Participant (₱)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-[#6E6E73]">₱</span>
                        <input type="number" 
                               step="100" 
                               id="downpayment_own_transpo" 
                               name="downpayment_own_transpo" 
                               value="{{ old('downpayment_own_transpo', $settings['downpayment_own_transpo']) }}" 
                               min="0" 
                               required
                               class="w-full min-h-[44px] pl-8 pr-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Standard rate is ₱2,000 to secure resort accommodation slots.</p>
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
