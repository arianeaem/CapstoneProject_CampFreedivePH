@extends('layouts.app')

@section('title', 'Manage Booking | Camp FreedivePH')
@section('meta_description', 'Retrieve and manage booking details using Booking Number and PIN.')

@section('content')
<div class="max-w-md mx-auto px-4 sm:px-6 py-12 sm:py-20 text-sm">
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-10">
        
        <!-- Lookup Header -->
        <div class="text-center mb-8">
            <h1 class="text-2xl font-extrabold text-[#1D1D1F]">Manage Booking</h1>
            <p class="text-sm text-[#6E6E73] mt-1.5">
                Enter the Booking Number and 4-digit PIN to view reservation details, reschedule, or request cancellation.
            </p>
        </div>

        <!-- Booking Lookup Form -->
        <form action="{{ route('manage.search') }}" method="POST" class="space-y-5" novalidate x-data="{ submitting: false }" @submit="submitting = true">
            @csrf

            <div>
                <label for="booking_number" class="block font-bold text-[#1D1D1F] mb-2.5">
                    Booking Number <span class="text-[#D70015]">*</span>
                </label>
                <input type="text" 
                       name="booking_number" 
                       id="booking_number" 
                       value="{{ old('booking_number', $prefilledNumber) }}" 
                       placeholder="e.g. CFP-2026-8942" 
                       autocapitalize="characters"
                       autocorrect="off"
                       spellcheck="false"
                       autocomplete="off"
                       aria-invalid="{{ $errors->has('booking_number') ? 'true' : 'false' }}"
                       @if($errors->has('booking_number')) aria-describedby="err-booking-number" @endif
                       required
                        class="w-full px-4 py-3 rounded-xl border text-sm font-mono uppercase tracking-wider text-[#1D1D1F] bg-white transition-colors {{ $errors->has('booking_number') ? 'border-[#D70015] bg-red-50/10 focus:border-[#D70015]' : 'border-[#D1D1D6] focus:border-[#780000]' }}">
                @error('booking_number')
                <span id="err-booking-number" class="text-xs text-[#D70015] font-semibold mt-1.5 block">
                    {{ $message }}
                </span>
                @enderror
            </div>

            <div x-data="{ showPin: false }">
                <label for="pin" class="block font-bold text-[#1D1D1F] mb-2.5">
                    4-Digit PIN <span class="text-[#D70015]">*</span>
                </label>
                <div class="relative">
                    <input :type="showPin ? 'text' : 'password'" 
                           name="pin" 
                           id="pin" 
                           value="{{ old('pin', $prefilledPin) }}" 
                           placeholder="••••" 
                           maxlength="8"
                           inputmode="numeric"
                           pattern="[0-9]*"
                           autocomplete="one-time-code"
                           autocapitalize="none"
                           autocorrect="off"
                           spellcheck="false"
                           aria-invalid="{{ $errors->has('pin') ? 'true' : 'false' }}"
                           aria-describedby="{{ $errors->has('pin') ? 'err-pin' : 'pin-helper' }}"
                           required
                           class="w-full pl-4 pr-12 py-3 rounded-xl border text-sm font-mono tracking-widest text-[#1D1D1F] bg-white transition-colors {{ $errors->has('pin') ? 'border-[#D70015] bg-red-50/10 focus:border-[#D70015]' : 'border-[#D1D1D6] focus:border-[#780000]' }}">
                    <button type="button" 
                            @click="showPin = !showPin" 
                            :aria-label="showPin ? 'Hide PIN' : 'Show PIN'"
                            :aria-pressed="showPin"
                            class="absolute right-1 top-1/2 -translate-y-1/2 w-11 h-11 flex items-center justify-center text-[#636366] hover:text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] rounded-lg transition-colors cursor-pointer">
                        <svg x-show="!showPin" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg x-show="showPin" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                @error('pin')
                <span id="err-pin" class="text-xs text-[#D70015] font-semibold mt-1.5 block">
                    {{ $message }}
                </span>
                @enderror
                <span id="pin-helper" class="text-sm text-[#6E6E73] mt-1.5 block">
                    The 4-digit PIN was sent to the booking email.
                </span>
            </div>

            <button type="submit" 
                    :disabled="submitting"
                    class="btn-primary w-full py-3.5 min-h-[44px] text-sm font-bold mt-2 cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 flex items-center justify-center gap-2 disabled:opacity-75 disabled:cursor-not-allowed">
                <svg x-show="submitting" x-cloak class="animate-spin w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke-opacity="1"></path></svg>
                <span x-text="submitting ? 'Finding Booking…' : 'Find Booking'">Find Booking</span>
            </button>
        </form>

        <!-- Support Contact Information -->
        <div class="mt-8 pt-6 border-t border-[#E5E5EA] text-center text-sm text-[#6E6E73] space-y-1.5">
            <p class="font-semibold text-[#1D1D1F]">Need help with your reservation?</p>
            <p class="leading-relaxed">
                You may contact us on 
                <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" aria-label="Camp FreedivePH on Facebook (opens in a new tab)" class="text-[#780000] font-bold underline hover:text-[#5E0000] inline-flex items-center gap-1">
                    <span>Facebook</span>
                    <img src="{{ asset('icons/icons8-linking-60.png') }}" class="w-4 h-4 object-contain" alt="" aria-hidden="true">
                </a>, 
                <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" aria-label="Camp FreedivePH on Instagram (opens in a new tab)" class="text-[#780000] font-bold underline hover:text-[#5E0000] inline-flex items-center gap-1">
                    <span>Instagram</span>
                    <img src="{{ asset('icons/icons8-linking-60.png') }}" class="w-4 h-4 object-contain" alt="" aria-hidden="true">
                </a>, 
                email <a href="mailto:campfreediveph@gmail.com" aria-label="Email Camp FreedivePH" class="text-[#780000] font-bold underline hover:text-[#5E0000]">campfreediveph@gmail.com</a>, 
                or call <a href="tel:+639278879894" aria-label="Call Camp FreedivePH" class="text-[#780000] font-bold whitespace-nowrap hover:underline">+63 927 887 9894</a>.
            </p>
        </div>
    </div>
</div>
@endsection
