@extends('layouts.app')

@section('title', '404 - Page Not Found | Camp FreedivePH')

@section('content')
<div class="min-h-[60vh] flex items-center justify-center px-4 py-16">
    <div class="max-w-lg w-full text-center space-y-6">
        
        <!-- 404 Hero Code -->
        <div class="space-y-2">
            <span class="text-xs sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">
                Resource Not Found
            </span>
            <h1 class="font-heading font-black text-6xl sm:text-7xl text-[#1D1D1F] tracking-tight">
                404
            </h1>
        </div>

        <!-- Descriptive Text -->
        <div class="space-y-2">
            <p class="text-sm sm:text-base text-[#6E6E73] leading-relaxed max-w-md mx-auto">
                The page or reservation you are looking for might have moved, been rescheduled, or is no longer accessible.
            </p>
        </div>

        <!-- Helpful Action Buttons -->
        <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="{{ route('landing') }}" class="btn-primary w-full sm:w-auto px-6 py-2.5 text-sm font-bold text-center">
                Return to Home
            </a>
            <a href="{{ route('manage.index') }}" class="btn-secondary w-full sm:w-auto px-6 py-2.5 text-sm font-semibold text-center">
                Manage My Booking
            </a>
        </div>

        <!-- Support Note -->
        <div class="pt-6 border-t border-[#E5E5EA] text-xs text-[#8E8E93] leading-relaxed">
            Need help finding your reservation? You may contact us on 
            <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-medium underline">Facebook</a>, 
            <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-medium underline">Instagram</a>, 
            email <a href="mailto:campfreediveph@gmail.com" class="text-[#780000] font-medium underline">campfreediveph@gmail.com</a>, 
            or call <a href="tel:+639278879894" class="text-[#780000] font-medium whitespace-nowrap underline">+63 927 887 9894</a>.
        </div>

    </div>
</div>
@endsection
