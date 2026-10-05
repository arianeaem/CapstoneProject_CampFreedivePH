@extends('layouts.app')

@section('title', 'Book Camp | Camp FreedivePH')
@section('meta_description', 'Book a 2D1N freediving camp in Mabini, Batangas.')
@section('hide_header', true)

@section('content')
<div class="w-full bg-white min-h-screen text-sm" 
     x-data="bookingForm({
         initialClass: '{{ $selectedClass }}',
         initialStep: {{ $initialStep ?? 1 }},
         confirmedBookingData: {{ json_encode($confirmedBookingData ?? null) }},
         pickupPoints: {{ json_encode($pickupPoints) }},
         pricingConfig: {
             basePrices: {
                 discovery: {{ $packagesData['discovery']['price'] ?? 4250 }},
                 fundive_cert: {{ $packagesData['fundive']['price_certified'] ?? 2500 }},
                 fundive_noncert: {{ $packagesData['fundive']['price_non_certified'] ?? 3300 }},
                 refinement: {{ $packagesData['refinement']['price'] ?? 4100 }}
             },
             fees: {
                 carpool: {{ $feesData['carpool'] ?? 1200 }},
                 boat_dive: {{ $feesData['boat_dive'] ?? 600 }},
                 lgu_pass: {{ $feesData['lgu_pass'] ?? 300 }},
                 environmental: {{ $feesData['environmental'] ?? 50 }}
             },
             downpayments: {
                 carpool: {{ $downpaymentsData['carpool'] ?? 3000 }},
                 own_transpo: {{ $downpaymentsData['own_transpo'] ?? 2000 }}
             }
         },
         csrfToken: '{{ csrf_token() }}',
         checkWeatherUrl: '{{ route('api.weather.check') }}',
         pricingQuoteUrl: '{{ route('api.pricing.quote') }}',
         storeBookingUrl: '{{ route('booking.store') }}'
     })"
     x-init="initBooking()">

    <!-- Combined Top Sticky Header & Numbered Circle Stepper -->
    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-[#E5E5EA] shadow-2xs">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3 sm:py-3.5 flex items-center justify-between gap-3 sm:gap-6">
            
            <!-- Left: Brand Logo & Title (Mobile: Only Logo, Desktop: Logo + Name) -->
            <a href="{{ route('landing') }}" class="flex items-center gap-2 sm:gap-2.5 group shrink-0 min-w-0 focus-visible:outline-none" title="Camp FreedivePH">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH Logo" class="w-8 h-8 sm:w-9 sm:h-9 rounded-full object-contain bg-white shrink-0 border border-[#E5E5EA]">
                <div class="hidden sm:block min-w-0">
                    <span class="font-extrabold text-sm sm:text-base tracking-tight text-[#1D1D1F] block leading-none truncate">Camp Freedive<span class="text-[#780000]">PH</span></span>
                    <span class="text-[10px] sm:text-[11px] text-[#6E6E73] font-medium tracking-wider block mt-0.5 truncate">Mabini, Batangas</span>
                </div>
            </a>

            <!-- Right: Stepper (Desktop: Numbered Circles + Labels, Mobile: Numbered Circles Only) -->
            
            <!-- Desktop Stepper (MD+) -->
            <nav aria-label="Booking Progress" class="hidden md:flex items-center gap-1.5 lg:gap-2.5">
                <template x-for="(label, index) in shortStepTitles" :key="index">
                    <div class="flex items-center">
                        <!-- Step Item -->
                        <button type="button"
                                @click="goToStep(index + 1)"
                                :disabled="currentStep <= (index + 1) || currentStep === 5"
                                :aria-current="currentStep === (index + 1) ? 'step' : null"
                                :aria-label="currentStep > (index + 1) ? ('Return to step ' + (index + 1) + ': ' + label) : ((index + 1) + '. ' + label)"
                                class="flex items-center gap-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 rounded-full py-1 px-1.5 transition-all"
                                :class="{
                                    'cursor-pointer hover:opacity-80 active:scale-95': currentStep > (index + 1) && currentStep !== 5,
                                    'cursor-default': currentStep <= (index + 1) || currentStep === 5
                                }">
                            <!-- Circle Number -->
                            <span class="w-6 h-6 lg:w-7 lg:h-7 rounded-full flex items-center justify-center text-xs font-black transition-all duration-200 shrink-0"
                                  :class="{
                                      'bg-[#780000] text-white shadow-xs scale-105': currentStep === (index + 1),
                                      'bg-[#780000]/15 text-[#780000] font-bold': currentStep > (index + 1),
                                      'bg-[#F2F2F7] text-[#8E8E93]': currentStep < (index + 1)
                                  }">
                                <template x-if="currentStep > (index + 1)">
                                    <svg class="w-3.5 h-3.5 stroke-[3]" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                                </template>
                                <template x-if="currentStep <= (index + 1)">
                                    <span x-text="index + 1"></span>
                                </template>
                            </span>
                            <!-- Label -->
                            <span class="text-xs lg:text-sm transition-colors duration-200 whitespace-nowrap"
                                  :class="{
                                      'text-[#1D1D1F] font-black': currentStep === (index + 1),
                                      'text-[#1D1D1F] font-semibold': currentStep > (index + 1),
                                      'text-[#8E8E93] font-medium': currentStep < (index + 1)
                                  }"
                                  x-text="label">
                            </span>
                        </button>

                        <!-- Connecting Line (if not last step) -->
                        <div x-show="index < 4" class="w-3 lg:w-6 h-[1.5px] mx-1 lg:mx-2 transition-colors duration-200"
                             :class="currentStep > (index + 1) ? 'bg-[#780000]/40' : 'bg-[#E5E5EA]'"></div>
                    </div>
                </template>
            </nav>

            <!-- Mobile Stepper (<MD) -->
            <nav aria-label="Booking Progress" class="flex md:hidden items-center gap-1 shrink-0">
                <template x-for="stepNum in [1, 2, 3, 4, 5]" :key="stepNum">
                    <div class="flex items-center">
                        <!-- Circle Number / Step Button -->
                        <button type="button"
                                @click="goToStep(stepNum)"
                                :disabled="currentStep <= stepNum || currentStep === 5"
                                :aria-current="currentStep === stepNum ? 'step' : null"
                                :aria-label="currentStep > stepNum ? ('Return to step ' + stepNum + ': ' + shortStepTitles[stepNum - 1]) : ('Step ' + stepNum + ': ' + shortStepTitles[stepNum - 1])"
                                class="p-1 min-w-[32px] min-h-[32px] flex items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] transition-all"
                                :class="{
                                    'cursor-pointer active:scale-90': currentStep > stepNum && currentStep !== 5,
                                    'cursor-default': currentStep <= stepNum || currentStep === 5
                                }">
                            <span class="w-5 h-5 sm:w-6 sm:h-6 rounded-full flex items-center justify-center text-[10px] sm:text-[11px] font-black transition-all duration-200 shrink-0"
                                  :class="{
                                      'bg-[#780000] text-white shadow-xs scale-105': currentStep === stepNum,
                                      'bg-[#780000]/15 text-[#780000] font-bold': currentStep > stepNum,
                                      'bg-[#F2F2F7] text-[#8E8E93]': currentStep < stepNum
                                  }">
                                <template x-if="currentStep > stepNum">
                                    <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 stroke-[3]" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                                </template>
                                <template x-if="currentStep <= stepNum">
                                    <span x-text="stepNum"></span>
                                </template>
                            </span>
                        </button>

                        <!-- Connecting Line -->
                        <div x-show="stepNum < 5" class="w-2 sm:w-3 h-[1.5px] mx-0.5 sm:mx-1 transition-colors duration-200"
                             :class="currentStep > stepNum ? 'bg-[#780000]/40' : 'bg-[#E5E5EA]'"></div>
                    </div>
                </template>
            </nav>

        </div>
    </header>

    <!-- Main Booking Page Body -->
    <main class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-6 sm:py-10">

        <!-- Step Heading Banner -->
        <div class="mb-5 sm:mb-8">
            <h1 class="text-xl sm:text-3xl font-black text-[#1D1D1F] tracking-tight" x-text="stepTitles[currentStep - 1]"></h1>
            <p class="text-xs sm:text-sm text-[#6E6E73] mt-1" x-show="currentStep === 1">Choose the package that matches your freediving experience level.</p>
            <p class="text-xs sm:text-sm text-[#6E6E73] mt-1" x-show="currentStep === 2">Select your 2D1N weekend dive schedule with real-time weather safety validation.</p>
            <p class="text-xs sm:text-sm text-[#6E6E73] mt-1" x-show="currentStep === 3">Enter guest details, emergency contact, carpool hub, and optional add-ons.</p>
            <p class="text-xs sm:text-sm text-[#6E6E73] mt-1" x-show="currentStep === 4">Review your reservation breakdown and complete downpayment.</p>
            <p class="text-xs sm:text-sm text-[#6E6E73] mt-1" x-show="currentStep === 5">Save your booking reference PIN and view your camp itinerary.</p>
        </div>

        <!-- Error Alert Banner -->
        <div x-show="errorMessage" x-cloak class="mb-6 p-3.5 sm:p-4 rounded-xl bg-[#FEF2F2] text-[#991B1B] text-sm flex items-center justify-between gap-3 shadow-2xs">
            <div class="flex items-center gap-2 min-w-0">
                <span x-text="errorMessage"></span>
            </div>
            <button type="button" @click="errorMessage = ''" aria-label="Dismiss error message" class="w-11 h-11 -mr-2 -my-2 flex items-center justify-center text-[#991B1B] hover:bg-[#991B1B]/10 rounded-lg font-bold text-base transition-colors shrink-0 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#991B1B]">✕</button>
        </div>

        <!-- Draft Restored Notification Banner -->
        <div x-show="draftRestored" x-cloak class="mb-6 p-3.5 sm:p-4 rounded-xl bg-[#F0FDF4] text-[#166534] text-sm flex items-center justify-between gap-3 shadow-2xs">
            <div class="flex items-center gap-2 min-w-0">
                <span>Your saved booking progress has been automatically restored.</span>
            </div>
            <div class="flex items-center gap-1 shrink-0 -mr-2">
                <button type="button" @click="resetForm()" class="font-bold underline text-[#15803D] hover:text-[#166534] text-sm min-h-[44px] px-3 inline-flex items-center justify-center rounded-lg cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#15803D]">
                    Clear
                </button>
                <button type="button" @click="draftRestored = false" aria-label="Dismiss restored draft notification" class="w-11 h-11 flex items-center justify-center text-[#166534] hover:bg-[#166534]/10 rounded-lg font-bold text-base transition-colors shrink-0 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#166534]">✕</button>
            </div>
        </div>

        <!-- Booking Form Container -->
        <div class="relative text-sm">

            <!-- Step 1: Select Class -->
            <div x-show="currentStep === 1" x-cloak class="space-y-6">

                <!-- Mobile-Only Quick Jump Tabs -->
                <div class="block lg:hidden sticky top-[57px] sm:top-[61px] z-30 bg-white/95 backdrop-blur-md py-2.5 -mx-3 px-3 sm:-mx-6 sm:px-6 border-b border-[#E5E5EA] shadow-2xs mb-5">
                    <div class="flex items-center gap-2 overflow-x-auto no-scrollbar py-0.5">
                        <button type="button"
                                @click="selectAndScrollPackage('discovery')"
                                class="py-2 px-4 rounded-full text-xs font-bold transition-all shrink-0 flex items-center justify-center cursor-pointer"
                                :class="form.class_type === 'discovery' ? 'bg-[#780000] text-white shadow-xs' : 'bg-[#F2F2F7] text-[#1D1D1F] hover:bg-[#E5E5EA]'">
                            Discovery
                        </button>
                        <button type="button"
                                @click="selectAndScrollPackage('fundive')"
                                class="py-2 px-4 rounded-full text-xs font-bold transition-all shrink-0 flex items-center justify-center cursor-pointer"
                                :class="form.class_type === 'fundive' ? 'bg-[#780000] text-white shadow-xs' : 'bg-[#F2F2F7] text-[#1D1D1F] hover:bg-[#E5E5EA]'">
                            Fundive
                        </button>
                        <button type="button"
                                @click="selectAndScrollPackage('refinement')"
                                class="py-2 px-4 rounded-full text-xs font-bold transition-all shrink-0 flex items-center justify-center cursor-pointer"
                                :class="form.class_type === 'refinement' ? 'bg-[#780000] text-white shadow-xs' : 'bg-[#F2F2F7] text-[#1D1D1F] hover:bg-[#E5E5EA]'">
                            Refinement Class
                        </button>
                    </div>
                </div>

                <!-- 3 Packages Grid (items-start prevents other cards from extending on accordion toggle) -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    
                    <!-- 1. Discovery Package Card -->
                    <div id="package-discovery"
                         x-data="{ openDetails: false }"
                         class="scroll-mt-32 lg:scroll-mt-0 bg-white rounded-2xl border border-[#E5E5EA] shadow-xs hover:border-[#D1D1D6] hover:shadow-md transition-all duration-200 flex flex-col justify-between overflow-hidden text-sm relative">
                        
                        <!-- Package Header & Price -->
                        <div class="p-6 sm:p-7 flex flex-col justify-between text-center flex-grow">
                            <div>

                                <!-- Title -->
                                <h3 class="text-2xl sm:text-3xl font-black text-[#1D1D1F] tracking-tight mb-2">
                                    Discovery
                                </h3>
                                
                                 <!-- Category Badge -->
                                <span class="inline-block text-xs font-bold text-[#780000] uppercase tracking-wider mb-2">
                                    BEGINNER CLASS
                                </span>

                                <!-- Pricing Display -->
                                <div class="my-4">
                                    <div class="text-3xl sm:text-4xl font-black text-[#1D1D1F] tracking-tight">
                                        ₱{{ number_format($packagesData['discovery']['price'] ?? 4250) }}
                                    </div>
                                    <span class="text-xs sm:text-sm text-[#6E6E73] font-medium block mt-1">
                                        per person
                                    </span>
                                </div>

                                <!-- Target Audience Description -->
                                <p class="text-sm text-[#4A4A4F] font-medium my-3 mb-4 min-h-[44px] flex items-center justify-center leading-relaxed text-center">
                                    Complete beginners, non-swimmers, and first-timers.
                                </p>
                            </div>

                            <!-- Action Row: Book Button + Quaternary See Offer Details -->
                            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4">
                                <button type="button"
                                        @click="form.class_type = 'discovery'; nextStep()"
                                        class="w-full sm:flex-1 min-h-[44px] py-3 px-5 rounded-xl font-extrabold text-sm text-center flex items-center justify-center bg-[#00C3D0] hover:bg-[#00B2BE] active:bg-[#009DA7] text-[#1D1D1F] shadow-xs hover:shadow active:scale-[0.98] transition-all cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#00C3D0] focus-visible:ring-offset-2">
                                    Select
                                </button>

                            <button type="button"
                                    @click.stop="openDetails = !openDetails"
                                    class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 gap-1.5 text-xs sm:text-sm font-bold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-xl transition-colors cursor-pointer shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1D1D1F]"
                                    :aria-expanded="openDetails"
                                    aria-controls="discovery-details"
                                    title="Toggle offer details">
                                <span x-text="openDetails ? 'Hide details' : 'See offer details'"></span>
                                <svg class="w-4 h-4 transform transition-transform duration-200" :class="openDetails ? 'rotate-180 text-[#1D1D1F]' : 'text-[#6E6E73]'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Inclusions & Exclusions Accordion -->
                    <div x-show="openDetails" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         x-cloak 
                         id="discovery-details" 
                         class="p-5 sm:p-6 border-t border-[#E5E5EA] bg-white space-y-5 text-left">
                        
                        <!-- Inclusions -->
                        <div>
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#1D1D1F] mb-2.5 flex items-center gap-1.5">
                                <span>Inclusions</span>
                            </h4>
                            <ul class="space-y-2 text-xs sm:text-sm text-[#1D1D1F]">
                                @foreach($packagesData['discovery']['inclusions'] ?? [] as $inc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#780000] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span>{{ $inc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <!-- Exclusions -->
                        <div class="pt-3 border-t border-[#E5E5EA]">
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73] mb-2 flex items-center gap-1.5">
                                <span>Exclusions</span>
                            </h4>
                            <ul class="space-y-1.5 text-xs sm:text-sm text-[#636366]">
                                @foreach($packagesData['discovery']['exclusions'] ?? [] as $exc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#8E8E93] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span>{{ $exc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- 2. Fundive Package Card (With 2-Option Direct Choice after Description) -->
                <div id="package-fundive"
                     x-data="{ openDetails: false }"
                     class="scroll-mt-32 lg:scroll-mt-0 bg-white rounded-2xl border border-[#E5E5EA] shadow-xs hover:border-[#D1D1D6] hover:shadow-md transition-all duration-200 flex flex-col justify-between overflow-hidden text-sm relative">
                    
                    <!-- Package Header & Price -->
                    <div class="p-6 sm:p-7 flex flex-col justify-between flex-grow">
                        <div>
                            <div class="text-center">

                                <!-- Title -->
                                <h3 class="text-2xl sm:text-3xl font-black text-[#1D1D1F] tracking-tight mb-2">
                                    Fundive
                                </h3>
                            
                            <!-- Category Badge -->
                                <span class="inline-block text-xs font-bold text-[#780000] uppercase tracking-wider mb-2">
                                    PREREQUISITE: DISCOVERY CLASS
                                </span>
                            </div>

                            <!-- Target Audience Description (Placed before certification options) -->
                            <p class="text-sm text-[#4A4A4F] font-medium my-3 mb-4 min-h-[44px] flex items-center justify-center leading-relaxed text-center">
                                Certified or experienced freedivers who want to explore and take photos.
                            </p>

                            <!-- 2-Option Direct Choice Selector (Placed after description as requested) -->
                            <div class="my-3 space-y-2 text-left" role="radiogroup" aria-label="Fundive certification options">
                                <span class="text-[11px] font-bold text-[#6E6E73] uppercase tracking-wider block text-center mb-3">
                                    Select Your Certification Option
                                </span>

                                <!-- Option A: Certified Diver -->
                                <button type="button"
                                        @click.stop="form.class_type = 'fundive'; form.is_certified_diver = true; onFundiveOptionChanged()"
                                        class="w-full p-2.5 sm:p-3 rounded-xl border border-transparent text-left transition-all flex items-center justify-between gap-2.5 cursor-pointer bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                        :class="(form.is_certified_diver) 
                                            ? 'ring-2 ring-[#780000]' 
                                            : ''">
                                    <div class="min-w-0">
                                        <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate">Certified Diver</span>
                                        <span class="text-[11px] text-[#6E6E73] block italic truncate">Safety coach not included</span>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="text-sm sm:text-base font-black text-[#1D1D1F]">₱{{ number_format($packagesData['fundive']['price_certified'] ?? 2500) }}</span>
                                    </div>
                                </button>

                                <!-- Option B: Non-Certified Diver -->
                                <button type="button"
                                        @click.stop="form.class_type = 'fundive'; form.is_certified_diver = false; onFundiveOptionChanged()"
                                        class="w-full p-2.5 sm:p-3 rounded-xl border border-transparent text-left transition-all flex items-center justify-between gap-2.5 cursor-pointer bg-transparent hover:bg-transparent hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                        :class="(!form.is_certified_diver) 
                                            ? 'ring-2 ring-[#780000]' 
                                            : ''">
                                    <div class="min-w-0">
                                        <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate">Non-Certified Diver</span>
                                        <span class="text-[11px] text-[#6E6E73] block italic truncate">Includes dedicated coach</span>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="text-sm sm:text-base font-black text-[#1D1D1F]">₱{{ number_format($packagesData['fundive']['price_non_certified'] ?? 3300) }}</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Action Row: Book Button + Quaternary See Offer Details -->
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4">
                            <button type="button"
                                    @click="form.class_type = 'fundive'; nextStep()"
                                    class="w-full sm:flex-1 min-h-[44px] py-3 px-5 rounded-xl font-extrabold text-sm text-center flex items-center justify-center bg-[#00C3D0] hover:bg-[#00B2BE] active:bg-[#009DA7] text-[#1D1D1F] shadow-xs hover:shadow active:scale-[0.98] transition-all cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#00C3D0] focus-visible:ring-offset-2">
                                Select
                            </button>

                            <button type="button"
                                    @click.stop="openDetails = !openDetails"
                                    class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 gap-1.5 text-xs sm:text-sm font-bold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-xl transition-colors cursor-pointer shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1D1D1F]"
                                    :aria-expanded="openDetails"
                                    aria-controls="fundive-details"
                                    title="Toggle offer details">
                                <span x-text="openDetails ? 'Hide details' : 'See offer details'"></span>
                                <svg class="w-4 h-4 transform transition-transform duration-200" :class="openDetails ? 'rotate-180 text-[#1D1D1F]' : 'text-[#6E6E73]'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Inclusions & Exclusions Accordion -->
                    <div x-show="openDetails" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         x-cloak 
                         id="fundive-details" 
                         class="p-5 sm:p-6 border-t border-[#E5E5EA] bg-white space-y-5 text-left">
                        
                        <!-- Inclusions -->
                        <div>
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#1D1D1F] mb-2.5 flex items-center gap-1.5">
                                <span>Inclusions</span>
                            </h4>
                            <ul class="space-y-2 text-xs sm:text-sm text-[#1D1D1F]">
                                @foreach($packagesData['fundive']['inclusions'] ?? [] as $inc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#780000] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span>{{ $inc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <!-- Exclusions -->
                        <div class="pt-3 border-t border-[#E5E5EA]">
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73] mb-2 flex items-center gap-1.5">
                                <span>Exclusions</span>
                            </h4>
                            <ul class="space-y-1.5 text-xs sm:text-sm text-[#636366]">
                                @foreach($packagesData['fundive']['exclusions'] ?? [] as $exc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#8E8E93] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span>{{ $exc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- 3. Refinement Package Card -->
                <div id="package-refinement"
                     x-data="{ openDetails: false }"
                     class="scroll-mt-32 lg:scroll-mt-0 bg-white rounded-2xl border border-[#E5E5EA] shadow-xs hover:border-[#D1D1D6] hover:shadow-md transition-all duration-200 flex flex-col justify-between overflow-hidden text-sm relative">
                    
                    <!-- Package Header & Price -->
                    <div class="p-6 sm:p-7 flex flex-col justify-between text-center flex-grow">
                        <div>

                            <!-- Title -->
                            <h3 class="text-2xl sm:text-3xl font-black text-[#1D1D1F] tracking-tight mb-2">
                                Refinement Class
                            </h3>

                            <!-- Category Badge -->
                            <span class="inline-block text-xs font-bold text-[#780000] uppercase tracking-wider mb-2">
                                PRACTICE DIVE
                            </span>

                            <!-- Pricing Display -->
                            <div class="my-4">
                                <div class="text-3xl sm:text-4xl font-black text-[#1D1D1F] tracking-tight">
                                    ₱{{ number_format($packagesData['refinement']['price'] ?? 4100) }}
                                </div>
                                <span class="text-xs sm:text-sm text-[#6E6E73] font-medium block mt-1">
                                    per person
                                </span>
                            </div>

                            <!-- Target Audience Description -->
                            <p class="text-sm text-[#4A4A4F] font-medium my-3 mb-4 min-h-[44px] flex items-center justify-center leading-relaxed text-center">
                                Divers who already completed an intro class but want to fix their form.
                            </p>
                        </div>

                        <!-- Action Row: Book Button + Quaternary See Offer Details -->
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4">
                            <button type="button"
                                    @click="form.class_type = 'refinement'; nextStep()"
                                    class="w-full sm:flex-1 min-h-[44px] py-3 px-5 rounded-xl font-extrabold text-sm text-center flex items-center justify-center bg-[#00C3D0] hover:bg-[#00B2BE] active:bg-[#009DA7] text-[#1D1D1F] shadow-xs hover:shadow active:scale-[0.98] transition-all cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#00C3D0] focus-visible:ring-offset-2">
                                Select
                            </button>

                            <button type="button"
                                    @click.stop="openDetails = !openDetails"
                                    class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 gap-1.5 text-xs sm:text-sm font-bold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-xl transition-colors cursor-pointer shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1D1D1F]"
                                    :aria-expanded="openDetails"
                                    aria-controls="refinement-details"
                                    title="Toggle offer details">
                                <span x-text="openDetails ? 'Hide details' : 'See offer details'"></span>
                                <svg class="w-4 h-4 transform transition-transform duration-200" :class="openDetails ? 'rotate-180 text-[#1D1D1F]' : 'text-[#6E6E73]'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Inclusions & Exclusions Accordion -->
                    <div x-show="openDetails" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         x-cloak 
                         id="refinement-details" 
                         class="p-5 sm:p-6 border-t border-[#E5E5EA] bg-white space-y-5 text-left">
                        
                        <!-- Inclusions -->
                        <div>
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#1D1D1F] mb-2.5 flex items-center gap-1.5">
                                <span>Inclusions</span>
                            </h4>
                            <ul class="space-y-2 text-xs sm:text-sm text-[#1D1D1F]">
                                @foreach($packagesData['refinement']['inclusions'] ?? [] as $inc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#780000] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span>{{ $inc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <!-- Exclusions -->
                        <div class="pt-3 border-t border-[#E5E5EA]">
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73] mb-2 flex items-center gap-1.5">
                                <span>Exclusions</span>
                            </h4>
                            <ul class="space-y-1.5 text-xs sm:text-sm text-[#636366]">
                                @foreach($packagesData['refinement']['exclusions'] ?? [] as $exc)
                                    <li class="flex items-start gap-2.5">
                                        <svg class="w-4 h-4 text-[#8E8E93] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span>{{ $exc }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- Step 2: Select Dates -->
        <div x-show="currentStep === 2" x-cloak class="space-y-6">

            <!-- Dates and Safety Evaluation Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">

                <!-- Calendar and Date Selection -->
                <div class="lg:col-span-7 space-y-5">

                    <!-- Selected Dates Overview -->
                    <div class="rounded-2xl border border-[#E5E5EA] bg-white p-3.5 sm:p-5 transition-all shadow-2xs">
                        <div class="grid grid-cols-2 divide-x divide-[#E5E5EA]">
                            
                            <!-- Depart (Day 1) -->
                            <div class="pr-2.5 sm:pr-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between items-start gap-1 sm:gap-2">
                                    <span class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-[#6E6E73]">Depart</span>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded bg-[#F8EAEA] text-[#780000] whitespace-nowrap">Day 1</span>
                                </div>
                                <div class="mt-1.5">
                                    <template x-if="form.start_date">
                                        <div>
                                            <div class="text-sm sm:text-lg md:text-xl font-black text-[#1D1D1F] tracking-tight truncate" x-text="formatDateDisplay(form.start_date)"></div>
                                            <div class="text-xs sm:text-sm font-semibold text-[#780000] mt-0.5 truncate" x-text="formatDateDayOfWeek(form.start_date)"></div>
                                        </div>
                                    </template>
                                    <template x-if="!form.start_date">
                                        <div class="text-xs sm:text-sm font-medium text-[#636366] italic py-1">
                                            Select start date below
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Return (Day 2) -->
                            <div class="pl-2.5 sm:pl-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between items-start gap-1 sm:gap-2">
                                    <span class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-[#6E6E73]">Return</span>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded bg-[#EBF7F8] text-[#00C3D0] whitespace-nowrap">Day 2</span>
                                </div>
                                <div class="mt-1.5">
                                    <template x-if="form.end_date">
                                        <div>
                                            <div class="text-sm sm:text-lg md:text-xl font-black text-[#1D1D1F] tracking-tight truncate" x-text="formatDateDisplay(form.end_date)"></div>
                                            <div class="text-xs sm:text-sm font-semibold text-[#00C3D0] mt-0.5 truncate" x-text="formatDateDayOfWeek(form.end_date)"></div>
                                        </div>
                                    </template>
                                    <template x-if="!form.end_date">
                                        <div class="text-xs sm:text-sm font-medium text-[#636366] italic py-1">
                                            Next day return
                                        </div>
                                    </template>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Interactive Dual-Month Calendar -->
                    <div class="bg-white rounded-2xl p-4 sm:p-7 space-y-6 shadow-2xs border border-[#E5E5EA]">
                        
                        <!-- Months Container (Single month on mobile with arrows, dual months on desktop) -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12 items-start">
                            
                            <!-- First Month -->
                            <div class="space-y-4">
                                <!-- Header for Month 1 with Prev/Next Arrows -->
                                <div class="flex items-center justify-between h-11">
                                    <button type="button" 
                                            @click="prevMonth()" 
                                            :disabled="!canGoPrev()"
                                            class="w-11 h-11 rounded-full flex items-center justify-center hover:bg-[#F2F2F7] active:bg-[#E5E5EA] disabled:opacity-20 disabled:cursor-not-allowed transition-all text-[#1D1D1F] shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                            title="Previous Month"
                                            aria-label="Previous Month">
                                        <svg class="w-5 h-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                                    </button>

                                    <div class="font-extrabold text-sm sm:text-base text-[#1D1D1F] text-center flex-1">
                                        <span x-text="getMonthName(month1Month) + ' ' + month1Year"></span>
                                    </div>

                                    <!-- Month Navigation Controls -->
                                    <div class="w-11 hidden md:block shrink-0"></div>
                                    <button type="button" 
                                            @click="nextMonth()" 
                                            class="w-11 h-11 rounded-full flex items-center justify-center active:bg-[#E5E5EA] transition-all text-[#1D1D1F] md:hidden shrink-0 focus-visible:outline-none"
                                            title="Next Month"
                                            aria-label="Next Month">
                                        <svg class="w-5 h-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                                    </button>
                                </div>
                                
                                <!-- Weekday Headers -->
                                <div class="grid grid-cols-7 text-center text-xs sm:text-sm font-semibold text-[#6E6E73] py-1">
                                    <span class="text-[#780000] font-bold">Sun</span>
                                    <span>Mon</span>
                                    <span>Tue</span>
                                    <span>Wed</span>
                                    <span>Thu</span>
                                    <span>Fri</span>
                                    <span>Sat</span>
                                </div>

                                <!-- Month Calendar Days -->
                                <div class="grid grid-cols-7 gap-y-1 sm:gap-y-2 text-center text-xs sm:text-sm">
                                    <template x-for="(dObj, idx) in getMonthDays(month1Year, month1Month)" :key="'m1-' + idx">
                                        <div class="h-10 sm:h-11 flex items-center justify-center relative">
                                            <template x-if="dObj.isBlank">
                                                <span class="w-full h-full"></span>
                                            </template>
                                            <template x-if="!dObj.isBlank">
                                                <button type="button"
                                                        @click="!dObj.isDisabled && selectDate(dObj.dateStr)"
                                                        :disabled="dObj.isDisabled"
                                                        :aria-label="dObj.dateStr + (dObj.isDisabled ? ' (Unavailable)' : '')"
                                                        :aria-pressed="dObj.dateStr === form.start_date"
                                                        class="w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-semibold text-xs sm:text-sm transition-all relative z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                                        :class="{
                                                            'bg-[#780000] text-white font-bold': dObj.dateStr === form.start_date,
                                                            'bg-[#00C3D0] text-white font-bold ring-2 ring-[#00C3D0]/20': dObj.dateStr === form.end_date,
                                                            'text-[#AEAEB2] cursor-not-allowed': dObj.isDisabled,
                                                            'hover:bg-[#F2F2F7] hover:text-[#780000] cursor-pointer text-[#1D1D1F]': !dObj.isDisabled && dObj.dateStr !== form.start_date && dObj.dateStr !== form.end_date,
                                                            'text-[#780000] font-semibold': dObj.isSunday && !dObj.isDisabled && dObj.dateStr !== form.start_date && dObj.dateStr !== form.end_date
                                                        }"
                                                        x-text="dObj.day">
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Second Month (Visible on md+ screens for dual-calendar experience) -->
                            <div class="space-y-4 hidden md:block">
                                <!-- Header for Month 2 with Next Arrow -->
                                <div class="flex items-center justify-between h-11">
                                    <div class="w-11 shrink-0"></div>

                                    <div class="font-extrabold text-sm sm:text-base text-[#1D1D1F] text-center flex-1">
                                        <span x-text="getMonthName(month2Month) + ' ' + month2Year"></span>
                                    </div>

                                    <button type="button" 
                                            @click="nextMonth()" 
                                            class="w-11 h-11 rounded-full flex items-center justify-center hover:bg-[#F2F2F7] active:bg-[#E5E5EA] transition-all text-[#1D1D1F] shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                            title="Next Month"
                                            aria-label="Next Month">
                                        <svg class="w-5 h-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                                    </button>
                                </div>
                                
                                <!-- Weekday Headers -->
                                <div class="grid grid-cols-7 text-center text-xs sm:text-sm font-semibold text-[#6E6E73] py-1">
                                    <span class="text-[#780000] font-bold">Sun</span>
                                    <span>Mon</span>
                                    <span>Tue</span>
                                    <span>Wed</span>
                                    <span>Thu</span>
                                    <span>Fri</span>
                                    <span>Sat</span>
                                </div>

                                <!-- Month Calendar Days -->
                                <div class="grid grid-cols-7 gap-y-1 sm:gap-y-2 text-center text-xs sm:text-sm">
                                    <template x-for="(dObj, idx) in getMonthDays(month2Year, month2Month)" :key="'m2-' + idx">
                                        <div class="h-10 sm:h-11 flex items-center justify-center relative">
                                            <template x-if="dObj.isBlank">
                                                <span class="w-full h-full"></span>
                                            </template>
                                            <template x-if="!dObj.isBlank">
                                                <button type="button"
                                                        @click="!dObj.isDisabled && selectDate(dObj.dateStr)"
                                                        :disabled="dObj.isDisabled"
                                                        :aria-label="dObj.dateStr + (dObj.isDisabled ? ' (Unavailable)' : '')"
                                                        :aria-pressed="dObj.dateStr === form.start_date"
                                                        class="w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-semibold text-xs sm:text-sm transition-all relative z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                                        :class="{
                                                            'bg-[#780000] text-white font-bold': dObj.dateStr === form.start_date,
                                                            'bg-[#00C3D0] text-white font-bold ring-2 ring-[#00C3D0]/20': dObj.dateStr === form.end_date,
                                                            'text-[#AEAEB2] cursor-not-allowed': dObj.isDisabled,
                                                            'hover:bg-[#F2F2F7] hover:text-[#780000] cursor-pointer text-[#1D1D1F]': !dObj.isDisabled && dObj.dateStr !== form.start_date && dObj.dateStr !== form.end_date,
                                                            'text-[#780000] font-semibold': dObj.isSunday && !dObj.isDisabled && dObj.dateStr !== form.start_date && dObj.dateStr !== form.end_date
                                                        }"
                                                        x-text="dObj.day">
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                        </div>

                    </div>

                </div>

                <!-- Dive Safety Evaluation -->
                <div class="lg:col-span-5 space-y-4 lg:sticky lg:top-8">
                    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs space-y-4">
                        
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5 sm:gap-2">
                                <h3 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-[#6E6E73]">Dive conditions</h3>
                                
                                <!-- About Forecast Icon with Popover -->
                                <div class="relative inline-flex items-center" x-data="{ showTip: false }">
                                    <button type="button" 
                                            @click="showTip = !showTip"
                                            :aria-expanded="showTip"
                                            aria-label="About Forecast" 
                                            class="w-11 h-11 flex items-center justify-center rounded-lg text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] cursor-pointer"
                                            :class="showTip ? 'bg-[#F2F2F7] text-[#1D1D1F]' : ''">
                                        <img src="{{ asset('icons/icons8-exclamation-mark-60.png') }}" class="w-5 h-5 shrink-0 object-contain" alt="">
                                    </button>
                                    <div x-show="showTip"
                                         @click.outside="showTip = false"
                                         x-cloak
                                         x-transition:enter="transition ease-out duration-200"
                                         x-transition:enter-start="opacity-0 translate-y-1"
                                         x-transition:enter-end="opacity-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-150"
                                         x-transition:leave-start="opacity-100 translate-y-0"
                                         x-transition:leave-end="opacity-0 translate-y-1"
                                         class="absolute left-0 top-full mt-1.5 w-64 sm:w-72 max-w-[calc(100vw-3rem)] p-3.5 bg-[#1D1D1F] text-white text-xs rounded-xl shadow-xl z-50 space-y-1.5 leading-relaxed border border-[#2C2C2E]">
                                        <div class="font-bold flex items-center justify-between text-[#00C3D0]">
                                            <div class="flex items-center gap-1.5">
                                                <img src="{{ asset('icons/icons8-exclamation-mark-60.png') }}" class="w-4 h-4 shrink-0 object-contain brightness-0 invert" alt="" aria-hidden="true">
                                                <span>Weather &amp; Sea Conditions</span>
                                            </div>
                                            <button type="button" @click="showTip = false" aria-label="Close note" class="text-[#8E8E93] hover:text-white font-bold p-1">✕</button>
                                        </div>
                                        <p class="text-xs text-gray-200">
                                            Safety ratings shown are automated predictions based on coastal forecast models. Actual water conditions can change naturally, and our safety team continuously checks the water before every dive.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <template x-if="weatherLoading">
                                <span class="text-xs sm:text-sm text-[#00C3D0] font-bold flex items-center gap-1 shrink-0">
                                    <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                    Checking...
                                </span>
                            </template>
                        </div>

                        <!-- Forecast Loading State -->
                        <div x-show="weatherLoading" x-cloak class="space-y-4 py-2">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs sm:text-sm">
                                    <span id="weather-eval-status" class="font-semibold text-[#1D1D1F]">Checking weather & marine conditions...</span>
                                    <span class="font-mono font-bold text-[#00C3D0]" x-text="weatherProgress + '%'"></span>
                                </div>
                                <div role="progressbar"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     :aria-valuenow="weatherProgress"
                                     :aria-valuetext="weatherProgress + '%'"
                                     aria-labelledby="weather-eval-status"
                                     class="w-full bg-[#E5E5EA] h-1.5 rounded-full overflow-hidden">
                                    <div class="bg-gradient-to-r from-[#00C3D0] to-[#00C3D0] h-full transition-all duration-200 rounded-full"
                                         :style="'width: ' + weatherProgress + '%'"></div>
                                </div>
                            </div>

                            <!-- Weather Assessment Loading State -->
                            <div class="space-y-2.5 pt-1 animate-pulse">
                                <div class="p-3.5 sm:p-4 rounded-xl bg-[#F2F2F7] space-y-2">
                                    <div class="h-3.5 w-24 bg-[#E5E5EA] rounded"></div>
                                    <div class="h-3 w-40 bg-[#E5E5EA] rounded"></div>
                                </div>
                                <div class="p-3.5 sm:p-4 rounded-xl bg-[#F2F2F7] space-y-2">
                                    <div class="h-3.5 w-24 bg-[#E5E5EA] rounded"></div>
                                    <div class="h-3 w-40 bg-[#E5E5EA] rounded"></div>
                                </div>
                            </div>

                            <!-- Rotating Tip -->
                            <div class="p-3 rounded-xl bg-[#F2F2F7] flex items-center gap-2.5 text-xs sm:text-sm text-[#6E6E73]">
                                <div class="flex-1 min-w-0">
                                    <span class="font-bold text-[#1D1D1F]" x-text="currentTip.title + ': '"></span>
                                    <span x-text="currentTip.text"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Weather Assessment Results (shared design: components/dive-safety/live-evaluation) -->
                        <template x-if="forecast && !weatherLoading">
                            <x-dive-safety.live-evaluation forecast="forecast" />
                        </template>

                        <!-- Empty State: Dates Selected but Forecast Failed to Load -->
                        <template x-if="form.start_date && !weatherLoading && !forecast">
                            <div class="py-1 text-left">
                                <p class="text-xs sm:text-sm text-[#6E6E73] leading-relaxed">
                                    Marine condition evaluations are currently updating. You may still proceed with your reservation; our safety team verifies water conditions continuously.
                                </p>
                            </div>
                        </template>

                        <!-- Empty State: No Date Selected Yet -->
                        <template x-if="!form.start_date && !weatherLoading">
                            <div class="py-1 text-left">
                                <p class="text-xs sm:text-sm text-[#636366] italic leading-relaxed">
                                    Select dates on the calendar to view safety evaluation.
                                </p>
                            </div>
                        </template>

                    </div>
                </div>

            </div>

            <!-- Step 2 Navigation Controls -->
            <div class="pt-6 flex items-center justify-between gap-3 sm:gap-4">
                <button type="button" 
                        @click="prevStep()" 
                        class="btn-secondary px-4 sm:px-6 py-2.5 sm:py-3 text-xs sm:text-sm font-bold flex items-center gap-1.5 cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    <span>Back</span>
                </button>
                <button type="button" 
                        @click="nextStep()" 
                        class="btn-primary px-6 sm:px-8 py-2.5 sm:py-3 text-xs sm:text-sm font-extrabold cursor-pointer active:scale-[0.99] transition-all shadow-sm">
                    <span>Continue</span>
                </button>
            </div>
        </div>

        <!-- Step 3: Booking Details -->
        <div x-show="currentStep === 3" x-cloak class="space-y-6">

            <!-- Form Inputs and Summary Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">

                <!-- Form Inputs -->
                <div class="lg:col-span-7 space-y-6">

                    <!-- Section 1: Participants -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-1 gap-2 flex-wrap sm:flex-nowrap">
                            <h4 class="text-base sm:text-lg font-bold text-[#1D1D1F]">1. Participants</h4>
                            <button type="button" 
                                    @click="addParticipant()" 
                                    class="px-3 py-1.5 rounded-xl border border-[#780000] text-[#780000] font-bold text-xs sm:text-sm bg-[#F8EAEA]/30 hover:bg-[#F8EAEA] transition-colors flex items-center gap-1.5 shadow-2xs cursor-pointer shrink-0">
                                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                <span>Add Participant</span>
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <template x-for="(participant, index) in form.participants" :key="index">
                                <div class="p-3.5 sm:p-5 border border-[#D1D1D6] rounded-xl relative space-y-3.5 sm:space-y-4">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold text-[#780000] text-sm" x-text="'Participant #' + (index + 1)"></span>
                                        <button type="button" 
                                                x-show="form.participants.length > 1" 
                                                @click="removeParticipant(index)"
                                                class="inline-flex items-center justify-center min-h-[44px] px-3 py-1.5 text-xs sm:text-sm font-semibold text-[#D70015] hover:bg-rose-50 rounded-lg transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D70015]">
                                            Remove
                                        </button>
                                    </div>

                                    <!-- Participant Name -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">First Name <span class="text-[#780000]">*</span></label>
                                            <input type="text" 
                                                   x-model="participant.first_name" 
                                                   @input="participant.first_name = cleanNameInput(participant.first_name); assembleParticipantName(participant)"
                                                   placeholder="e.g. Maria Ma." 
                                                   autocomplete="given-name"
                                                   autocapitalize="words"
                                                   spellcheck="false"
                                                   :aria-invalid="touchedStep3 && !validateName(participant.first_name)"
                                                   :aria-describedby="'err-participant-fn-' + index"
                                                   class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors"
                                                   :class="touchedStep3 && !validateName(participant.first_name) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]'">
                                            <span :id="'err-participant-fn-' + index" x-show="touchedStep3 && !validateName(participant.first_name)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                                Please enter a valid first name (letters, hyphens, periods).
                                            </span>
                                        </div>

                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                            <input type="text" 
                                                   x-model="participant.middle_name" 
                                                   :disabled="participant.no_middle_name"
                                                   @input="participant.middle_name = cleanNameInput(participant.middle_name); assembleParticipantName(participant)"
                                                   placeholder="Full middle name" 
                                                   autocomplete="additional-name"
                                                   autocapitalize="words"
                                                   spellcheck="false"
                                                   class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors border-[#D1D1D6] focus:border-[#780000] disabled:bg-gray-100 disabled:text-gray-400">
                                            <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73] cursor-pointer">
                                                <input type="checkbox" 
                                                       x-model="participant.no_middle_name" 
                                                       @change="if(participant.no_middle_name) participant.middle_name = ''; assembleParticipantName(participant)"
                                                       class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000]">
                                                <span>I do not have a legal middle name</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                                        <div class="sm:col-span-2">
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Last Name <span class="text-[#780000]">*</span></label>
                                            <input type="text" 
                                                   x-model="participant.last_name" 
                                                   @input="participant.last_name = cleanNameInput(participant.last_name); assembleParticipantName(participant)"
                                                   placeholder="e.g. Santos-Concepcion or De la Cruz" 
                                                   autocomplete="family-name"
                                                   autocapitalize="words"
                                                   spellcheck="false"
                                                   :aria-invalid="touchedStep3 && !validateName(participant.last_name)"
                                                   :aria-describedby="'err-participant-ln-' + index"
                                                   class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors"
                                                   :class="touchedStep3 && !validateName(participant.last_name) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]'">
                                            <span :id="'err-participant-ln-' + index" x-show="touchedStep3 && !validateName(participant.last_name)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                                Please enter a valid last name (letters, hyphens, spaces).
                                            </span>
                                        </div>

                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                            <select x-model="participant.suffix" 
                                                    @change="assembleParticipantName(participant)"
                                                    class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium focus:border-[#780000]">
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

                                    <!-- Birthdate, Gender, Swimming Ability & Health -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Birthdate <span class="text-[#780000]">*</span></label>
                                            <x-date-picker model="participant.birthdate" x-on:change="calculateAge(participant)" :max="now()->toDateString()" year-select placeholder="Select birthdate" invalid="touchedStep3 && !validateAge(calculateAge(participant))" />
                                            <span class="text-xs text-[#6E6E73]" x-show="participant.birthdate" x-text="calculateAge(participant) + ' years old'"></span>
                                        </div>

                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Gender <span class="text-[#780000]">*</span></label>
                                            <select x-model="participant.gender" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                                <option value="">Select gender</option>
                                                <option value="female">Female</option>
                                                <option value="male">Male</option>
                                                <option value="non_binary">Non-binary</option>
                                                <option value="prefer_not_to_say">Prefer not to say</option>
                                            </select>
                                        </div>

                                        <div x-show="form.class_type === 'discovery'">
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Swimming Ability</label>
                                            <select x-model="participant.swimmer_status" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                                                <option value="non_swimmer">Non-Swimmer</option>
                                                <option value="casual_swimmer">Casual / Beginner Swimmer</option>
                                                <option value="confident_swimmer">Confident Swimmer</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">
                                                Health Condition / Medical Notes
                                            </label>
                                            <input type="text"
                                                   x-model="participant.health_condition"
                                                   placeholder="e.g. Asthma, ear pressure issues, or None"
                                                   class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Contact Details -->
                    <div class="space-y-4 pt-1">
                        <div class="flex items-center justify-between pb-1 flex-wrap gap-2">
                            <h4 class="text-base sm:text-lg font-bold text-[#1D1D1F]">2. Contact Information</h4>
                            <span class="text-xs text-[#6E6E73]">Primary Contact</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">
                                        Primary Contact First Name <span class="text-[#780000]">*</span>
                                    </label>
                                    <template x-if="form.selected_lead_participant === 'custom'">
                                        <button type="button" 
                                                @click="form.selected_lead_participant = 0; syncLeadContactFromParticipant()" 
                                                class="text-xs text-[#780000] hover:underline font-semibold">
                                            Select from participants
                                        </button>
                                    </template>
                                </div>

                                <!-- Dropdown for Lead First Name (selecting from participants) -->
                                <div x-show="form.selected_lead_participant !== 'custom'">
                                    <select x-model="form.selected_lead_participant" 
                                            @change="onLeadParticipantChange()"
                                            class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium transition-colors focus:border-[#780000]">
                                        <template x-for="(p, idx) in form.participants" :key="idx">
                                            <option :value="idx" x-text="(p.first_name ? p.first_name : ('Participant #' + (idx + 1))) + (p.last_name ? ' (' + p.name + ')' : '')"></option>
                                        </template>
                                        <option value="custom">Other (Enter custom name...)</option>
                                    </select>
                                </div>

                                <!-- Text Input for Lead First Name (when custom is chosen) -->
                                <div x-show="form.selected_lead_participant === 'custom'" x-cloak class="space-y-1">
                                    <input type="text" 
                                           x-model="form.contact_first_name" 
                                           @input="form.contact_first_name = cleanNameInput(form.contact_first_name); assembleContactName()"
                                           placeholder="Juan" 
                                           autocomplete="given-name"
                                           autocapitalize="words"
                                           spellcheck="false"
                                           :aria-invalid="touchedStep3 && !validateName(form.contact_first_name)"
                                           aria-describedby="err-lead-fn"
                                           class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors"
                                           :class="touchedStep3 && !validateName(form.contact_first_name) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]'">
                                    <span id="err-lead-fn" x-show="touchedStep3 && !validateName(form.contact_first_name)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                        Please enter a valid first name (letters, hyphens, periods).
                                    </span>
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Primary Contact Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                <input type="text" 
                                       x-model="form.contact_middle_name" 
                                       :disabled="form.contact_no_middle_name || form.selected_lead_participant !== 'custom'"
                                       @input="form.contact_middle_name = cleanNameInput(form.contact_middle_name); assembleContactName()"
                                       placeholder="Full middle name" 
                                       autocomplete="additional-name"
                                       autocapitalize="words"
                                       spellcheck="false"
                                       class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] transition-colors border-[#D1D1D6] focus:border-[#780000] disabled:bg-gray-100 disabled:text-gray-400">
                                <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73]" :class="form.selected_lead_participant !== 'custom' ? 'cursor-default pointer-events-none' : 'cursor-pointer'">
                                    <input type="checkbox" 
                                           x-model="form.contact_no_middle_name" 
                                           :disabled="form.selected_lead_participant !== 'custom'"
                                           @change="if(form.contact_no_middle_name) form.contact_middle_name = ''; assembleContactName()"
                                           class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000] disabled:opacity-60">
                                    <span>I do not have a legal middle name</span>
                                </label>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Primary Contact Last Name <span class="text-[#780000]">*</span></label>
                                <input type="text" 
                                       x-model="form.contact_last_name" 
                                       :readonly="form.selected_lead_participant !== 'custom'"
                                       @input="form.contact_last_name = cleanNameInput(form.contact_last_name); assembleContactName()"
                                       placeholder="Dela Cruz" 
                                       autocomplete="family-name"
                                       autocapitalize="words"
                                       spellcheck="false"
                                       :aria-invalid="touchedStep3 && !validateName(form.contact_last_name)"
                                       aria-describedby="err-lead-ln"
                                       class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] transition-colors"
                                       :class="(touchedStep3 && !validateName(form.contact_last_name) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]') + (form.selected_lead_participant !== 'custom' ? ' bg-[#F9FAFB] cursor-default' : ' bg-white')">
                                <span id="err-lead-ln" x-show="touchedStep3 && !validateName(form.contact_last_name)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                    Please enter a valid last name (letters, hyphens, spaces).
                                </span>
                            </div>

                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                                <select x-model="form.contact_suffix" 
                                        :disabled="form.selected_lead_participant !== 'custom'"
                                        @change="assembleContactName()"
                                        class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] font-medium focus:border-[#780000] disabled:bg-gray-100 disabled:text-gray-400"
                                        :class="form.selected_lead_participant !== 'custom' ? 'bg-[#F9FAFB]' : 'bg-white'">
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
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Email Address <span class="text-[#780000]">*</span></label>
                                <input type="email" 
                                       x-model="form.contact_email" 
                                       placeholder="juan@example.com" 
                                       autocomplete="email"
                                       inputmode="email"
                                       autocapitalize="none"
                                       spellcheck="false"
                                       :aria-invalid="touchedStep3 && !validateEmail(form.contact_email)"
                                       aria-describedby="err-lead-email"
                                       class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors"
                                       :class="touchedStep3 && !validateEmail(form.contact_email) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]'">
                                <span id="err-lead-email" x-show="touchedStep3 && !validateEmail(form.contact_email)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                    Please enter a valid email address with @ (e.g. name@example.com).
                                </span>
                            </div>

                            <div>
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Mobile Number (PH) <span class="text-[#780000]">*</span></label>
                                <input type="tel" 
                                       x-model="form.contact_phone" 
                                       @input="form.contact_phone = formatPhoneInput($event.target.value)"
                                       placeholder="+63 917-123-4567" 
                                       autocomplete="tel"
                                       inputmode="tel"
                                       maxlength="16"
                                       :aria-invalid="touchedStep3 && !validatePhone(form.contact_phone)"
                                       aria-describedby="err-lead-phone"
                                       class="w-full px-3.5 py-2.5 rounded-xl border text-sm text-[#1D1D1F] bg-white transition-colors focus:outline-none"
                                       :class="touchedStep3 && !validatePhone(form.contact_phone) ? 'border-[#D70015] bg-red-50/20' : 'border-[#D1D1D6] focus:border-[#780000]'">
                                <span id="err-lead-phone" x-show="touchedStep3 && !validatePhone(form.contact_phone)" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                    Valid 10-digit PH mobile number starting with 9 required (e.g. +63 917-123-4567).
                                </span>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Facebook Profile Link (Optional)</label>
                                <input type="url" 
                                       x-model="form.contact_facebook" 
                                       placeholder="facebook.com/juandelacruz" 
                                       autocomplete="url"
                                       inputmode="url"
                                       autocapitalize="none"
                                       spellcheck="false"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                            </div>
                        </div>
                    </div>

                    <!-- Transportation and Add-ons -->
                    <div class="space-y-4 pt-1">
                        <h4 class="text-base sm:text-lg font-bold text-[#1D1D1F] pb-1">3. Transportation & Add-ons</h4>
                        
                        <div class="space-y-3">
                            <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm">Transportation Option:</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="Transportation Option">
                                <label tabindex="0"
                                       role="radio"
                                       :aria-checked="form.pickup_option === 'carpool'"
                                       @keydown.enter.prevent="form.pickup_option = 'carpool'"
                                       @keydown.space.prevent="form.pickup_option = 'carpool'"
                                       class="p-3.5 rounded-xl border border-[#D1D1D6] transition-all cursor-pointer flex flex-col justify-between select-none bg-white hover:bg-[#FAFAFA] hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                       :class="form.pickup_option === 'carpool' ? 'ring-2 ring-[#780000]' : ''">
                                    <input type="radio" name="pickup_opt" value="carpool" x-model="form.pickup_option" class="hidden">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between">
                                             <span class="font-extrabold text-sm text-[#1D1D1F]">Manila Carpool Van</span>
                                            <span x-show="form.pickup_option === 'carpool'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                        </div>
                                        <span class="font-bold text-[#780000] text-xs sm:text-sm block">₱1,200 / person</span>
                                    </div>
                                </label>

                                <label tabindex="0"
                                       role="radio"
                                       :aria-checked="form.pickup_option === 'own'"
                                       @keydown.enter.prevent="form.pickup_option = 'own'"
                                       @keydown.space.prevent="form.pickup_option = 'own'"
                                       class="p-3.5 rounded-xl border border-[#D1D1D6] transition-all cursor-pointer flex flex-col justify-between select-none bg-white hover:bg-[#FAFAFA] hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                       :class="form.pickup_option === 'own' ? 'ring-2 ring-[#780000]' : ''">
                                    <input type="radio" name="pickup_opt" value="own" x-model="form.pickup_option" class="hidden">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between">
                                            <span class="font-extrabold text-sm text-[#1D1D1F]">Own Vehicle / Commute</span>
                                            <span x-show="form.pickup_option === 'own'" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                        </div>
                                        <span class="font-bold text-[#1D1D1F] text-xs sm:text-sm block">₱0 (Self-arranged)</span>
                                    </div>
                                </label>
                            </div>

                            <!-- Pickup Hub Selection with Times -->
                            <div x-show="form.pickup_option === 'carpool'" x-cloak class="pt-1">
                                <label class="block font-bold text-[#1D1D1F] text-xs sm:text-sm mb-1.5">Carpool Pickup Hub & Schedule: <span class="text-[#780000]">*</span></label>
                                <select x-model="form.pickup_location" 
                                        :aria-invalid="touchedStep3 && form.pickup_option === 'carpool' && !form.pickup_location"
                                        aria-describedby="err-carpool-hub"
                                        class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium transition-all cursor-pointer hover:bg-[#FAFAFA] hover:ring-1 hover:ring-[#780000] focus:outline-none focus:ring-2 focus:ring-[#780000]"
                                        :class="touchedStep3 && form.pickup_option === 'carpool' && !form.pickup_location ? 'ring-2 ring-[#D70015] bg-red-50/20' : (form.pickup_location ? 'ring-2 ring-[#780000]' : '')">
                                    <option value="" disabled selected class="bg-white text-[#1D1D1F]">-- Select Carpool Pickup Hub & Schedule --</option>
                                    <template x-for="p in pickupPoints" :key="p.id">
                                        <option :value="p.name" x-text="p.name" class="bg-white text-[#1D1D1F]"></option>
                                    </template>
                                </select>
                                <span id="err-carpool-hub" x-show="touchedStep3 && form.pickup_option === 'carpool' && !form.pickup_location" class="text-xs text-[#D70015] font-semibold mt-1 block">
                                    Please select your preferred Carpool Pickup Hub to continue.
                                </span>
                            </div>
                        </div>

                        <!-- Optional Boat Dive -->
                        <div class="pt-1">
                            <label tabindex="0"
                                   role="checkbox"
                                   :aria-checked="form.boat_dive"
                                   @keydown.enter.prevent="form.boat_dive = !form.boat_dive"
                                   @keydown.space.prevent="form.boat_dive = !form.boat_dive"
                                   class="p-3.5 rounded-xl border border-[#D1D1D6] transition-all cursor-pointer flex items-start justify-between gap-3 select-none bg-white hover:bg-[#FAFAFA] hover:ring-1 hover:ring-[#780000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]"
                                   :class="form.boat_dive ? 'ring-2 ring-[#780000]' : ''">
                                <input type="checkbox" x-model="form.boat_dive" class="hidden">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-sm text-[#1D1D1F] block">Boat Dive (Optional)</span>
                                        <span x-show="form.boat_dive" class="w-2.5 h-2.5 rounded-full bg-[#780000]"></span>
                                    </div>
                                    <span class="text-xs sm:text-sm text-[#6E6E73] block leading-snug">Boat ride to deeper marine sanctuaries.</span>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="font-extrabold text-[#780000] text-xs sm:text-sm">+₱{{ number_format($feesData['boat_dive'] ?? 600) }}</span>
                                    <span class="text-[11px] sm:text-xs text-[#6E6E73] block">/ person</span>
                                </div>
                            </label>
                        </div>
                    </div>

                </div>

                <!-- Live Booking Summary -->
                <div class="lg:col-span-5 space-y-4 lg:sticky lg:top-6">
                    
                    <!-- Itemized Price Calculation Summary -->
                    <div class="border border-[#E5E5EA] rounded-2xl bg-white overflow-hidden shadow-2xs">
                        <div class="px-4 py-3 border-b border-[#E5E5EA] flex items-center justify-between gap-2">
                            <span class="font-bold text-[#1D1D1F] text-sm sm:text-base">Booking Summary</span>
                        </div>

                        <div class="p-3.5 sm:p-5 space-y-3 text-xs sm:text-sm">
                            <div class="flex justify-between items-center gap-2 text-[#1d1d1f]">
                                <span class="min-w-0">Base Class Rate (<span class="capitalize" x-text="form.class_type"></span> × <span x-text="form.participants.length"></span>)</span>
                                <span class="font-bold text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber((pricingQuote ? pricingQuote.base_price_per_pax : calculateBasePriceUnit()) * form.participants.length)"></span>
                            </div>

                            <!-- Dynamic Pricing Adjustments (with vertical line on left, badges removed) -->
                            <template x-if="pricingQuote && pricingQuote.adjustments && pricingQuote.adjustments.length > 0">
                                <div class="border-l-2 border-[#D1D1D6] pl-3 py-1 space-y-2 my-1.5">
                                    <template x-for="adj in pricingQuote.adjustments" :key="adj.rule_id">
                                        <div class="flex justify-between items-start gap-2 text-xs sm:text-sm">
                                            <span class="font-medium text-[#6E6E73] leading-snug" x-text="adj.rule_name"></span>
                                            <span class="font-bold shrink-0 text-right" :class="adj.delta_per_pax >= 0 ? 'text-[#D70015]' : 'text-[#065F46]'" x-text="(adj.delta_per_pax >= 0 ? '+' : '−') + '₱' + formatNumber(Math.abs(adj.delta_per_pax) * form.participants.length)"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Adjusted Class Subtotal (only shown if there are adjustments) -->
                            <div x-show="pricingQuote && pricingQuote.adjustments && pricingQuote.adjustments.length > 0" class="flex justify-between items-center gap-2 text-[#1D1D1F] font-semibold">
                                <span>Adjusted Class Subtotal</span>
                                <span class="font-extrabold text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateSubtotal())"></span>
                            </div>

                            <div x-show="form.pickup_option === 'carpool'" class="flex justify-between items-center gap-2 text-[#1D1D1F] font-semibold">
                                <span>Transportation (Carpool × <span x-text="form.participants.length"></span>)</span>
                                <span class="font-extrabold text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateCarpoolFee())"></span>
                            </div>

                            <div x-show="form.boat_dive" class="flex justify-between items-center gap-2 text-[#1D1D1F] font-semibold">
                                <span>Boat Dive (₱{{ number_format($feesData['boat_dive'] ?? 600) }} × <span x-text="form.participants.length"></span>)</span>
                                <span class="font-extrabold text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateBoatFee())"></span>
                            </div>

                            <div class="flex justify-between items-center gap-2 text-[#1D1D1F] font-semibold">
                                <div>
                                    <span>Mabini LGU Pass &amp; Env. Fee</span>
                                    <span class="text-[11px] text-[#6E6E73] font-normal block">(₱350 / head × <span x-text="form.participants.length"></span> pax)</span>
                                </div>
                                <span class="font-extrabold text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateLguAndEnvFee())"></span>
                            </div>

                            <!-- Downpayment Due Now (No background color) -->
                            <div class="pt-2.5 border-t border-[#E5E5EA] flex justify-between items-center gap-2">
                                <div class="min-w-0">
                                    <span class="font-bold text-[#1D1D1F] block text-xs sm:text-sm leading-tight">Downpayment Due Now</span>
                                    <span class="text-[11px] sm:text-xs text-[#6E6E73] font-medium block mt-0.5" x-text="'(' + (form.pickup_option === 'carpool' ? '₱3,000.00' : '₱2,000.00') + ' / head × ' + form.participants.length + ' pax)'"></span>
                                </div>
                                <span class="text-sm sm:text-base font-black text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateDownpayment())"></span>
                            </div>

                            <!-- Remaining Balance at Camp (No background color) -->
                            <div class="flex justify-between items-center gap-2 text-[#1D1D1F]">
                                <span class="font-medium text-xs sm:text-sm">Remaining Balance (at Camp)</span>
                                <span class="font-bold text-xs sm:text-sm shrink-0 text-right text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateTotal() - calculateDownpayment())"></span>
                            </div>

                            <!-- Total Amount (Placed after the two payments) -->
                            <div class="pt-2.5 border-t border-[#E5E5EA] flex justify-between items-center gap-2 font-extrabold text-[#1D1D1F]">
                                <span class="text-xs sm:text-sm">Total Amount</span>
                                <span class="text-base sm:text-lg font-black text-[#1D1D1F] shrink-0 text-right" x-text="'₱' + formatNumber(calculateTotal())"></span>
                            </div>

                            <div id="booking-confirmations"
                                 class="mt-4 p-3.5 sm:p-4 space-y-3 scroll-mt-24"
                                 :class="touchedStep3 && (!form.confirmation_ack || !form.hasAgreedToTerms) ? 'border-2 border-[#D70015] bg-red-50/40 ring-2 ring-[#D70015]/20' : ''">
                                <label tabindex="0" role="checkbox"
                                       :aria-checked="form.confirmation_ack"
                                       @keydown.enter.prevent="form.confirmation_ack = !form.confirmation_ack"
                                       @keydown.space.prevent="form.confirmation_ack = !form.confirmation_ack"
                                       class="flex items-start gap-2.5 cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 rounded-lg">
                                    <input type="checkbox" x-model="form.confirmation_ack"
                                           class="w-4 h-4 rounded text-[#780000] focus:ring-[#780000] mt-0.5 shrink-0 cursor-pointer">
                                    <span class="font-bold text-[#780000] text-xs sm:text-sm leading-relaxed">
                                        I confirm that all information provided is accurate. <span class="text-[#D70015]">*</span>
                                    </span>
                                </label>
                                <label tabindex="0" role="checkbox"
                                       :aria-checked="form.hasAgreedToTerms"
                                       @keydown.enter.prevent="form.hasAgreedToTerms = !form.hasAgreedToTerms"
                                       @keydown.space.prevent="form.hasAgreedToTerms = !form.hasAgreedToTerms"
                                       class="flex items-start gap-2.5 cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 rounded-lg">
                                    <input id="terms-checkbox" type="checkbox" x-model="form.hasAgreedToTerms"
                                           class="w-4 h-4 rounded text-[#780000] focus:ring-[#780000] mt-0.5 shrink-0 cursor-pointer">
                                    <span class="font-bold text-[#780000] text-xs sm:text-sm leading-relaxed">
                                        I have read and agree to the
                                        <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener noreferrer" @click.stop class="text-[#780000] underline">Terms &amp; Conditions</a>
                                        and
                                        <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener noreferrer" @click.stop class="text-[#780000] underline">Privacy Policy</a>. <span class="text-[#D70015]">*</span>
                                    </span>
                                </label>
                                <span x-show="touchedStep3 && !form.confirmation_ack" class="text-xs text-[#D70015] font-semibold block">
                                    Please confirm that all information provided is accurate.
                                </span>
                                <span x-show="touchedStep3 && !form.hasAgreedToTerms" class="text-xs text-[#D70015] font-semibold block">
                                    Please read and agree to the Terms &amp; Conditions and Privacy Policy.
                                </span>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

            <!-- Step 3 Navigation Controls -->
            <div class="pt-6 flex items-center justify-between gap-3 sm:gap-4">
                <button type="button" 
                        @click="prevStep()" 
                        class="btn-secondary px-4 sm:px-6 py-2.5 sm:py-3 text-xs sm:text-sm font-bold flex items-center gap-1.5 cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    <span>Back</span>
                </button>
                <button type="button" 
                        @click="nextStep()" 
                        class="btn-primary px-6 sm:px-8 py-2.5 sm:py-3 text-xs sm:text-sm font-extrabold cursor-pointer active:scale-[0.99] transition-all shadow-sm">
                    <span x-text="'Proceed to Downpayment (₱' + formatNumber(calculateDownpayment()) + ')'">Proceed to Downpayment</span>
                </button>
            </div>

        </div>

        <!-- Step 4: Downpayment -->
        <div x-show="currentStep === 4" x-cloak class="space-y-6">

            <div class="max-w-7xl mx-auto space-y-6">
                <!-- Slot Hold Countdown Banner -->
                <div class="p-3.5 sm:p-4 rounded-xl bg-amber-50 text-amber-900 text-xs sm:text-sm flex items-center justify-between gap-3 shadow-2xs">
                    <div class="flex items-center gap-2 min-w-0">
                        <img src="{{ asset('icons/icons8-clock-60.png') }}" alt="" aria-hidden="true" class="w-4 h-4 sm:w-5 sm:h-5 shrink-0 object-contain">
                        <span class="font-medium">Slots held for checkout:</span>
                    </div>
                    <span class="font-mono font-bold text-amber-950 px-2.5 py-0.5 rounded-lg bg-amber-100/80 tracking-wide shrink-0" x-text="timerDisplay"></span>
                </div>

                <!-- 2-Column Grid: Reservation Breakdown (Left) & Cancellation Policy (Right) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 items-start">
                    
                    <!-- Left Column: Reservation Breakdown & Payment Channels -->
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">
                                Reservation Breakdown
                            </h3>
                            <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">
                                Review your trip summary and required downpayment to secure your slots.
                            </p>
                        </div>

                        <div class="rounded-2xl space-y-3">
                            <div class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <span>Package</span>
                                <strong class="text-[#1D1D1F] capitalize" x-text="form.class_type + ' (' + form.participants.length + ' pax)'"></strong>
                            </div>
                            <div class="flex justify-between items-center text-sm text-[#6E6E73]" x-show="form.start_date && form.end_date">
                                <span>Dive Dates</span>
                                <span class="font-medium text-[#1D1D1F]" x-text="formatDateDisplay(form.start_date) + ' - ' + formatDateDisplay(form.end_date)"></span>
                            </div>
                            <div class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <span>Course Subtotal</span>
                                <span class="font-medium text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateSubtotal())"></span>
                            </div>
                            <div x-show="form.pickup_option === 'carpool'" class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <span>Transportation (Manila Carpool)</span>
                                <span class="font-medium text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateCarpoolFee())"></span>
                            </div>
                            <div x-show="form.boat_dive" class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <span>Sanctuary Boat Dive</span>
                                <span class="font-medium text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateBoatFee())"></span>
                            </div>
                            <div class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <div>
                                    <span class="block">Mabini LGU Pass &amp; Env. Fee</span>
                                    <span class="text-xs text-[#8E8E93]" x-text="'(₱350 / head × ' + form.participants.length + ' pax)'"></span>
                                </div>
                                <span class="font-semibold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateLguAndEnvFee())"></span>
                            </div>
                            <div class="flex justify-between items-center text-sm text-[#6E6E73] pt-2 border-t border-[#E5E5EA]">
                                <span>Total Trip Cost</span>
                                <strong class="text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateTotal())"></strong>
                            </div>
                            <div class="flex justify-between items-center text-sm text-[#6E6E73]">
                                <span>Remaining Balance (at Camp)</span>
                                <span class="font-semibold text-[#1D1D1F]" x-text="'₱' + formatNumber(calculateTotal() - calculateDownpayment())"></span>
                            </div>

                            <!-- Downpayment Highlight -->
                            <div class="pt-3 flex justify-between items-center gap-2 border-t border-[#E5E5EA]">
                                <div>
                                    <span class="font-bold text-[#065F46] text-sm block">Downpayment Due Now:</span>
                                    <span class="text-xs text-[#065F46]" x-text="'(' + (form.pickup_option === 'carpool' ? '₱3,000.00' : '₱2,000.00') + ' / head × ' + form.participants.length + ' pax)'"></span>
                                </div>
                                <strong class="text-2xl font-black text-[#065F46]" x-text="'₱' + formatNumber(calculateDownpayment())"></strong>
                            </div>

                        </div>

                    </div>

                    <!-- Right Column: Cancellation & Reschedule Policy -->
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">
                                Cancellation & Reschedule Policy
                            </h3>
                            <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">
                                These notice periods apply to customer requests. Camp FreedivePH may also cancel or reschedule a dive when safety, weather, site, boat, or other operational conditions require it. We will notify affected customers and provide the applicable next steps.
                            </p>
                        </div>

                        <div class="text-xs sm:text-sm text-[#6E6E73]">
                            <!-- Tier 1: > 14 Days -->
                            <div class="p-3 rounded-xl space-y-1">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <strong class="text-xs sm:text-sm font-bold text-[#1D1D1F]">Lead Time More Than 14 Days Before Dive</strong>
                                    <span class="text-[11px] sm:text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-md">
                                        100% Refund or 1 Free Reschedule
                                    </span>
                                </div>
                                <p class="text-xs text-[#6E6E73] leading-relaxed">
                                    Eligible for full downpayment refund or one free date transfer to any future open schedule.
                                </p>
                            </div>

                            <!-- Tier 2: 7 to 14 Days -->
                            <div class="p-3 rounded-xl space-y-1">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <strong class="text-xs sm:text-sm font-bold text-[#1D1D1F]">Lead Time 7 to 14 Days Before Dive</strong>
                                    <span class="text-[11px] sm:text-xs font-bold text-amber-700 bg-amber-50 px-2.5 py-0.5 rounded-md">
                                        1 Free Date Reschedule
                                    </span>
                                </div>
                                <p class="text-xs text-[#6E6E73] leading-relaxed">
                                    Free date reschedule to another available schedule. Downpayment is non-refundable.
                                </p>
                            </div>

                            <!-- Tier 3: < 7 Days (Locked) -->
                            <div class="p-3 rounded-xl space-y-1">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <strong class="text-xs sm:text-sm font-bold text-[#1D1D1F]">Lead Time Less Than 7 Days Before Dive</strong>
                                    <span class="text-[11px] sm:text-xs font-bold text-rose-700 bg-rose-50 px-2.5 py-0.5 rounded-md">
                                        Non-Refundable
                                    </span>
                                </div>
                                <p class="text-xs text-[#6E6E73] leading-relaxed">
                                    Slots and resort/boat allocations are finalized. Cannot be refunded or rescheduled.
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Hosted Checkout Action Button (Full Width Bottom) -->
                <div class="pt-4 space-y-4">
                    <!-- Step 4 Navigation Controls -->
                    <div class="pt-2 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
                        <button type="button" 
                                @click="prevStep()" 
                                class="btn-secondary w-full sm:w-auto px-4 sm:px-6 py-2.5 sm:py-3 text-xs sm:text-sm font-bold flex items-center justify-center gap-1.5 cursor-pointer order-2 sm:order-1">
                            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                            <span>Back</span>
                        </button>

                        <div class="flex flex-col items-stretch sm:items-end gap-1.5 text-right w-full sm:w-auto order-1 sm:order-2">
                            <button type="button" 
                                    @click="processPayment(false, true)" 
                                    :disabled="submittingPayment"
                                    class="btn-primary w-full sm:w-auto px-6 sm:px-8 py-2.5 sm:py-3 text-xs sm:text-sm font-extrabold cursor-pointer active:scale-[0.99] transition-all shadow-sm flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!submittingPayment" class="flex items-center justify-center gap-2">
                                    <span>Proceed to PayMongo Hosted Checkout</span>
                                </span>
                                <span x-show="submittingPayment" class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                    <span>Redirecting to PayMongo...</span>
                                </span>
                            </button>
                            <span class="text-[11px] sm:text-xs text-[#6E6E73] leading-relaxed text-center sm:text-right">
                                Encrypted &amp; securely processed by PayMongo. We never store card or wallet details.
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Step 5: Confirmation & Credentials -->
        <div x-show="currentStep === 5" x-cloak class="space-y-6">

            <div class="max-w-7xl mx-auto space-y-6">
                <!-- 2-Column Grid: Confirmation & Voucher (Left) & Summary + Checklist (Right) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 items-start">
                    
                    <!-- Left Column: Confirmation Message & Voucher -->
                    <div class="space-y-4 sm:space-y-6 text-left">
                        <div class="space-y-2">
                            <p class="text-xs sm:text-sm text-[#6E6E73] leading-relaxed">
                                We've emailed your booking confirmation to <strong class="text-[#1D1D1F] break-all" x-text="form.contact_email"></strong>. Please save your reference number and PIN below.
                            </p>
                        </div>

                        <!-- Booking Credentials Voucher (Apple-Style Dark Card) -->
                        <div class="p-5 sm:p-6 rounded-2xl bg-[#1D1D1F] text-white space-y-4 shadow-md border border-[#2C2C2E]">
                            <div>
                                <span class="text-xs uppercase tracking-wider text-[#00C3D0] font-bold">Booking Reference Number</span>
                                <div class="text-xl sm:text-2xl md:text-3xl font-mono font-black text-white tracking-wider break-all mt-0.5" x-text="confirmedBooking.booking_number"></div>
                            </div>

                            <div class="pt-3 border-t border-white/15">
                                <span class="text-xs uppercase tracking-wider text-[#00C3D0] font-bold">4-Digit PIN</span>
                                <div class="text-2xl font-mono font-bold text-white tracking-widest mt-0.5" x-text="confirmedBooking.pin"></div>
                                <span class="text-xs text-[#A1A1A6] block mt-1">Keep this PIN safe to manage or update your booking anytime.</span>
                            </div>

                            <div class="pt-2">
                                <button type="button" 
                                        @click="copyCredentials()" 
                                        class="w-full sm:w-auto px-5 py-2.5 min-h-[44px] text-xs sm:text-sm font-bold bg-white hover:bg-[#F2F2F7] active:bg-[#E5E5EA] text-[#1D1D1F] rounded-xl shadow-sm inline-flex items-center justify-center gap-2 cursor-pointer active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#1D1D1F] transition-all">
                                    <img src="{{ asset('icons/icons8-copy-60.png') }}" class="w-4.5 h-4.5 object-contain shrink-0" alt="" aria-hidden="true" x-show="!copied">
                                    <svg x-show="copied" x-cloak class="w-4.5 h-4.5 text-[#065F46] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                    <span x-text="copied ? 'Copied to Clipboard!' : 'Copy Booking # and PIN'"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Recap Summary & Things to Bring Checklist -->
                    <div class="space-y-4">
                        <!-- Recap Summary -->
                        <div class="p-4 sm:p-5 rounded-xl border border-[#E5E5EA] text-left text-xs sm:text-sm space-y-2.5 shadow-2xs bg-white">
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[#6E6E73] shrink-0">Package:</span>
                                <span class="font-bold text-[#1D1D1F] capitalize text-right" x-text="form.class_type"></span>
                            </div>
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[#6E6E73] shrink-0">Trip Dates:</span>
                                <span class="font-bold text-[#1D1D1F] text-right" x-text="formatDateDisplay(form.start_date) + ' - ' + formatDateDisplay(form.end_date)"></span>
                            </div>
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[#6E6E73] shrink-0">Participants:</span>
                                <span class="font-bold text-[#1D1D1F] text-right" x-text="form.participants.length + ' participant(s)'"></span>
                            </div>

                            <!-- Applied Dynamic Pricing Rules -->
                            <template x-if="pricingQuote && pricingQuote.adjustments && pricingQuote.adjustments.length > 0">
                                <div class="border-l-2 border-[#D1D1D6] pl-3 py-1 space-y-1.5 my-1.5">
                                    <div class="text-xs uppercase font-bold tracking-wider text-[#6E6E73]">Applied Pricing Rules:</div>
                                    <template x-for="adj in pricingQuote.adjustments" :key="adj.rule_id">
                                        <div class="flex justify-between items-start gap-2 text-xs sm:text-sm">
                                            <span class="text-[#1D1D1F]" x-text="adj.rule_name + ' (' + adj.formatted_adjustment + ')'"></span>
                                            <span class="font-bold shrink-0 text-right" :class="adj.delta_per_pax >= 0 ? 'text-rose-700' : 'text-emerald-700'" x-text="(adj.delta_per_pax >= 0 ? '+' : '−') + '₱' + formatNumber(Math.abs(adj.delta_per_pax) * form.participants.length)"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[#6E6E73] shrink-0">Transportation:</span>
                                <span class="font-bold text-[#1D1D1F] text-right" x-text="form.pickup_option === 'carpool' ? form.pickup_location : 'Own Transportation'"></span>
                            </div>
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[#6E6E73] shrink-0">Mabini LGU Pass &amp; Env. Fee:</span>
                                <span class="font-bold text-[#1D1D1F] text-right" x-text="'₱' + formatNumber((confirmedBooking.lgu_fee !== undefined && confirmedBooking.environmental_fee !== undefined) ? ((Number(confirmedBooking.lgu_fee) || 0) + (Number(confirmedBooking.environmental_fee) || 0)) : calculateLguAndEnvFee())"></span>
                            </div>
                            <div class="flex justify-between items-center gap-2">
                                <span class="text-[#6E6E73]">Downpayment Paid:</span>
                                <span class="font-bold text-[#065F46]" x-text="'₱' + formatNumber(confirmedBooking.downpayment_paid)"></span>
                            </div>
                            <div class="flex justify-between items-center gap-2 text-[#780000] font-bold">
                                <span>Balance Due at Camp:</span>
                                <span x-text="'₱' + formatNumber(confirmedBooking.balance_due)"></span>
                            </div>
                        </div>

                        <!-- Things to Bring Checklist & Carpool Guidelines -->
                        <div class="p-4 sm:p-5 rounded-xl border border-[#E5E5EA] text-left text-xs sm:text-sm space-y-2.5 shadow-2xs bg-white">
                            <h4 class="font-bold text-[#1D1D1F]">Things to Bring (Towels, shampoo and soap are all provided):</h4>
                            <ul class="space-y-1 text-[#6E6E73] list-disc list-inside">
                                <li>Swimming clothes (anything you’re comfortable wearing)</li>
                                <li>Toiletries</li>
                                <li>Personal things</li>
                                <li>A pair of socks (any style) for fin fitting</li>
                            </ul>

                            <!-- Carpool Guidelines (if carpool availed) -->
                            <div x-show="form.pickup_option === 'carpool'" class="pt-2.5 border-t border-[#E5E5EA] space-y-1">
                                <h4 class="font-bold text-[#1D1D1F]">Carpool Reminder:</h4>
                                <p class="text-[#6E6E73] leading-relaxed">
                                    Please arrive at your selected pickup location (<strong class="text-[#1D1D1F]" x-text="form.pickup_location"></strong>) before the designated departure time. A 10-minute grace period applies before the van departs; please arrive promptly to avoid delays for other participants.
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Step 5 Navigation Controls -->
                <div class="pt-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 sm:gap-4">
                    <a href="{{ route('landing') }}" 
                       class="btn-secondary w-full sm:w-auto px-4 sm:px-6 py-2.5 sm:py-3 text-xs sm:text-sm font-bold flex items-center justify-center gap-1.5 cursor-pointer order-2 sm:order-1">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        <span>Done / Back to Home</span>
                    </a>
                    <a :href="confirmedBooking.manage_url" 
                       class="btn-primary w-full sm:w-auto px-6 sm:px-8 py-2.5 sm:py-3 text-xs sm:text-sm font-extrabold cursor-pointer active:scale-[0.99] transition-all shadow-sm flex items-center justify-center gap-1.5 order-1 sm:order-2">
                        <span>Manage This Booking</span>
                    </a>
                </div>
            </div>
        </div>

    </main>

    <!-- Session Expired In-Page Modal Dialog -->
    <div x-show="sessionExpired" 
         x-cloak
         role="dialog"
         aria-modal="true"
         aria-labelledby="session-expired-title"
         aria-describedby="session-expired-desc"
         class="fixed inset-0 z-50 flex justify-end bg-black/40 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 sm:p-7 shadow-2xl text-center space-y-4 relative border-l border-[#E5E5EA]"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
            
            <!-- Warning Icon -->
            <div class="w-14 h-14 mx-auto rounded-full bg-amber-50 flex items-center justify-center text-amber-600">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>

            <!-- Title & Description -->
            <div class="space-y-2">
                <h3 id="session-expired-title" class="text-lg sm:text-xl font-black text-[#1D1D1F] tracking-tight">
                    Session Expired
                </h3>
                <p id="session-expired-desc" class="text-xs sm:text-sm text-[#6E6E73] leading-relaxed">
                    Your 15-minute reservation hold has expired. To maintain fair slot availability for all campers, your session needs to restart.
                </p>
            </div>

            <!-- Action Button -->
            <div class="pt-2">
                <button type="button"
                        @click="restartSession()"
                        class="w-full min-h-[44px] py-3 px-5 rounded-xl font-extrabold text-sm text-center flex items-center justify-center bg-[#780000] hover:bg-[#5E0000] active:scale-[0.98] text-white shadow-sm transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2">
                    Restart Session
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
/**
 * Alpine.js Client-Side Booking Flow State Machine.
 *
 * Workflow Architecture:
 * - Step 1 (Package Selection): Class tier configuration and diver certification prerequisite checking.
 * - Step 2 (Date & Weather Selection): Interactive dual-calendar picker with live 16-day Open-Meteo & ML risk preview.
 * - Step 3 (Contact & Add-ons): Dynamic participant repeater, medical questionnaires, carpool hubs, and live pricing quote.
 * - Step 4 (Downpayment Checkout): 15-minute slot-hold countdown timer and PayMongo payment gateway integration.
 * - Step 5 (Voucher & PIN): Booking reference confirmation and credential copy tool.
 *
 * State Persistence:
 * Automatically synchronizes draft state to localStorage to prevent data loss on accidental page refresh.
 *
 * @param {Object} config Initial configuration (initialClass, pickupPoints).
 * @return {Object} Reactive Alpine.js component scope.
 */
// TODO: Implement Web Worker background sync for offline draft storage in IndexedDB.
function bookingForm(config) {
    return {
        config: config,
        currentStep: config.initialStep || 1,
        shortStepTitles: [
            "Class",
            "Dates",
            "Details",
            "Payment",
            "Confirmed"
        ],
        stepTitles: [
            "Select Class",
            "Choose Dive Dates",
            "Contact, Add-ons & Summary",
            "Secure Downpayment",
            "Booking Confirmed"
        ],
        pickupPoints: config.pickupPoints,
        form: {
            class_type: config.initialClass || 'discovery',
            is_certified_diver: false,
            discovery_completed_ack: false,
            start_date: '',
            end_date: '',
            participants: [
                { first_name: '', middle_name: '', no_middle_name: false, last_name: '', suffix: '', name: '', birthdate: '', gender: '', age: '', health_condition: '', swimmer_status: 'non_swimmer' }
            ],
            selected_lead_participant: 0,
            contact_first_name: '',
            contact_middle_name: '',
            contact_no_middle_name: false,
            contact_last_name: '',
            contact_suffix: '',
            contact_name: '',
            contact_email: '',
            contact_phone: '',
            contact_facebook: '',
            pickup_option: 'carpool',
            pickup_location: '',
            boat_dive: false,
            confirmation_ack: false,
            hasAgreedToTerms: false,
            payment_method: 'paymongo'
        },
        forecast: null,
        pricingQuote: null,
        weatherLoading: false,
        weatherProgress: 0,
        weatherProgressTimer: null,
        weatherTipTimer: null,
        weatherTipIndex: 0,
        weatherTips: [
            {title: 'Marine Safety', text: 'Mabini coastal waters feature sheltered coves ideal for beginner and advanced freediving.' },
            {title: 'Freediving Tip', text: 'Equalization is key: always equalize early and frequently before feeling ear pressure.' },
            {title: 'Marine Conditions', text: 'Our forecast monitors wave height (<1.0m is ideal), ocean currents, and wind speed.' },
            {title: 'Sanctuary Dives', text: 'Camp FreedivePH operates in Mabini Marine Protected Areas with clear year-round visibility.' },
            {title: 'Carpool Hubs', text: 'Weekend carpool vans depart Manila hubs at 2:30 AM - 3:30 AM directly to camp resort.' }
        ],
        get currentTip() {
            return this.weatherTips[this.weatherTipIndex] || this.weatherTips[0];
        },
        errorMessage: '',
        submittingPayment: false,
        timerSeconds: 15 * 60,
        timerDisplay: '15:00',
        timerInterval: null,
        confirmedBooking: config.confirmedBookingData || {
            booking_number: '',
            pin: '',
            downpayment_paid: 0,
            balance_due: 0,
            manage_url: '#'
        },
        copied: false,
        draftRestored: false,
        sessionExpired: false,

        selectAndScrollPackage(type) {
            this.form.class_type = type;
            this.$nextTick(() => {
                const el = document.getElementById('package-' + type);
                if (el) {
                    const headerOffset = 115;
                    const elementPosition = el.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                    window.scrollTo({
                        top: Math.max(0, offsetPosition),
                        behavior: 'smooth'
                    });
                }
            });
        },

        onFundiveOptionChanged() {
            if (this.form.start_date) {
                this.fetchPricingQuote();
            }
        },

        // Dual Calendar State & Helpers
        calendarYear: new Date().getFullYear(),
        calendarMonth: new Date().getMonth(),

        get month1Year() {
            return this.calendarYear;
        },
        get month1Month() {
            return this.calendarMonth;
        },
        get month2Year() {
            return (this.calendarMonth === 11) ? this.calendarYear + 1 : this.calendarYear;
        },
        get month2Month() {
            return (this.calendarMonth + 1) % 12;
        },

        getMonthName(mIndex) {
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            return months[mIndex] || '';
        },

        formatDateDisplay(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            if (parts.length !== 3) return dateStr;
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const d = parts[2].padStart(2, '0');
            const m = months[parseInt(parts[1], 10) - 1] || '';
            const y = parts[0];
            return `${d} ${m} ${y}`;
        },

        formatDateDayOfWeek(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            if (parts.length !== 3) return '';
            const date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            return days[date.getDay()] || '';
        },

        canGoPrev() {
            const now = new Date();
            const curY = now.getFullYear();
            const curM = now.getMonth();
            return (this.calendarYear > curY) || (this.calendarYear === curY && this.calendarMonth > curM);
        },

        prevMonth() {
            if (!this.canGoPrev()) return;
            if (this.calendarMonth === 0) {
                this.calendarMonth = 11;
                this.calendarYear--;
            } else {
                this.calendarMonth--;
            }
        },

        nextMonth() {
            if (this.calendarMonth === 11) {
                this.calendarMonth = 0;
                this.calendarYear++;
            } else {
                this.calendarMonth++;
            }
        },

        getMonthDays(year, month) {
            const firstDayIndex = new Date(year, month, 1).getDay();
            const totalDays = new Date(year, month + 1, 0).getDate();
            const now = new Date();
            const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            
            const days = [];
            for (let b = 0; b < firstDayIndex; b++) {
                days.push({ isBlank: true });
            }
            for (let d = 1; d <= totalDays; d++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const dayOfWeek = new Date(year, month, d).getDay();
                const isDisabled = dateStr < todayStr;
                const isSunday = (dayOfWeek === 0);
                days.push({
                    day: d,
                    dateStr: dateStr,
                    isBlank: false,
                    isDisabled: isDisabled,
                    isSunday: isSunday
                });
            }
            return days;
        },

        selectDate(dateStr) {
            this.form.start_date = dateStr;
            this.onStartDateChange();
        },

        formatPhoneInput(val) {
            if (!val) return '';
            let digits = val.toString().replace(/\D/g, '');
            if (digits.startsWith('63')) {
                digits = digits.substring(2);
            }
            if (digits.startsWith('0')) {
                digits = digits.substring(1);
            }
            digits = digits.substring(0, 10);
            if (!digits) return '';

            let res = '+63 ';
            if (digits.length <= 3) {
                res += digits;
            } else if (digits.length <= 6) {
                res += digits.substring(0, 3) + '-' + digits.substring(3);
            } else {
                res += digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
            }
            return res;
        },

        initBooking() {
            if (config.confirmedBookingData) {
                this.confirmedBooking = config.confirmedBookingData;
                this.currentStep = 5;
                if (config.confirmedBookingData.class_type) {
                    this.form.class_type = config.confirmedBookingData.class_type;
                }
                if (config.confirmedBookingData.start_date) {
                    this.form.start_date = config.confirmedBookingData.start_date;
                    this.form.end_date = config.confirmedBookingData.end_date;
                }
                if (config.confirmedBookingData.contact_email) {
                    this.form.contact_email = config.confirmedBookingData.contact_email;
                }
                if (config.confirmedBookingData.contact_name) {
                    this.form.contact_name = config.confirmedBookingData.contact_name;
                }
                if (config.confirmedBookingData.participants && config.confirmedBookingData.participants.length > 0) {
                    this.form.participants = config.confirmedBookingData.participants;
                }
                if (config.confirmedBookingData.pickup_option) {
                    this.form.pickup_option = config.confirmedBookingData.pickup_option;
                    this.form.pickup_location = config.confirmedBookingData.pickup_location || '';
                }
                if (config.confirmedBookingData.adjustments) {
                    this.pricingQuote = { adjustments: config.confirmedBookingData.adjustments };
                }
                this.clearDraft();
                return;
            }

            this.loadDraft();

            // Auto-save form inputs whenever they change
            this.$watch('form', () => {
                this.saveDraft();
            });

            // Watch for changes that affect live dynamic pricing quote
            this.$watch('form.class_type', () => this.fetchPricingQuote());
            this.$watch('form.is_certified_diver', () => this.fetchPricingQuote());
            this.$watch('form.participants.length', () => this.fetchPricingQuote());

            // Auto-save step position (for steps 1 to 4)
            this.$watch('currentStep', (val) => {
                if (val < 5) {
                    this.saveDraft();
                }
            });
        },

        saveDraft() {
            try {
                if (this.currentStep >= 5) return;
                const draft = {
                    form: this.form,
                    currentStep: this.currentStep,
                    savedAt: new Date().toISOString()
                };
                localStorage.setItem('camp_freedive_booking_draft', JSON.stringify(draft));
            } catch (e) {
                console.warn('Booking draft save error:', e);
            }
        },

        loadDraft() {
            try {
                const saved = localStorage.getItem('camp_freedive_booking_draft');
                if (saved) {
                    const data = JSON.parse(saved);
                    if (data && data.form) {
                        const hasDetails = data.form.contact_name || 
                                           data.form.contact_first_name ||
                                           data.form.contact_email || 
                                           data.form.contact_phone || 
                                           data.form.start_date ||
                                           (data.form.participants && data.form.participants[0] && (data.form.participants[0].first_name || data.form.participants[0].name));

                        this.form = {
                            ...this.form,
                            ...data.form
                        };

                        if (!Array.isArray(this.form.participants) || this.form.participants.length === 0) {
                            this.form.participants = [
                                { first_name: '', middle_name: '', no_middle_name: false, last_name: '', suffix: '', name: '', birthdate: '', gender: '', age: '', health_condition: '', swimmer_status: 'non_swimmer' }
                            ];
                        } else {
                            this.form.participants.forEach(p => {
                                if (p.middle_name === undefined) p.middle_name = '';
                                if (p.no_middle_name === undefined) p.no_middle_name = false;
                                if (p.suffix === undefined) p.suffix = '';
                                if (!p.first_name && p.name) {
                                    const parts = p.name.trim().split(/\s+/);
                                    p.first_name = parts[0] || '';
                                    p.last_name = parts.slice(1).join(' ') || '';
                                }
                            });
                        }

                        if (this.form.contact_middle_name === undefined) this.form.contact_middle_name = '';
                        if (this.form.contact_no_middle_name === undefined) this.form.contact_no_middle_name = false;
                        if (this.form.contact_suffix === undefined) this.form.contact_suffix = '';
                        if (!this.form.contact_first_name && this.form.contact_name) {
                            const parts = this.form.contact_name.trim().split(/\s+/);
                            this.form.contact_first_name = parts[0] || '';
                            this.form.contact_last_name = parts.slice(1).join(' ') || '';
                        }

                        if (this.form.selected_lead_participant === undefined) {
                            this.form.selected_lead_participant = 0;
                        }
                        if (this.form.participants.length === 1 || this.form.selected_lead_participant !== 'custom') {
                            this.syncLeadContactFromParticipant();
                        }

                        if (this.form.start_date) {
                            const p = this.form.start_date.split('-');
                            if (p.length === 3) {
                                this.calendarYear = parseInt(p[0], 10);
                                this.calendarMonth = parseInt(p[1], 10) - 1;
                            }
                            this.onStartDateChange();
                        }

                        if (data.currentStep && data.currentStep >= 1 && data.currentStep < 5) {
                            this.currentStep = data.currentStep;
                        }

                        if (hasDetails) {
                            this.draftRestored = true;
                        }
                    }
                }
            } catch (e) {
                console.warn('Booking draft load error:', e);
            }
        },

        clearDraft() {
            try {
                localStorage.removeItem('camp_freedive_booking_draft');
            } catch (e) {}
        },

        resetForm() {
            this.clearDraft();
            this.draftRestored = false;
            this.currentStep = 1;
            this.form = {
                class_type: config.initialClass || 'discovery',
                is_certified_diver: false,
                discovery_completed_ack: false,
                start_date: '',
                end_date: '',
                participants: [
                    { first_name: '', middle_name: '', no_middle_name: false, last_name: '', suffix: '', name: '', birthdate: '', gender: '', age: '', health_condition: '', swimmer_status: 'non_swimmer' }
                ],
                selected_lead_participant: 0,
                contact_first_name: '',
                contact_middle_name: '',
                contact_no_middle_name: false,
                contact_last_name: '',
                contact_suffix: '',
                contact_name: '',
                contact_email: '',
                contact_phone: '',
                contact_facebook: '',
                pickup_option: 'carpool',
                pickup_location: '',
                boat_dive: false,
                confirmation_ack: false,
                hasAgreedToTerms: false,
                payment_method: 'paymongo'
            };
            this.forecast = null;
            this.pricingQuote = null;
            this.errorMessage = '';
            this.touchedStep3 = false;
            this.touchedStep4 = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        onStartDateChange() {
            if (!this.form.start_date) {
                this.form.end_date = '';
                this.forecast = null;
                this.pricingQuote = null;
                return;
            }
            const parts = this.form.start_date.split('-');
            if (parts.length === 3) {
                const start = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                const end = new Date(start);
                end.setDate(start.getDate() + 1);
                const ey = end.getFullYear();
                const em = String(end.getMonth() + 1).padStart(2, '0');
                const ed = String(end.getDate()).padStart(2, '0');
                this.form.end_date = `${ey}-${em}-${ed}`;
            }
            this.fetchWeather();
            this.fetchPricingQuote();
        },

        calculateBasePriceUnit() {
            const basePrices = (config.pricingConfig && config.pricingConfig.basePrices) ? config.pricingConfig.basePrices : {
                discovery: 4250,
                fundive_cert: 2500,
                fundive_noncert: 3300,
                refinement: 4100
            };
            let price = basePrices.discovery || 4250;
            if (this.form.class_type === 'fundive') {
                price = this.form.is_certified_diver ? (basePrices.fundive_cert || 2500) : (basePrices.fundive_noncert || 3300);
            } else if (this.form.class_type === 'refinement') {
                price = basePrices.refinement || 4100;
            }
            return price;
        },

        async fetchPricingQuote() {
            if (!this.form.start_date) return;
            try {
                const response = await fetch(config.pricingQuoteUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken
                    },
                    body: JSON.stringify({
                        class_type: this.form.class_type,
                        start_date: this.form.start_date,
                        is_certified_diver: this.form.is_certified_diver,
                        participants_count: this.form.participants.length
                    })
                });
                const data = await response.json();
                if (data && data.adjusted_price_per_pax) {
                    this.pricingQuote = data;
                }
            } catch (e) {
                console.warn('Pricing quote fetch error:', e);
            }
        },

        async fetchWeather() {
            this.weatherLoading = true;
            this.weatherProgress = 15;
            
            if (this.weatherProgressTimer) clearInterval(this.weatherProgressTimer);
            if (this.weatherTipTimer) clearInterval(this.weatherTipTimer);

            this.weatherProgressTimer = setInterval(() => {
                if (this.weatherProgress < 90) {
                    this.weatherProgress += Math.floor(Math.random() * 15) + 5;
                    if (this.weatherProgress > 90) this.weatherProgress = 90;
                }
            }, 200);

            this.weatherTipTimer = setInterval(() => {
                this.weatherTipIndex = (this.weatherTipIndex + 1) % this.weatherTips.length;
            }, 2500);

            try {
                const response = await fetch(config.checkWeatherUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken
                    },
                    body: JSON.stringify({
                        start_date: this.form.start_date,
                        end_date: this.form.end_date
                    })
                });
                const data = await response.json();
                this.weatherProgress = 100;
                this.forecast = data;
            } catch (e) {
                console.error(e);
            } finally {
                clearInterval(this.weatherProgressTimer);
                clearInterval(this.weatherTipTimer);
                setTimeout(() => {
                    this.weatherLoading = false;
                }, 300);
            }
        },

        addParticipant() {
            this.form.participants.push({
                first_name: '',
                middle_name: '',
                no_middle_name: false,
                last_name: '',
                suffix: '',
                name: '',
                age: '',
                birthdate: '',
                gender: '',
                health_condition: '',
                swimmer_status: 'non_swimmer'
            });
        },

        assembleParticipantName(p) {
            const parts = [
                p.first_name || '',
                (!p.no_middle_name && p.middle_name) ? p.middle_name : '',
                p.last_name || '',
                p.suffix || ''
            ].filter(s => s.trim().length > 0);
            p.name = parts.join(' ');

            const pIdx = this.form.participants.indexOf(p);
            if (this.form.participants.length === 1 || parseInt(this.form.selected_lead_participant, 10) === pIdx) {
                this.syncLeadContactFromParticipant();
            }
            return p.name;
        },

        assembleContactName() {
            const parts = [
                this.form.contact_first_name || '',
                (!this.form.contact_no_middle_name && this.form.contact_middle_name) ? this.form.contact_middle_name : '',
                this.form.contact_last_name || '',
                this.form.contact_suffix || ''
            ].filter(s => s.trim().length > 0);
            this.form.contact_name = parts.join(' ');
            return this.form.contact_name;
        },

        syncLeadContactFromParticipant() {
            if (this.form.participants.length === 1) {
                this.form.selected_lead_participant = 0;
            }
            if (this.form.selected_lead_participant === 'custom') {
                return;
            }
            const idx = parseInt(this.form.selected_lead_participant, 10);
            if (!isNaN(idx) && this.form.participants[idx]) {
                const p = this.form.participants[idx];
                this.form.contact_first_name = p.first_name || '';
                this.form.contact_middle_name = p.middle_name || '';
                this.form.contact_no_middle_name = !!p.no_middle_name;
                this.form.contact_last_name = p.last_name || '';
                this.form.contact_suffix = p.suffix || '';
                this.assembleContactName();
            }
        },

        onLeadParticipantChange() {
            if (this.form.selected_lead_participant === 'custom') {
                return;
            }
            this.syncLeadContactFromParticipant();
        },

        removeParticipant(index) {
            if (this.form.participants.length > 1) {
                this.form.participants.splice(index, 1);
                if (this.form.participants.length === 1 || this.form.selected_lead_participant === index) {
                    this.form.selected_lead_participant = 0;
                    this.syncLeadContactFromParticipant();
                } else if (this.form.selected_lead_participant > index && this.form.selected_lead_participant !== 'custom') {
                    this.form.selected_lead_participant--;
                }
            }
        },

        calculateSubtotal() {
            const count = this.form.participants.length;
            if (this.pricingQuote && this.pricingQuote.adjusted_price_per_pax) {
                return this.pricingQuote.adjusted_price_per_pax * count;
            }
            return this.calculateBasePriceUnit() * count;
        },

        calculateCarpoolFee() {
            const carpoolFee = (config.pricingConfig && config.pricingConfig.fees && config.pricingConfig.fees.carpool) ? config.pricingConfig.fees.carpool : 1200;
            if (this.form.pickup_option === 'carpool') {
                return carpoolFee * this.form.participants.length;
            }
            return 0;
        },

        calculateBoatFee() {
            const boatFee = (config.pricingConfig && config.pricingConfig.fees && config.pricingConfig.fees.boat_dive) ? config.pricingConfig.fees.boat_dive : 600;
            return this.form.boat_dive ? (boatFee * this.form.participants.length) : 0;
        },

        calculateLguFee() {
            const lguFee = (config.pricingConfig && config.pricingConfig.fees && config.pricingConfig.fees.lgu_pass) ? config.pricingConfig.fees.lgu_pass : 300;
            return lguFee * this.form.participants.length;
        },

        calculateEnvironmentalFee() {
            const envFee = (config.pricingConfig && config.pricingConfig.fees && config.pricingConfig.fees.environmental) ? config.pricingConfig.fees.environmental : 50;
            return envFee * this.form.participants.length;
        },

        calculateLguAndEnvFee() {
            return this.calculateLguFee() + this.calculateEnvironmentalFee();
        },

        calculateTotal() {
            const subtotal = this.calculateSubtotal();
            const carpool = this.calculateCarpoolFee();
            const boat = this.calculateBoatFee();
            const lguAndEnv = this.calculateLguAndEnvFee();
            return subtotal + carpool + boat + lguAndEnv;
        },

        calculateDownpayment() {
            const total = this.calculateTotal();
            const count = this.form.participants.length;
            const carpoolDp = (config.pricingConfig && config.pricingConfig.downpayments && config.pricingConfig.downpayments.carpool) ? config.pricingConfig.downpayments.carpool : 3000;
            const ownTranspoDp = (config.pricingConfig && config.pricingConfig.downpayments && config.pricingConfig.downpayments.own_transpo) ? config.pricingConfig.downpayments.own_transpo : 2000;
            const dpPerHead = (this.form.pickup_option === 'carpool') ? carpoolDp : ownTranspoDp;
            const totalDp = dpPerHead * count;
            return Math.min(totalDp, total);
        },

        formatNumber(num) {
            return (num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        getSafetyScore(classification) {
            switch (classification) {
                case 'Very Safe': return 5;
                case 'Safe': return 4;
                case 'Moderate': return 3;
                case 'High Risk': return 2;
                case 'Critical Risk': return 1;
                default: return 4;
            }
        },

        getSafetyBarClass(classification) {
            switch (classification) {
                case 'Very Safe':
                case 'Safe':
                    return 'bg-[#10B981]';
                case 'Moderate':
                    return 'bg-[#F59E0B]';
                case 'High Risk':
                    return 'bg-[#F43F5E]';
                case 'Critical Risk':
                    return 'bg-[#EF4444]';
                default:
                    return 'bg-[#10B981]';
            }
        },

        getSafetyBorderClass(classification) {
            switch (classification) {
                case 'Very Safe':
                case 'Safe':
                    return 'border-[#10B981]';
                case 'Moderate':
                    return 'border-[#F59E0B]';
                case 'High Risk':
                    return 'border-[#F43F5E]';
                case 'Critical Risk':
                    return 'border-[#EF4444]';
                default:
                    return 'border-[#10B981]';
            }
        },

        getSafetyTextClass(classification) {
            switch (classification) {
                case 'Very Safe':
                case 'Safe':
                    return 'text-[#10B981]';
                case 'Moderate':
                    return 'text-[#F59E0B]';
                case 'High Risk':
                    return 'text-[#F43F5E]';
                case 'Critical Risk':
                    return 'text-[#EF4444]';
                default:
                    return 'text-[#10B981]';
            }
        },

        touchedStep3: false,
        touchedStep4: false,

        cleanNameInput(val) {
            if (!val) return '';
            return val.toString().replace(/[^\p{L}\s.'-]/gu, '');
        },

        validateName(name) {
            if (!name) return false;
            const trimmed = name.toString().trim();
            return trimmed.length >= 2 && /^[\p{L}\s.'-]+$/u.test(trimmed);
        },

        validateAge(age) {
            if (age === '' || age === null || age === undefined) return false;
            const a = parseInt(age, 10);
            return !isNaN(a) && a >= 8 && a <= 85;
        },

        calculateAge(participant) {
            if (!participant.birthdate) return '';
            const birthdate = new Date(participant.birthdate + 'T00:00:00');
            const today = new Date();
            let age = today.getFullYear() - birthdate.getFullYear();
            if (today < new Date(today.getFullYear(), birthdate.getMonth(), birthdate.getDate())) age--;
            participant.age = age;
            return age;
        },

        validateEmail(email) {
            if (!email) return false;
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.toString().trim());
        },

        validatePhone(phone) {
            if (!phone) return false;
            const digits = phone.toString().replace(/\D/g, '');
            return (digits.length === 12 && digits.startsWith('639')) ||
                   (digits.length === 11 && digits.startsWith('09')) ||
                   (digits.length === 10 && digits.startsWith('9'));
        },

        nextStep() {
            this.errorMessage = '';

            if (this.currentStep === 1) {
                if (!this.form.class_type) {
                    this.errorMessage = "Please select a freediving class to proceed.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
            } else if (this.currentStep === 2) {
                if (!this.form.start_date) {
                    this.errorMessage = "Please select your preferred trip start date to continue.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (this.weatherLoading) {
                    this.errorMessage = "Please wait while we evaluate the weather & marine safety for your dates.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (this.forecast && !this.forecast.is_bookable) {
                    this.errorMessage = "The selected dive date has a Critical Risk. Please choose an alternate safe date.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
            } else if (this.currentStep === 3) {
                this.touchedStep3 = true;
                for (let i = 0; i < this.form.participants.length; i++) {
                    const p = this.form.participants[i];
                    this.assembleParticipantName(p);
                    if (!p.first_name || !p.first_name.trim()) {
                        this.errorMessage = `Please enter the First Name for Participant #${i + 1}.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!this.validateName(p.first_name)) {
                        this.errorMessage = `Participant #${i + 1} first name must contain letters only (minimum 2 characters).`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!p.no_middle_name && p.middle_name && !this.validateName(p.middle_name)) {
                        this.errorMessage = `Participant #${i + 1} middle name must contain letters only.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!p.last_name || !p.last_name.trim()) {
                        this.errorMessage = `Please enter the Last Name for Participant #${i + 1}.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!this.validateName(p.last_name)) {
                        this.errorMessage = `Participant #${i + 1} last name must contain letters only (minimum 2 characters).`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    const calculatedAge = this.calculateAge(p);
                    if (!p.birthdate) {
                        this.errorMessage = `Please enter the Birthdate for Participant #${i + 1}.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!this.validateAge(calculatedAge)) {
                        this.errorMessage = `Participant #${i + 1} must be between 8 and 85 years old.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                    if (!p.gender) {
                        this.errorMessage = `Please select the Gender for Participant #${i + 1}.`;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }
                }
                this.assembleContactName();
                if (!this.form.contact_first_name || !this.form.contact_first_name.trim()) {
                    this.errorMessage = "Please enter the Primary Contact First Name.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.validateName(this.form.contact_first_name)) {
                    this.errorMessage = "Primary Contact First Name must contain letters only.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.contact_no_middle_name && this.form.contact_middle_name && !this.validateName(this.form.contact_middle_name)) {
                    this.errorMessage = "Primary Contact Middle Name must contain letters only.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.contact_last_name || !this.form.contact_last_name.trim()) {
                    this.errorMessage = "Please enter the Primary Contact Last Name.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.validateName(this.form.contact_last_name)) {
                    this.errorMessage = "Primary Contact Last Name must contain letters only.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.contact_email || !this.form.contact_email.trim()) {
                    this.errorMessage = "Please enter the Primary Contact Email Address.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.validateEmail(this.form.contact_email)) {
                    this.errorMessage = "Please provide a valid email address format (e.g. name@example.com).";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.contact_phone || !this.form.contact_phone.trim()) {
                    this.errorMessage = "Please enter the Primary Mobile Phone Number.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.validatePhone(this.form.contact_phone)) {
                    this.errorMessage = "Please enter a valid 11-digit Philippine Mobile Number (e.g. 09171234567 or +639171234567).";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (this.form.pickup_option === 'carpool' && !this.form.pickup_location) {
                    this.errorMessage = "Please select your preferred Carpool Pickup Hub & Schedule to continue.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.confirmation_ack) {
                    this.errorMessage = "Please confirm that all information provided is accurate.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (!this.form.hasAgreedToTerms) {
                    this.errorMessage = "Please read and agree to the Terms & Conditions and Privacy Policy.";
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                if (this.form.class_type === 'fundive' || this.form.class_type === 'refinement') {
                    this.form.discovery_completed_ack = true;
                }
                this.startPaymentTimer();
            }

            this.currentStep++;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        prevStep() {
            this.errorMessage = '';
            if (this.currentStep > 1) {
                this.currentStep--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        goToStep(step) {
            if (this.currentStep === 5) return;
            if (step < this.currentStep && step >= 1) {
                this.errorMessage = '';
                this.currentStep = step;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        startPaymentTimer() {
            if (this.timerInterval) clearInterval(this.timerInterval);
            this.timerSeconds = 15 * 60;
            this.timerInterval = setInterval(() => {
                this.timerSeconds--;
                const m = Math.floor(this.timerSeconds / 60);
                const s = this.timerSeconds % 60;
                this.timerDisplay = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;

                if (this.timerSeconds <= 0) {
                    clearInterval(this.timerInterval);
                    this.sessionExpired = true;
                }
            }, 1000);
        },

        restartSession() {
            if (this.timerInterval) clearInterval(this.timerInterval);
            this.clearDraft();
            window.location.reload();
        },

        async processPayment(instantSimulation = false, hostedCheckout = true) {
            this.submittingPayment = true;
            this.errorMessage = '';

            try {
                const payload = {
                    ...this.form,
                    has_agreed_to_terms: this.form.hasAgreedToTerms,
                    payment_method: 'paymongo'
                };

                const response = await fetch(config.storeBookingUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken
                    },
                    body: JSON.stringify(payload)
                });

                let data;
                try {
                    data = await response.json();
                } catch (jsonErr) {
                    data = null;
                }

                if (response.ok && data && data.success) {
                    if (this.timerInterval) clearInterval(this.timerInterval);

                    // Clear draft on successful booking completion
                    this.clearDraft();
                    this.draftRestored = false;

                    // If live PayMongo checkout session was created, redirect directly to PayMongo
                    if (data.is_paymongo_redirect && data.checkout_url && !instantSimulation) {
                        window.location.href = data.checkout_url;
                        return;
                    }

                    // Otherwise show confirmation step (Step 5)
                    this.confirmedBooking = data;
                    this.currentStep = 5;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    let errMsg = "Payment processing error. Please check your details and try again.";
                    if (data) {
                        if (data.message) {
                            errMsg = data.message;
                        } else if (data.errors) {
                            errMsg = Object.values(data.errors).flat().join(" ");
                        }
                    }
                    this.errorMessage = errMsg;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            } catch (e) {
                console.error("Booking error:", e);
                this.errorMessage = "An unexpected error occurred while communicating with the booking server. Please check your connection and try again.";
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } finally {
                this.submittingPayment = false;
            }
        },

        copyCredentials() {
            const text = `Camp FreedivePH Booking\nBooking #: ${this.confirmedBooking.booking_number}\nPIN: ${this.confirmedBooking.pin}\nManage: ${this.confirmedBooking.manage_url}`;
            navigator.clipboard.writeText(text).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 3000);
            });
        }
    };
}
window.bookingWizard = bookingForm;
</script>
@endpush
