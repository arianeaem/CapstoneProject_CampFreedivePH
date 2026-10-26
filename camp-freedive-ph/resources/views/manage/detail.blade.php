@extends('layouts.app')

@section('title', 'Booking #' . $booking->booking_number . ' | Camp FreedivePH')
@section('meta_description', 'View and manage your Camp FreedivePH booking details, reschedule dates, or cancel.')

@section('content')
<div class="max-w-5xl mx-auto px-3 sm:px-6 lg:px-8 py-6 sm:py-12 text-sm"
     x-data="manageBooking({
         bookingNumber: '{{ $booking->booking_number }}',
         pin: '{{ $booking->pin }}',
         csrfToken: '{{ csrf_token() }}',
         checkWeatherUrl: '{{ route('api.weather.check') }}',
         currentStartDate: '{{ $booking->start_date->format('Y-m-d') }}',
         currentEndDate: '{{ $booking->end_date->format('Y-m-d') }}'
     })">

    <!-- Back Navigation -->
    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('manage.index') }}" class="min-h-[44px] -ml-2 px-2.5 py-2 rounded-xl text-sm font-semibold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] inline-flex items-center gap-1.5 transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>Switch to another booking</span>
        </a>
        <div class="text-sm sm:text-sm text-[#636366]">
            Booking Created: {{ $booking->created_at->format('M d, Y') }}
        </div>
    </div>

    @if($booking->status === 'pending_downpayment')
    <!-- Downpayment Required Alert -->
    <div class="mb-6 p-4 sm:p-5 rounded-xl bg-amber-100 text-amber-900 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="space-y-1">
            <div class="flex items-center gap-2 font-bold text-sm sm:text-base text-amber-900">
                <span>Downpayment Required</span>
            </div>
            <p class="text-sm text-[#78350F] leading-relaxed">
                Your reservation is unconfirmed because the required downpayment of <strong>₱{{ number_format($booking->downpayment_amount, 2) }}</strong> remains unpaid. Complete payment via PayMongo to secure your slots.
            </p>
        </div>
        <form action="{{ route('paymongo.checkout', ['booking' => $booking->id]) }}" method="POST" class="shrink-0">
            @csrf
            <button type="submit" class="btn-primary min-h-[44px] w-full sm:w-auto px-5 py-2.5 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2">
                Pay Downpayment
            </button>
        </form>
    </div>
    @endif

    <!-- Booking Overview -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 mb-6 sm:mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 mb-6">
            <div>
                <h1 class="text-xl sm:text-3xl font-extrabold text-[#1D1D1F] font-mono tracking-wide">
                    Reservation {{ $booking->booking_number }}
                </h1>
                <div class="mt-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 sm:px-3 sm:py-1 rounded-full text-sm font-bold {{ $booking->status_badge['bg'] }}">
                        {{ $booking->status_badge['label'] }}
                    </span>
                </div>
            </div>

        </div>

        <!-- Booking Key Information -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 text-sm">
            <div class="p-3.5 sm:p-4 rounded-xl ">
                <span class="text-sm text-[#6E6E73] block mb-1">Lead Booker:</span>
                <strong class="text-sm text-[#1D1D1F] block">{{ $booking->contact_name }}</strong>
                <span class="text-sm text-[#6E6E73] break-all">{{ $booking->contact_email }}</span>
            </div>

            <div class="p-3.5 sm:p-4 rounded-xl ">
                <span class="text-sm text-[#6E6E73] block mb-1">Class Package:</span>
                <strong class="text-sm text-[#1D1D1F] block">{{ $booking->formatted_class_type }}</strong>
                <span class="text-sm text-[#6E6E73]">{{ $booking->participants->count() }} Participant(s)</span>
            </div>

            <div class="p-3.5 sm:p-4 rounded-xl ">
                <span class="text-sm text-[#6E6E73] block mb-1">Trip Dates:</span>
                <strong class="text-sm text-[#1D1D1F] block">{{ $booking->formatted_date_range }}</strong>
                <span class="text-sm text-[#780000] font-semibold">
                    @if($policy['days_until_dive'] > 0)
                        {{ $policy['days_until_dive'] }} days until dive trip
                    @elseif($policy['days_until_dive'] === 0)
                        Dive trip is today
                    @else
                        Completed trip
                    @endif
                </span>
            </div>

            <div class="p-3.5 sm:p-4 rounded-xl ">
                <span class="text-sm text-[#6E6E73] block mb-1">Payment Status:</span>
                @if($booking->status === 'pending_downpayment')
                    <strong class="text-sm text-amber-700 block">₱{{ number_format($booking->downpayment_amount, 2) }} Downpayment Unpaid</strong>
                    <span class="text-sm text-[#D70015] font-bold">Unconfirmed Reservation</span>
                @else
                    <strong class="text-sm text-[#065F46] block">₱{{ number_format($booking->downpayment_amount, 2) }} Downpayment Paid</strong>
                    @if(str_starts_with($booking->status, 'cancelled') || $booking->status === 'no_show')
                        <span class="text-sm text-[#6E6E73] font-bold">No balance to pay (booking cancelled)</span>
                    @else
                        <span class="text-sm text-[#780000] font-bold">₱{{ number_format($booking->balance_amount, 2) }} balance due at camp (check-out)</span>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <!-- Booking Content and Actions -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 sm:gap-8">

        <!-- Booking Details and Logistics -->
        <div class="lg:col-span-2 space-y-6 sm:space-y-8">
            
            <!-- Dynamic Pricing and Rate Breakdown -->
            @if($booking->priceAdjustments && $booking->priceAdjustments->count() > 0)
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm sm:text-base font-bold text-[#1D1D1F]">Price Breakdown &amp; Applied Rules</h3>
                </div>

                <div class="space-y-2 text-xs sm:text-sm">
                    <div class="flex flex-wrap sm:flex-nowrap justify-between items-center gap-1 sm:gap-2 text-[#6E6E73]">
                        <span class="shrink-0">Base Class Rate:</span>
                        <span class="font-bold text-[#1D1D1F] whitespace-nowrap">₱{{ number_format($booking->priceAdjustments->first()->base_price ?? 4250, 2) }} <span class="font-normal text-[#636366]">/ person</span></span>
                    </div>

                    <div class="border-l-2 border-[#D1D1D6] pl-3 py-1 space-y-2 my-1.5">
                        @foreach($booking->priceAdjustments as $adj)
                        <div class="flex flex-wrap sm:flex-nowrap justify-between items-start sm:items-center gap-1 sm:gap-3">
                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                <span class="font-medium text-[#1D1D1F]">{{ $adj->rule_name }}</span>
                                <span class="text-[#6E6E73] text-xs">({{ $adj->condition_summary }})</span>
                            </div>
                            <span class="font-bold shrink-0 whitespace-nowrap {{ $adj->adjustment_amount >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                                {{ $adj->adjustment_amount >= 0 ? '+' : '−' }}₱{{ number_format(abs($adj->adjustment_amount), 2) }} <span class="font-normal text-xs text-[#636366]">/ person</span>
                            </span>
                        </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap sm:flex-nowrap justify-between items-center gap-1 sm:gap-2 font-extrabold text-[#1D1D1F] pt-1">
                        <span class="shrink-0">Final Adjusted Rate:</span>
                        <span class="text-sm sm:text-base font-extrabold text-[#780000] whitespace-nowrap">₱{{ number_format($booking->participants->first()->price_per_person ?? $booking->priceAdjustments->first()->adjusted_price, 2) }} <span class="font-normal text-xs text-[#636366]">/ person</span></span>
                    </div>
                </div>
            </div>
            @endif

            <!-- Participant List -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                    <h3 class="text-base font-bold text-[#1D1D1F]">Participants in this Booking ({{ $booking->participants->count() }})</h3>
                    <a href="{{ route('manage.book_again', $booking->booking_number) }}" data-native class="btn-secondary px-3.5 py-1.5 text-sm font-bold rounded-xl">Book again with the same people</a>
                </div>
                <div class="divide-y divide-[#E5E5EA]">
                    @foreach($booking->participants as $p)
                    <div class="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-sm">
                        <div>
                            <span class="font-bold text-[#1D1D1F]">{{ $p->name }}</span>
                            <span class="text-sm text-[#6E6E73] ml-1.5">Age {{ $p->age }}</span>
                            <div class="text-sm text-[#6E6E73] mt-0.5">
                                <strong>Medical Notes:</strong> {{ $p->health_condition ?: 'None declared' }}
                            </div>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="px-2.5 py-0.5 rounded text-sm font-semibold bg-[#F2F2F7] text-[#1D1D1F]">
                                {{ ucfirst(str_replace('_', ' ', $p->swimmer_status ?: 'Swimmer')) }}
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- Logistics and Add-ons -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 space-y-4">
                <h3 class="text-base font-bold text-[#1D1D1F]">Transportation & Add-ons</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div class="space-y-1">
                        <span class="text-sm text-[#6E6E73] block mb-1">Transportation:</span>
                        <strong class="text-sm text-[#1D1D1F] block">
                            {{ $booking->pickup_option === 'carpool' ? 'Manila Carpool Service' : 'Own Transportation' }}
                        </strong>
                        @if($booking->pickup_location)
                            <span class="text-sm text-[#780000] font-medium block mt-1">Pickup Hub: {{ $booking->pickup_location }}</span>
                        @endif
                    </div>

                    <div class="space-y-1">
                        <span class="text-sm text-[#6E6E73] block mb-1">Boat Dive:</span>
                        <strong class="text-sm text-[#1D1D1F] block">
                            {{ $booking->boat_dive ? 'Included (+₱600 / person)' : 'Not Included' }}
                        </strong>
                        <span class="text-sm text-[#6E6E73] block mt-1">Sanctuary exploration</span>
                    </div>

                    <div class="space-y-1">
                        <span class="text-sm text-[#6E6E73] block mb-1">Mabini LGU Pass &amp; Env. Fee:</span>
                        <strong class="text-sm text-[#1D1D1F] block">
                            ₱{{ number_format(($booking->lgu_fee ?? 0) + ($booking->environmental_fee ?? 0), 2) }}
                        </strong>
                        <span class="text-xs text-[#6E6E73] block mt-1">₱350 / head × {{ $booking->participants->count() }} pax</span>
                    </div>
                </div>

                @if($booking->pickup_option === 'carpool')
                <div class="banner banner-note">
                    <strong class="font-bold text-[#780000] block mb-1">Carpool Arrival & Grace Period Notice:</strong>
                    <p class="text-[#4A4A4F] leading-relaxed">
                        Please arrive at your pickup location (<strong>{{ $booking->pickup_location }}</strong>) before the designated departure time. A 30-minute grace period applies before the van departs; please arrive promptly to avoid delays for other participants.
                    </p>
                </div>
                @endif
            </div>

            <!-- Trip Cost & Payment Breakdown -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 space-y-4">
                <h3 class="text-base font-bold text-[#1D1D1F]">Trip Cost &amp; Payment Breakdown</h3>
                <div class="space-y-2.5 text-xs sm:text-sm">
                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <span>Course Package ({{ $booking->formatted_class_type }} × {{ $booking->participants->count() }} pax):</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->subtotal, 2) }}</strong>
                    </div>

                    @if($booking->carpool_fee > 0)
                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <span>Transportation (Manila Carpool Service):</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->carpool_fee, 2) }}</strong>
                    </div>
                    @endif

                    @if($booking->boat_dive_fee > 0)
                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <span>Sanctuary Boat Dive (+₱600 / person):</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->boat_dive_fee, 2) }}</strong>
                    </div>
                    @endif

                    <div class="flex justify-between items-center text-[#6E6E73]">
                        <div>
                            <span class="block">Mabini LGU Pass &amp; Env. Fee:</span>
                            <span class="text-xs text-[#8E8E93]">₱350 / head × {{ $booking->participants->count() }} pax (₱300 LGU Pass + ₱50 Env. Fee)</span>
                        </div>
                        <strong class="text-[#1D1D1F]">₱{{ number_format(($booking->lgu_fee ?? 0) + ($booking->environmental_fee ?? 0), 2) }}</strong>
                    </div>

                    <div class="pt-3 flex justify-between items-center text-sm sm:text-base">
                        <strong class="text-[#1D1D1F]">Total Trip Cost:</strong>
                        <strong class="text-base sm:text-lg font-black text-[#780000]">₱{{ number_format($booking->total_amount, 2) }}</strong>
                    </div>

                    <div class="pt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="p-3 rounded-xl bg-[#F0FDF4] border border-[#DCFCE7]">
                            <span class="text-xs text-[#166534] font-medium block">Downpayment {{ $booking->status === 'pending_downpayment' ? 'Required' : 'Paid' }}:</span>
                            <strong class="text-sm font-bold text-[#166534]">₱{{ number_format($booking->downpayment_amount, 2) }}</strong>
                        </div>
                        <div class="p-3 rounded-xl bg-[#FFFBEB] border border-[#FEF3C7]">
                            <span class="text-xs text-[#92400E] font-medium block">Balance Due at Camp (check-out):</span>
                            <strong class="text-sm font-bold text-[#780000]">{{ str_starts_with($booking->status, 'cancelled') || $booking->status === 'no_show' ? 'None (booking cancelled)' : '₱' . number_format($booking->balance_amount, 2) }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Things to Bring Checklist -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 space-y-3">
                <h3 class="text-base font-bold text-[#1D1D1F]">Things to Bring (Towels, shampoo and soap are all provided)</h3>
                <ul class="space-y-1.5 text-[#6E6E73] text-xs sm:text-sm list-disc list-inside">
                    <li>Swimming clothes (anything you’re comfortable wearing)</li>
                    <li>Toiletries</li>
                    <li>Personal things</li>
                    <li>A pair of socks (any style) for fin fitting</li>
                </ul>
            </div>

            <!-- Request History -->
            @if($booking->rescheduleRequests->isNotEmpty() || $booking->cancellationRequests->isNotEmpty())
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 space-y-4">
                <h3 class="text-base font-bold text-[#1D1D1F]">Request History</h3>

                @foreach($booking->rescheduleRequests as $req)
                <div class="p-4 rounded-xl bg-[#FFFBEB] text-sm text-[#92400E] space-y-1 shadow-2xs">
                    <div class="flex items-center justify-between font-bold">
                        <span>Reschedule Request ({{ $req->created_at->format('M d, Y') }})</span>
                        <span class="uppercase tracking-wider px-2 py-0.5 rounded bg-white text-sm font-bold">{{ $req->status }}</span>
                    </div>
                    <p>Requested Move: {{ $req->requested_start_date->year === $req->requested_end_date->year ? $req->requested_start_date->format('M d') . ' - ' . $req->requested_end_date->format('M d, Y') : $req->requested_start_date->format('M d, Y') . ' - ' . $req->requested_end_date->format('M d, Y') }}</p>
                    @if($req->reason)
                        <p class="text-sm text-[#78350F]">Reason: {{ $req->reason }}</p>
                    @endif
                </div>
                @endforeach

                @foreach($booking->cancellationRequests as $cReq)
                <div class="p-4 rounded-xl bg-[#FEF2F2] text-sm text-[#991B1B] space-y-1 shadow-2xs">
                    <div class="flex items-center justify-between font-bold">
                        <span>Cancellation Request ({{ $cReq->created_at->format('M d, Y') }})</span>
                        <span class="uppercase tracking-wider px-2 py-0.5 rounded bg-white text-sm font-bold">{{ $cReq->status }}</span>
                    </div>
                    <p>Refund quoted under the applicable terms: ₱{{ number_format($cReq->calculated_refund_amount, 2) }} (reviewed by our team; not yet sent)</p>
                    @if($cReq->reason)
                        <p class="text-sm">Reason: {{ $cReq->reason }}</p>
                    @endif
                </div>
                @endforeach
            </div>
            @endif

        </div>

        <!-- Policy Engine and Actions -->
        <div class="space-y-6 text-sm">

            <!-- Policy Status -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-8 space-y-5">
                <div>
                    <span class="text-sm font-bold uppercase tracking-wider text-[#780000]">Cancellation & Rescheduling Policy</span>
                    <p class="text-sm text-[#6E6E73] mt-1">Policy based on days before your dive date.</p>
                </div>

                @if($booking->status === 'pending_downpayment')
                <!-- Unpaid Downpayment Notice -->
                <div class="p-4 rounded-xl bg-amber-100 text-amber-900 space-y-3 shadow-2xs">
                    <div class="font-bold text-sm text-amber-900">
                        Downpayment Required
                    </div>
                    <p class="text-sm text-[#78350F] leading-relaxed">
                        Self-service rescheduling and cancellations are enabled once your required downpayment of <strong>₱{{ number_format($booking->downpayment_amount, 2) }}</strong> is paid.
                    </p>
                    <form action="{{ route('paymongo.checkout', ['booking' => $booking->id]) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn-primary min-h-[44px] w-full py-2.5 px-4 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2">
                            Pay ₱{{ number_format($booking->downpayment_amount, 2) }}
                        </button>
                    </form>
                </div>
                @elseif($paymentUnderReview ?? false)
                <!-- A payment under review (Batch 3-II B1): no online changes; the team contacts the guest -->
                <div class="p-4 rounded-xl space-y-2 bg-[#FFFBEB] border border-amber-200">
                    <span class="font-bold text-[#1D1D1F]">Payment under review</span>
                    <p class="text-sm text-[#6E6E73] leading-relaxed">{{ \App\Http\Controllers\ManageBookingController::PAYMENT_UNDER_REVIEW_MESSAGE }}</p>
                </div>
                @else
                <!-- Reschedule Status -->
                <div class="p-4 rounded-xl space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-[#1D1D1F]">Reschedule</span>
                        <span class="text-sm font-bold px-2 py-0.5 rounded {{ $policy['reschedule_allowed'] ? 'bg-[#ECFDF5] text-emerald-800' : 'bg-[#E5E5EA] text-[#6E6E73]' }}">
                            {{ $policy['reschedule_allowed'] ? 'Allowed' : 'Closed' }}
                        </span>
                    </div>
                    <p class="text-sm text-[#6E6E73] leading-relaxed">{{ $policy['reschedule_message'] }}</p>
                    
                    @if($policy['reschedule_allowed'])
                        <button type="button" 
                                @click="openRescheduleModal = true" 
                                class="btn-secondary min-h-[44px] w-full py-2.5 px-4 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 mt-1">
                            Reschedule Booking Date
                        </button>
                    @endif
                </div>

                <!-- Cancellation Status -->
                <div class="p-4 rounded-xl space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-[#1D1D1F]">Cancel / Refund</span>
                        <span class="text-sm font-bold px-2 py-0.5 rounded {{ $policy['cancel_allowed'] ? 'bg-[#FEF3C7] text-amber-900' : 'bg-[#E5E5EA] text-[#6E6E73]' }}">
                            {{ $policy['cancel_allowed'] ? 'Eligible' : 'Closed' }}
                        </span>
                    </div>
                    <p class="text-sm text-[#6E6E73] leading-relaxed">{{ $policy['cancel_message'] }}</p>

                    @if($policy['cancel_allowed'])
                        <button type="button" 
                                @click="openCancelModal = true" 
                                class="btn-secondary min-h-[44px] w-full py-2.5 px-4 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 mt-1">
                            Request Cancellation
                        </button>
                    @endif
                </div>
                @endif

                <!-- Marine Safety Advisory Notice -->
                @if($policy['is_force_majeure'])
                <div class="banner banner-error space-y-1.5">
                    <div class="font-bold text-sm text-sky-950 flex items-center gap-1.5">
                        <span>Advisory: Weather Force Majeure Active</span>
                    </div>
                    <p class="text-sm text-sky-900 leading-relaxed">
                        PAGASA gale warnings or severe sea surges have triggered camp-wide safety protocols. All rescheduling restrictions are waived. You may also request a refund of your downpayment; our team confirms the weather situation before any refund.
                    </p>
                </div>
                @endif
            </div>

            <!-- Coordinator Contact -->
            <div class="p-5 sm:p-6 rounded-xl bg-white text-sm text-[#6E6E73] space-y-3 shadow-2xs border border-[#E5E5EA]">
                <h4 class="font-bold text-[#1D1D1F] text-sm sm:text-base">Need Special Assistance?</h4>
                <p class="text-xs sm:text-sm text-[#6E6E73]">You may contact us through any of our support channels:</p>
                <div class="space-y-2.5 text-xs sm:text-sm text-[#1D1D1F] pt-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[#6E6E73] font-medium shrink-0">Facebook:</span>
                        <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" aria-label="Facebook (opens in a new tab)" class="text-[#780000] font-bold hover:underline inline-flex items-center gap-1.5">
                            <span>@Campfreediveph</span>
                            <img src="{{ asset('icons/icons8-linking-60.png') }}" class="w-4.5 h-4.5 object-contain shrink-0" alt="" aria-hidden="true">
                        </a>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[#6E6E73] font-medium shrink-0">Instagram:</span>
                        <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" aria-label="Instagram (opens in a new tab)" class="text-[#780000] font-bold hover:underline inline-flex items-center gap-1.5">
                            <span>@campfreediveph</span>
                            <img src="{{ asset('icons/icons8-linking-60.png') }}" class="w-4.5 h-4.5 object-contain shrink-0" alt="" aria-hidden="true">
                        </a>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[#6E6E73] font-medium shrink-0">Email:</span>
                        <a href="mailto:campfreediveph@gmail.com" aria-label="Email Camp FreedivePH" class="text-[#780000] font-bold hover:underline">campfreediveph@gmail.com</a>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[#6E6E73] font-medium shrink-0">Phone:</span>
                        <a href="tel:+639278879894" aria-label="Call Camp FreedivePH" class="text-[#780000] font-bold hover:underline whitespace-nowrap">+63 927 887 9894</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Reschedule Panel -->
    <x-side-panel show="openRescheduleModal" size="lg"
                  :eyebrow="'Booking ' . $booking->booking_number"
                  title="Reschedule your dive"
                  description="Pick a new 2D1N date. We check the sea conditions for you.">
        <form id="reschedule-form" action="{{ route('manage.reschedule', $booking->booking_number) }}" method="POST" @submit="submittingReschedule = true" class="space-y-5">
            @csrf
            <input type="hidden" name="pin" value="{{ $booking->pin }}">

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="reschedule-start-date" class="form-label form-label-required">New start date</label>
                    <x-date-picker id="reschedule-start-date" name="requested_start_date" model="rescheduleStartDate" x-on:change="onRescheduleDateChange()" :min="date('Y-m-d', strtotime('+3 days'))" required />
                </div>
                <div>
                    <label for="reschedule-end-date" class="form-label text-[#6E6E73]">New end date (set for you)</label>
                    <x-date-picker id="reschedule-end-date" name="requested_end_date" model="rescheduleEndDate" readonly required />
                </div>
            </div>

            <!-- Weather Loading State -->
            <div x-show="weatherLoading" x-cloak role="status" aria-live="polite" class="flex items-center gap-2 rounded-xl bg-[#F2F2F7] p-3.5 text-[#1D1D1F]">
                <svg class="h-4 w-4 shrink-0 animate-spin text-[#00C3D0]" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                <span class="font-medium">Checking sea safety and weather for these dates...</span>
            </div>

            <!-- Weather check (uses components/dive-safety/live-evaluation) -->
            <template x-if="rescheduleForecast && rescheduleForecast.day1 && !weatherLoading">
                <x-dive-safety.live-evaluation forecast="rescheduleForecast" />
            </template>

            <div>
                <label for="reschedule-reason" class="form-label">Reason (optional)</label>
                <textarea id="reschedule-reason" name="reason" rows="2" placeholder="e.g. Work schedule change" class="form-textarea"></textarea>
            </div>

            <div class="banner banner-info">
                Your request goes to the camp for approval. We will email you once a coach is confirmed for the new date.
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openRescheduleModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="reschedule-form"
                    :disabled="weatherLoading || (rescheduleForecast && !rescheduleForecast.is_bookable) || submittingReschedule"
                    class="btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                <span x-show="!submittingReschedule">Send request</span>
                <span x-show="submittingReschedule" x-cloak>Sending...</span>
            </button>
        </x-slot:footer>
    </x-side-panel>

    <!-- Cancellation Panel -->
    <x-side-panel show="openCancelModal" role="alertdialog"
                  :eyebrow="'Booking ' . $booking->booking_number"
                  title="Cancel your booking"
                  description="Check your refund before you confirm.">
        <div class="panel-context space-y-2">
            <div class="flex justify-between gap-4">
                <span class="text-[#6E6E73]">Downpayment paid</span>
                <span class="font-bold">₱{{ number_format($booking->downpayment_amount, 2) }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-[#6E6E73]">Days before your dive</span>
                <span class="font-bold">{{ $policy['days_until_dive'] }} days</span>
            </div>
            <div class="flex justify-between gap-4 border-t border-[#D1D1D6] pt-2">
                <span class="font-bold">{{ ($policy['refund_requires_review'] ?? false) ? 'Refund (reviewed by our team)' : 'Refund' }}</span>
                <span class="font-extrabold text-[#780000]">{{ $policy['calculated_refund'] > 0 ? '₱' . number_format($policy['calculated_refund'], 2) : 'No refund' }}</span>
            </div>
            <p class="text-[#6E6E73]">{{ $policy['cancel_message'] }}</p>
        </div>

        <form id="cancel-booking-form" action="{{ route('manage.cancel', $booking->booking_number) }}" method="POST" @submit="submittingCancel = true" class="space-y-5">
            @csrf
            <input type="hidden" name="pin" value="{{ $booking->pin }}">

            <div>
                <label for="cancel-reason" class="form-label">Reason (optional)</label>
                <textarea id="cancel-reason" name="reason" rows="2" placeholder="Let us know why you need to cancel" class="form-textarea"></textarea>
            </div>

            <label class="flex cursor-pointer select-none items-start gap-3">
                <input type="checkbox"
                       x-ref="cancelCheck"
                       name="confirm_cancel_ack"
                       required
                       class="mt-0.5 h-4.5 w-4.5 shrink-0 cursor-pointer rounded text-[#780000] focus:ring-[#780000]">
                <span class="leading-relaxed">
                    I want to cancel this booking. I understand the camp reviews the refund amount. <span class="text-[#D70015]">*</span>
                </span>
            </label>
        </form>

        <x-slot:footer>
            <button type="button" @click="openCancelModal = false" class="btn-secondary">Keep booking</button>
            <button type="submit" form="cancel-booking-form" :disabled="submittingCancel" class="btn-danger disabled:cursor-not-allowed disabled:opacity-50">
                <span x-show="!submittingCancel">Cancel booking</span>
                <span x-show="submittingCancel" x-cloak>Cancelling...</span>
            </button>
        </x-slot:footer>
    </x-side-panel>

</div>
@endsection

@push('scripts')
<script>
function manageBooking(config) {
    return {
        openRescheduleModal: false,
        openCancelModal: false,
        submittingReschedule: false,
        submittingCancel: false,
        rescheduleStartDate: '',
        rescheduleEndDate: '',
        rescheduleForecast: null,
        weatherLoading: false,
        copiedPin: false,

        onRescheduleDateChange() {
            if (!this.rescheduleStartDate) return;
            const start = new Date(this.rescheduleStartDate);
            const end = new Date(start);
            end.setDate(start.getDate() + 1);
            this.rescheduleEndDate = end.toISOString().split('T')[0];

            this.weatherLoading = true;
            this.rescheduleForecast = null;

            fetch(config.checkWeatherUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken
                },
                body: JSON.stringify({
                    start_date: this.rescheduleStartDate,
                    end_date: this.rescheduleEndDate
                })
            })
            .then(res => res.json())
            .then(data => {
                this.rescheduleForecast = data;
            })
            .catch(err => {
                console.error('Weather check failed:', err);
            })
            .finally(() => {
                this.weatherLoading = false;
            });
        }
    };
}
</script>
@endpush
