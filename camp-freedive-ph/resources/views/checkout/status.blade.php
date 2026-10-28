@extends('layouts.app')

@section('title', 'Checkout Status | Camp FreedivePH')
@section('meta_description', 'Status of your Camp FreedivePH checkout.')

@php
    // Only safe fields reach this view (no PIN, contact details or payment ids)
    $content = match ($state) {
        'finalized' => ['tone' => 'success', 'title' => 'Booking confirmed', 'lead' => 'Your payment was verified and your slot is confirmed.'],
        'paid_unallocated' => ['tone' => 'warning', 'title' => 'Payment received — booking not yet confirmed', 'lead' => "We've received your payment, but we couldn't confirm your reservation for the selected dates. Our team will review your booking and contact you about the available next steps. Please keep your payment reference for your records. You do not need to pay again."],
        'payment_review' => ['tone' => 'warning', 'title' => 'Payment under review', 'lead' => 'We could not automatically match your payment to this checkout. Our team will review it and contact you. Your booking is not confirmed yet.'],
        'not_paid' => ['tone' => 'neutral', 'title' => 'Payment not yet confirmed', 'lead' => 'We have not received a confirmed payment for this checkout yet. If you just paid, it can take a moment for PayMongo to confirm it.'],
        'retryable' => ['tone' => 'neutral', 'title' => 'Checking your payment', 'lead' => 'We are still checking the status of your payment. Please do not pay again. Refresh this page in a few minutes.'],
        default => ['tone' => 'neutral', 'title' => 'Checkout not completed', 'lead' => 'The PayMongo payment page was closed before payment. Nothing was confirmed.'],
    };
    $toneClasses = [
        'success' => 'bg-[#E8F5EC] text-[#1E7B3A] border-[#BFE5CB]',
        'warning' => 'bg-[#FFF6E5] text-[#8A5A00] border-[#F5DDA8]',
        'neutral' => 'bg-[#F5F5F7] text-[#1D1D1F] border-[#E5E5EA]',
    ][$content['tone']];
@endphp

@section('content')
<div class="max-w-md mx-auto px-4 sm:px-6 py-12 sm:py-20 text-sm">
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-10">
        <div class="rounded-lg border px-4 py-3 mb-6 {{ $toneClasses }}">
            <h1 class="text-lg font-extrabold">{{ $content['title'] }}</h1>
            <p class="mt-1">{{ $content['lead'] }}</p>
        </div>

        @if ($summary)
            <dl class="space-y-2.5 mb-6">
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Booking number</dt><dd class="font-mono font-bold text-[#1D1D1F]">{{ $summary['booking_number'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Class</dt><dd class="font-bold text-[#1D1D1F]">{{ $summary['class'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Dates</dt><dd class="font-bold text-[#1D1D1F]">{{ $summary['dates'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Participants</dt><dd class="font-bold text-[#1D1D1F]">{{ $summary['pax'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Downpayment paid</dt><dd class="font-bold text-[#1D1D1F]">₱{{ number_format($summary['paid'], 2) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-[#6E6E73]">Balance due at camp</dt><dd class="font-bold text-[#1D1D1F]">₱{{ number_format($summary['balance'], 2) }}</dd></div>
            </dl>
            <p class="text-[#6E6E73] mb-6">Keep your booking number. You can view or manage your booking on the Manage Booking page with your booking number and PIN.</p>
            <a href="{{ route('manage.index') }}" class="block text-center w-full rounded-lg bg-[#1D1D1F] text-white font-bold py-3">Manage Booking</a>
        @else
            <div class="flex justify-between gap-4 mb-6"><span class="text-[#6E6E73]">Checkout reference</span><span class="font-mono font-bold text-[#1D1D1F]">{{ $reference }}</span></div>
            @if (in_array($state, ['paid_unallocated', 'payment_review', 'retryable'], true))
                <p class="text-[#6E6E73] mb-6">Please keep this checkout reference. If you need help, contact us and mention it. Do not make another payment for this checkout.</p>
            @elseif ($state === 'not_paid')
                <p class="text-[#6E6E73] mb-6">If you have not paid yet, you can start a new booking. If you already paid, please wait a few minutes and refresh this page before paying again.</p>
                <a href="{{ route('booking.create') }}" class="block text-center w-full rounded-lg border border-[#1D1D1F] text-[#1D1D1F] font-bold py-3">Back to booking</a>
            @else
                <p class="text-[#6E6E73] mb-6">You can start a new booking whenever you are ready.</p>
                <a href="{{ route('booking.create') }}" class="block text-center w-full rounded-lg border border-[#1D1D1F] text-[#1D1D1F] font-bold py-3">Back to booking</a>
            @endif
        @endif
    </div>
</div>
@endsection
