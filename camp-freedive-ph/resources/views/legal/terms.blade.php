@extends('layouts.app')

@section('title', 'Terms and Conditions | Camp FreedivePH')
@section('meta_description', 'Read Camp FreedivePH terms and conditions of service, booking policies, weather safety guidelines, and participant terms.')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-16 text-sm text-[#1D1D1F] leading-relaxed relative"
     x-data="{
         showBackToTop: false,
         activeSection: '',
         scrollToSection(id) {
             this.activeSection = id;
             const target = document.getElementById(id);
             if (target) {
                 target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                 try { history.pushState(null, '', '#' + id); } catch(e) {}
             }
         },
         scrollToTop() {
             window.scrollTo({ top: 0, behavior: 'smooth' });
             try { history.pushState(null, '', window.location.pathname); } catch(e) {}
         }
     }"
     @scroll.window="showBackToTop = (window.pageYOffset > 400)">

    <!-- Legal Header -->
    <header class="space-y-4 pb-8 border-b border-[#E5E5EA]">
        <div class="flex items-center gap-2 text-xs font-semibold text-[#636366] uppercase tracking-wider">
            <a href="{{ route('landing') }}" class="min-h-[44px] inline-flex items-center hover:text-[#1D1D1F] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] rounded-md">Home</a>
            <span>/</span>
            <span>Legal</span>
        </div>

        <h1 class="text-3xl sm:text-5xl font-extrabold text-[#1D1D1F] tracking-tight">
            Terms and Conditions of Service
        </h1>

        <p class="text-xs sm:text-sm text-[#636366]">
            Last Updated: September 26, 2026
        </p>

        <!-- Document Switcher -->
        <nav class="pt-2" aria-label="Legal documents">
            <div class="inline-flex items-center p-1 bg-[#F2F2F7] rounded-xl text-sm font-semibold">
                <span class="px-4 py-2 bg-white text-[#1D1D1F] rounded-lg shadow-2xs">Terms &amp; Conditions</span>
                <div class="w-px h-5 bg-[#E5E5EA] mx-0.5 shrink-0"></div>
                <a href="{{ route('legal.privacy') }}" 
                   class="min-h-[44px] px-4 py-2 inline-flex items-center text-[#636366] hover:text-[#1D1D1F] transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    Privacy Policy
                </a>
            </div>
        </nav>
    </header>

    <!-- Two-Column Layout: Left Sticky TOC, Right Content -->
    <div class="mt-8 sm:mt-12 lg:grid lg:grid-cols-[280px_1fr] xl:grid-cols-[320px_1fr] gap-8 lg:gap-12 items-start">
        
        <!-- LEFT: Table of Contents (Sticky on desktop, no border, no line, no arrows) -->
        <aside class="lg:sticky lg:top-24 space-y-4 mb-8 lg:mb-0" aria-label="Table of Contents">
            <div class="p-5 sm:p-6 bg-[#F2F2F7] rounded-2xl max-h-[calc(100vh-7rem)] overflow-y-auto">
                <h2 class="text-xs font-bold uppercase tracking-wider text-[#636366] mb-3">Table of Contents</h2>
                
                <ol class="space-y-1 text-xs sm:text-sm text-[#1D1D1F]">
                    <li>
                        <a href="#section-1" @click.prevent="scrollToSection('section-1')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            1. Use of the Services
                        </a>
                    </li>
                    <li>
                        <a href="#section-2" @click.prevent="scrollToSection('section-2')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            2. Intellectual Property and Content
                        </a>
                    </li>
                    <li>
                        <a href="#section-3" @click.prevent="scrollToSection('section-3')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            3. Bookings, Pricing, and Payment
                        </a>
                    </li>
                    <li>
                        <a href="#section-4" @click.prevent="scrollToSection('section-4')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            4. Cancellations, Rescheduling, and Refunds
                        </a>
                    </li>
                    <li>
                        <a href="#section-5" @click.prevent="scrollToSection('section-5')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            5. Marine Operations, Weather, and Safety
                        </a>
                    </li>
                    <li>
                        <a href="#section-6" @click.prevent="scrollToSection('section-6')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            6. Assumption of Risk and Release
                        </a>
                    </li>
                    <li>
                        <a href="#section-7" @click.prevent="scrollToSection('section-7')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            7. Website and Digital Services
                        </a>
                    </li>
                    <li>
                        <a href="#section-8" @click.prevent="scrollToSection('section-8')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            8. Privacy and Personal Information
                        </a>
                    </li>
                    <li>
                        <a href="#section-9" @click.prevent="scrollToSection('section-9')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            9. Changes to These Terms
                        </a>
                    </li>
                    <li>
                        <a href="#section-10" @click.prevent="scrollToSection('section-10')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            10. Governing Law and Disputes
                        </a>
                    </li>
                    <li>
                        <a href="#section-11" @click.prevent="scrollToSection('section-11')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            11. General Provisions
                        </a>
                    </li>
                </ol>
            </div>
        </aside>

        <!-- RIGHT: Main Terms Content (No horizontal lines between sections, no banners, no badges) -->
        <main class="min-w-0 flex-1 space-y-10">

            <!-- Preamble -->
            <section class="space-y-3">
                <p class="text-base text-[#1D1D1F] font-normal leading-relaxed">
                    These Terms and Conditions (“Terms”) govern your access to and use of the Camp Freedive PH website, booking portal, and related services (“Services”). By accessing the website, submitting a booking, or selecting any button indicating acceptance, including “I Agree,” “Accept,” or “Confirm Booking,” you acknowledge that you have read, understood, and agreed to be bound by these Terms and our Privacy Policy.
                </p>
                <p class="text-sm text-[#3A3A3C]">
                    If you do not agree to these Terms, you must not access or use the booking portal or Services.
                </p>
            </section>

            <!-- 1. USE OF THE SERVICES -->
            <section id="section-1" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    1. USE OF THE SERVICES
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.1 Eligibility</h3>
                        <p>
                            You must be at least eighteen (18) years of age to make a booking independently. Participants between twelve (12) and seventeen (17) years of age may participate only with the written authorization and accompanied presence of a parent or legal guardian. By making a booking, you represent that you meet the applicable eligibility requirements and that all information you provide is truthful and accurate.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.2 Booking Information and Access</h3>
                        <p class="mb-2">
                            When making a booking or accessing the self-service booking portal, you are responsible for providing complete, accurate, and current information, including participant details, contact information, and emergency contact information.
                        </p>
                        <p>
                            You are responsible for maintaining the confidentiality of your booking reference number, personal identification information, and any other credentials or information used to access your booking. You must not share access information in a manner that may allow an unauthorized person to modify, access, or otherwise interfere with your booking.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.3 Acceptable Use</h3>
                        <p class="mb-2">
                            You may use the Services only for lawful purposes and in accordance with these Terms. You must not use automated systems, bots, scrapers, or other unauthorized means to access, collect, monitor, or reproduce information from the website or booking system. You must not attempt to bypass, disable, reverse engineer, interfere with, or gain unauthorized access to any part of the Services, including payment processing systems, booking functions, weather and safety assessment systems, application programming interfaces, or other technical components.
                        </p>
                        <p class="mb-2">
                            You must not submit fraudulent payment information, impersonate another person, introduce malicious code, interfere with the operation of the Services, or use the Services in a manner that may harm Camp Freedive PH, its personnel, participants, partners, or other users.
                        </p>
                        <p>
                            Harassment, abusive conduct, threats, or disruptive behavior toward instructors, safety personnel, boat crew, staff, or other participants is not permitted.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.4 Suspension and Termination</h3>
                        <p class="mb-2">
                            Camp Freedive PH may suspend, cancel, or restrict access to the Services or a participant’s booking where reasonably necessary to protect the safety, security, integrity, or proper operation of the Services, including where a user violates these Terms, provides fraudulent or inaccurate information, fails to comply with applicable safety requirements, or engages in disruptive or prohibited conduct.
                        </p>
                        <p>
                            Where circumstances permit, Camp Freedive PH may provide notice before taking such action. However, immediate action may be taken where necessary to protect participants, personnel, property, or the Services.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 2. INTELLECTUAL PROPERTY AND CONTENT -->
            <section id="section-2" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    2. INTELLECTUAL PROPERTY AND CONTENT
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">2.1 Ownership</h3>
                        <p class="mb-2">
                            The Services and their contents, including website designs, interfaces, source code, logos, trademarks, brand elements, training materials, photographs, videos, graphics, text, and other materials created or provided by Camp Freedive PH, are owned by or licensed to Camp Freedive PH and are protected by applicable intellectual property laws.
                        </p>
                        <p>
                            Except as expressly permitted by Camp Freedive PH, you may not reproduce, modify, distribute, publicly display, transmit, sell, license, scrape, or commercially exploit any portion of the Services or their contents without prior written authorization.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">2.2 User-Generated Content</h3>
                        <p class="mb-2">
                            You retain ownership of original photographs, videos, reviews, testimonials, comments, and other content that you independently create and submit to or share with Camp Freedive PH (“User Content”).
                        </p>
                        <p class="mb-2">
                            By voluntarily submitting User Content to Camp Freedive PH, or by publicly posting User Content and tagging or identifying Camp Freedive PH, you grant Camp Freedive PH a worldwide, non-exclusive, royalty-free license to use, reproduce, display, publish, distribute, and repost such User Content for promotional, website, social media, educational, and other legitimate business purposes.
                        </p>
                        <p>
                            This license does not transfer ownership of your User Content to Camp Freedive PH.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 3. BOOKINGS, PRICING, AND PAYMENT -->
            <section id="section-3" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    3. BOOKINGS, PRICING, AND PAYMENT
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.1 Booking and Reservation Deposit</h3>
                        <p class="mb-2">
                            A booking is subject to availability and is considered secured only upon successful submission and confirmation of the required reservation payment.
                        </p>
                        <p class="mb-2">
                            The standard reservation downpayment is <strong>Three Thousand Philippine Pesos (₱3,000.00) per participant</strong>, unless a different amount or payment arrangement is expressly indicated during the booking process.
                        </p>
                        <p>
                            Payments may be processed through the payment methods made available through our authorized payment processor, including GCash, Maya, credit or debit cards, and other supported online payment methods.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.2 Payment of Remaining Balance</h3>
                        <p class="mb-2">
                            Unless otherwise stated in the booking confirmation, any remaining balance shall be settled on-site upon arrival and before the applicable training session or orientation begins.
                        </p>
                        <p>
                            Failure to settle the required balance may result in the participant being unable to proceed with the scheduled activity.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.3 Prices and Additional Charges</h3>
                        <p class="mb-2">
                            All prices displayed through the Services are stated in Philippine Pesos (PHP), unless otherwise indicated. The applicable price may vary depending on the selected class, schedule, number of participants, applicable seasonal or demand-based pricing, transportation or boat arrangements, and other selected services or add-ons.
                        </p>
                        <p>
                            The applicable charges will be presented to the customer before the booking is confirmed.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.4 Booking Confirmation</h3>
                        <p class="mb-2">
                            Following successful payment, Camp Freedive PH may provide a digital booking confirmation containing the booking reference number, access credentials or PIN where applicable, schedule, participant information, payment details, and other relevant booking information.
                        </p>
                        <p>
                            Customers are responsible for reviewing their confirmation and promptly notifying Camp Freedive PH of any material error or discrepancy.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 4. CANCELLATIONS, RESCHEDULING, AND REFUNDS -->
            <section id="section-4" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    4. CANCELLATIONS, RESCHEDULING, AND REFUNDS
                </h2>
                <p class="text-sm font-semibold text-[#1D1D1F]">
                    14-Day Cancellation and Reschedule Policy
                </p>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.1 Customer Cancellation More Than Fourteen (14) Days Before the Scheduled Activity</h3>
                        <p class="mb-2">
                            Where a customer cancels more than fourteen (14) days before the scheduled activity, the customer may request a 100% downpayment refund or rescheduling, subject to availability.
                        </p>
                        <p>
                            Where rescheduling is selected, the reservation may be transferred to another available camp date within the permitted rescheduling period.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.2 Customer Cancellation Within Fourteen (14) Days</h3>
                        <p>
                            From seven (7) through fourteen (14) days before the scheduled activity, rescheduling is allowed, but cancellation is not allowed and the downpayment is non-refundable.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.3 Cancellation Within Seven (7) Days and No-Show</h3>
                        <p>
                            Within seven (7) days of the scheduled activity, cancellation and rescheduling are not allowed. The same condition applies to no-shows.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.4 Rescheduling</h3>
                        <p class="mb-2">
                            All requests for rescheduling are subject to availability and the applicable cancellation period. A rescheduled booking does not guarantee the availability of the original schedule, instructor, accommodation, transportation arrangement, or other booking components.
                        </p>
                        <p>
                            Any additional amount resulting from a difference in applicable rates, selected services, or other charges may be payable by the customer.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.5 Refund Processing</h3>
                        <p>
                            Where a refund is approved, the refund will be processed through the applicable payment method or in accordance with the refund procedure communicated by Camp Freedive PH. Processing times may vary depending on the payment provider and financial institution involved.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 5. MARINE OPERATIONS, WEATHER, AND SAFETY -->
            <section id="section-5" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    5. MARINE OPERATIONS, WEATHER, AND SAFETY
                </h2>
                <p class="text-sm font-semibold text-[#1D1D1F]">
                    Weather Safety and Force Majeure
                </p>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.1 Nature of Freediving Activities</h3>
                        <p class="mb-2">
                            Freediving and other open-water activities involve inherent risks associated with immersion, depth, pressure changes, currents, waves, weather conditions, marine life, boat transportation, equipment, and other environmental or operational circumstances.
                        </p>
                        <p>
                            Participation requires compliance with the instructions of Camp Freedive PH instructors, safety personnel, boat crew, and other authorized personnel.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.2 Participant Health and Fitness</h3>
                        <p class="mb-2">
                            Participants are responsible for determining whether they are physically capable of participating in freediving and related activities. Camp Freedive PH does not require a physician’s medical clearance unless otherwise specifically requested based on the circumstances of a participant or activity.
                        </p>
                        <p class="mb-2">
                            By participating, you represent that you have disclosed relevant information that may reasonably affect your ability to participate safely and that you will immediately inform the appropriate Camp Freedive PH personnel of any condition, injury, illness, medication, or other circumstance that may affect your safety during the activity.
                        </p>
                        <p>
                            Nothing in these Terms constitutes medical advice or a medical determination regarding a participant’s fitness to dive.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.3 Weather and Environmental Conditions</h3>
                        <p class="mb-2">
                            Freediving activities take place in an outdoor marine environment and may be affected by weather, tides, waves, currents, rainfall, wind, visibility, and other environmental conditions.
                        </p>
                        <p class="mb-2">
                            Rain, cloud cover, surface chop, or other moderate environmental conditions do not automatically require cancellation. Where conditions are assessed by the responsible instructors, boat crew, or authorized personnel to remain within acceptable operational and safety limits, the scheduled activity may proceed.
                        </p>
                        <p>
                            Participants acknowledge that environmental conditions may change before or during an activity and that operational decisions may be adjusted accordingly.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.4 Weather and Safety Assessment</h3>
                        <p class="mb-2">
                            Camp Freedive PH may use weather information, environmental observations, forecasts, government advisories, and its internal weather and safety assessment system to support operational decisions.
                        </p>
                        <p class="mb-2">
                            Forecasts and risk assessments are provided for operational and informational purposes and are not guarantees of future weather or marine conditions. Actual conditions may differ from forecasted conditions.
                        </p>
                        <p>
                            Camp Freedive PH may modify, delay, reschedule, suspend, or cancel an activity when environmental conditions or official advisories indicate that continuing the activity may not be appropriate.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.5 Government Advisories and Force Majeure</h3>
                        <p class="mb-2">
                            Where a government agency or competent authority issues a mandatory suspension of sea travel or an advisory that prevents the scheduled marine activity from safely proceeding, Camp Freedive PH may suspend or cancel the affected activity.
                        </p>
                        <p class="mb-2">
                            This may include circumstances involving tropical cyclones, severe weather, gale warnings, dangerous sea conditions, or other events beyond the reasonable control of Camp Freedive PH.
                        </p>
                        <p>
                            Where an activity is cancelled for such reasons, the applicable booking may be transferred to an available alternative schedule or otherwise handled in accordance with the applicable force majeure and cancellation policy communicated by Camp Freedive PH.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 6. ASSUMPTION OF RISK AND RELEASE -->
            <section id="section-6" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    6. ASSUMPTION OF RISK AND RELEASE
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.1 Voluntary Participation</h3>
                        <p class="mb-2">
                            By participating in a freediving camp or related activity, you acknowledge that participation is voluntary and that open-water activities involve inherent risks that cannot be completely eliminated even when reasonable safety procedures are followed.
                        </p>
                        <p>
                            You agree to follow all safety instructions, operational requirements, and reasonable directions provided by Camp Freedive PH personnel and authorized service providers.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.2 Liability</h3>
                        <p class="mb-2">
                            To the maximum extent permitted by applicable Philippine law, Camp Freedive PH, its owners, officers, employees, instructors, safety personnel, boat crew, and authorized representatives shall not be liable for indirect, incidental, special, punitive, or consequential damages arising from the use of the Services or participation in activities, except to the extent that such limitation is prohibited by law.
                        </p>
                        <p class="mb-2">
                            Participation may also be subject to a separate liability release, waiver, acknowledgment, or other safety document that must be completed before participating in applicable in-water activities.
                        </p>
                        <p>
                            Nothing in these Terms is intended to exclude or limit liability that cannot lawfully be excluded or limited under applicable law.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 7. WEBSITE AND DIGITAL SERVICES -->
            <section id="section-7" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    7. WEBSITE AND DIGITAL SERVICES
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.1 Availability of the Services</h3>
                        <p class="mb-2">
                            The website, booking portal, booking calendar, digital confirmation system, and related features are provided on an “as is” and “as available” basis.
                        </p>
                        <p>
                            Camp Freedive PH does not guarantee that the Services will always be available, uninterrupted, secure, accurate, or free from errors. Temporary interruptions may occur due to maintenance, technical issues, internet connectivity, third-party services, payment providers, or circumstances beyond our reasonable control.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.2 Forecast and Booking Information</h3>
                        <p class="mb-2">
                            Information displayed through the Services, including availability, prices, schedules, weather information, forecasts, and safety assessments, may change as operational conditions change.
                        </p>
                        <p>
                            Camp Freedive PH reserves the right to correct errors, update information, and modify operational details where reasonably necessary.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.3 Third-Party Services</h3>
                        <p>
                            Certain features of the Services may depend on third-party services, including payment providers, communication services, mapping services, weather data providers, or other external systems. Camp Freedive PH is not responsible for interruptions, errors, or failures attributable solely to a third-party service. Your use of such services may also be subject to the third party’s own terms and policies.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 8. PRIVACY AND PERSONAL INFORMATION -->
            <section id="section-8" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    8. PRIVACY AND PERSONAL INFORMATION
                </h2>
                <div class="space-y-3 text-[#3A3A3C]">
                    <p>
                        Camp Freedive PH collects and processes personal information necessary to provide booking, payment, communication, safety, operational, and related services.
                    </p>
                    <p>
                        The collection, use, storage, and disclosure of personal information are governed by our <a href="{{ route('legal.privacy') }}" class="text-[#780000] font-bold underline hover:text-[#500000]">Privacy Policy</a> and applicable Philippine data protection laws.
                    </p>
                    <p>
                        By using the Services, you acknowledge that you have been informed of the applicable privacy practices and agree to the processing of your personal information as described in the Privacy Policy.
                    </p>
                </div>
            </section>

            <!-- 9. CHANGES TO THESE TERMS -->
            <section id="section-9" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    9. CHANGES TO THESE TERMS
                </h2>
                <div class="space-y-3 text-[#3A3A3C]">
                    <p>
                        Camp Freedive PH may update or modify these Terms from time to time to reflect changes to the Services, operational practices, applicable requirements, or other circumstances.
                    </p>
                    <p>
                        Any revised Terms will become effective upon publication through the website unless a different effective date is stated. Your continued use of the Services after the revised Terms become effective constitutes your acknowledgment of the updated Terms.
                    </p>
                    <p>
                        The version of these Terms in effect at the time of your booking will generally govern that booking, unless otherwise required by law or expressly stated in the revised Terms.
                    </p>
                </div>
            </section>

            <!-- 10. GOVERNING LAW AND DISPUTES -->
            <section id="section-10" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    10. GOVERNING LAW AND DISPUTES
                </h2>
                <div class="space-y-3 text-[#3A3A3C]">
                    <p>
                        These Terms and any dispute arising out of or relating to the Services, bookings, or participation in Camp Freedive PH activities shall be governed by and construed in accordance with the laws of the <strong>Republic of the Philippines</strong>, without regard to conflict-of-law principles.
                    </p>
                    <p>
                        The parties shall endeavor to resolve disputes through good-faith communication before pursuing formal legal proceedings.
                    </p>
                    <p>
                        Subject to applicable Philippine law and jurisdictional requirements, any legal action or proceeding arising from these Terms shall be brought before a court of competent jurisdiction in the appropriate venue.
                    </p>
                </div>
            </section>

            <!-- 11. GENERAL PROVISIONS -->
            <section id="section-11" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    11. GENERAL PROVISIONS
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.1 Entire Agreement</h3>
                        <p>
                            These Terms, together with the Privacy Policy, applicable booking policies, waivers, and other terms expressly incorporated into the Services, constitute the agreement between you and Camp Freedive PH concerning your use of the Services and participation in the applicable activities.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.2 Severability</h3>
                        <p>
                            If any provision of these Terms is determined to be invalid, unlawful, or unenforceable, that provision shall be enforced to the maximum extent permitted by law, and the remaining provisions shall remain in full force and effect.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.3 No Waiver</h3>
                        <p>
                            The failure of Camp Freedive PH to enforce any provision of these Terms shall not constitute a waiver of its right to enforce that provision or any other provision in the future.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.4 Assignment</h3>
                        <p>
                            You may not assign or transfer your rights or obligations under these Terms without the prior written consent of Camp Freedive PH. Camp Freedive PH may assign or transfer its rights and obligations where permitted by applicable law.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.5 Contact Information</h3>
                        <p class="mb-3">
                            If you have questions regarding these Terms, your booking, or the Services, you may contact Camp Freedive PH through the official contact information provided on the website.
                        </p>
                        
                        <div class="space-y-1.5 text-sm text-[#3A3A3C]">
                            <p class="font-bold text-[#1D1D1F]">Camp Freedive PH</p>
                            <p>Barangay Bagalangit, Mabini, Batangas, Philippines</p>
                            <p><span class="font-medium text-[#636366]">Facebook:</span> <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@Campfreediveph</a></p>
                            <p><span class="font-medium text-[#636366]">Instagram:</span> <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@campfreediveph</a></p>
                            <p><span class="font-medium text-[#636366]">Email:</span> <a href="mailto:campfreediveph@gmail.com" class="text-[#780000] font-bold underline hover:text-[#500000]">campfreediveph@gmail.com</a></p>
                            <p><span class="font-medium text-[#636366]">Phone:</span> <a href="tel:+639278879894" class="text-[#780000] font-bold hover:underline">+63 927 887 9894</a></p>
                        </div>

                        <p class="text-xs text-[#8E8E93] pt-4">
                            <strong>Last Updated:</strong> September 26, 2026
                        </p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Floating Back to Top Button -->
    <button type="button"
            x-show="showBackToTop"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-3 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-3 scale-95"
            @click="scrollToTop()"
            aria-label="Back to top"
            class="fixed bottom-6 right-6 z-40 p-2.5 sm:px-3.5 sm:py-2 rounded-2xl bg-white/90 hover:bg-white text-[#1D1D1F] shadow-lg backdrop-blur-md border border-[#E5E5EA]/80 flex items-center gap-2 cursor-pointer transition-all active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
        <img src="{{ asset('icons/icons8-up-squared-60.png') }}" class="w-5 h-5 object-contain" alt="" aria-hidden="true">
        <span class="text-xs font-bold hidden sm:inline">Back to top</span>
    </button>
</div>
@endsection
