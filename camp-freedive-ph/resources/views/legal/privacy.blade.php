@extends('layouts.app')

@section('title', 'Privacy Policy | Camp FreedivePH')
@section('meta_description', 'Learn how Camp Freedive PH protects and manages your personal data in compliance with Republic Act No. 10173 (Data Privacy Act of 2012) and National Privacy Commission regulations.')

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
            Privacy Policy
        </h1>

        <div class="space-y-1 text-xs sm:text-sm text-[#636366]">
            <p><strong>Last Updated:</strong> September 26, 2026</p>
            <p class="text-xs text-[#8E8E93]">
                <strong>Compliance:</strong> Republic Act No. 10173, also known as the Data Privacy Act of 2012, and applicable rules, regulations, and issuances of the National Privacy Commission (NPC)
            </p>
        </div>

        <!-- Document Switcher -->
        <nav class="pt-2" aria-label="Legal documents">
            <div class="inline-flex items-center p-1 bg-[#F2F2F7] rounded-xl text-sm font-semibold">
                <a href="{{ route('legal.terms') }}" 
                   class="min-h-[44px] px-4 py-2 inline-flex items-center text-[#636366] hover:text-[#1D1D1F] transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    Terms &amp; Conditions
                </a>
                <div class="w-px h-5 bg-[#E5E5EA] mx-0.5 shrink-0"></div>
                <span class="px-4 py-2 bg-white text-[#1D1D1F] rounded-lg shadow-2xs">Privacy Policy</span>
            </div>
        </nav>
    </header>

    <!-- Two-Column Layout: Left Sticky TOC, Right Content -->
    <div class="mt-8 sm:mt-12 lg:grid lg:grid-cols-[280px_1fr] xl:grid-cols-[320px_1fr] gap-8 lg:gap-12 items-start">
        
        <!-- Left: table of contents (sticky on desktop) -->
        <aside class="lg:sticky lg:top-24 space-y-4 mb-8 lg:mb-0" aria-label="Table of Contents">
            <div class="p-5 sm:p-6 bg-[#F2F2F7] rounded-2xl max-h-[calc(100vh-7rem)] overflow-y-auto">
                <h2 class="text-xs font-bold uppercase tracking-wider text-[#636366] mb-3">Table of Contents</h2>
                
                <ol class="space-y-1 text-xs sm:text-sm text-[#1D1D1F]">
                    <li>
                        <a href="#section-1" @click.prevent="scrollToSection('section-1')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            1. Information We Collect
                        </a>
                    </li>
                    <li>
                        <a href="#section-2" @click.prevent="scrollToSection('section-2')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            2. Purposes &amp; Legal Bases
                        </a>
                    </li>
                    <li>
                        <a href="#section-3" @click.prevent="scrollToSection('section-3')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            3. Disclosure &amp; Sharing
                        </a>
                    </li>
                    <li>
                        <a href="#section-4" @click.prevent="scrollToSection('section-4')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            4. Data Security
                        </a>
                    </li>
                    <li>
                        <a href="#section-5" @click.prevent="scrollToSection('section-5')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            5. Data Retention
                        </a>
                    </li>
                    <li>
                        <a href="#section-6" @click.prevent="scrollToSection('section-6')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            6. Your Rights
                        </a>
                    </li>
                    <li>
                        <a href="#section-7" @click.prevent="scrollToSection('section-7')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            7. Cookies &amp; Communications
                        </a>
                    </li>
                    <li>
                        <a href="#section-8" @click.prevent="scrollToSection('section-8')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            8. Children's Privacy
                        </a>
                    </li>
                    <li>
                        <a href="#section-9" @click.prevent="scrollToSection('section-9')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            9. International Data Processing
                        </a>
                    </li>
                    <li>
                        <a href="#section-10" @click.prevent="scrollToSection('section-10')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            10. Data Protection Contact
                        </a>
                    </li>
                    <li>
                        <a href="#section-11" @click.prevent="scrollToSection('section-11')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            11. Changes to This Policy
                        </a>
                    </li>
                    <li>
                        <a href="#section-12" @click.prevent="scrollToSection('section-12')" 
                           class="py-1.5 block font-medium text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            12. Contact Us
                        </a>
                    </li>
                </ol>
            </div>
        </aside>

        <!-- Right: privacy policy -->
        <main class="min-w-0 flex-1 space-y-10">

            <!-- Preamble -->
            <section class="space-y-3">
                <p class="text-base text-[#1D1D1F] font-normal leading-relaxed">
                    Camp Freedive PH (“Camp Freedive PH,” “we,” “us,” or “our”) respects your privacy and is committed to protecting your personal information. This Privacy Policy explains how we collect, use, store, disclose, and protect personal information when you access our website, use our booking portal, make a reservation, communicate with us, or participate in our dive training programs and related services.
                </p>
                <p class="text-sm text-[#3A3A3C]">
                    By using our website or submitting information through our booking services, you acknowledge that you have read and understood this Privacy Policy.
                </p>
            </section>

            <!-- 1. INFORMATION WE COLLECT -->
            <section id="section-1" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    1. INFORMATION WE COLLECT
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.1 Personal Information You Provide</h3>
                        <p class="mb-2">
                            We collect personal information that you voluntarily provide when making a reservation, managing a booking, communicating with us, or otherwise using our services. Depending on the service or transaction involved, this information may include your full name, mobile or other contact number, email address, emergency contact name and telephone number, equipment sizing information such as foot or fin size, height, and weight, and dietary restrictions or preferences where applicable to camp meal arrangements.
                        </p>
                        <p>
                            We may also collect information necessary to administer your booking, process payments, coordinate transportation or boat arrangements, communicate schedule information, and provide appropriate services during your participation in a dive camp.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.2 Technical and Usage Information</h3>
                        <p class="mb-2">
                            When you access our website or booking platform, certain technical information may be collected automatically. This may include your Internet Protocol (IP) address, browser type and user-agent information, referring URL, session information, and identifiers stored through cookies or similar technologies.
                        </p>
                        <p>
                            We use this information primarily to maintain website functionality, security, session continuity, and the proper operation of our booking workflow.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">1.3 How We Collect Personal Information</h3>
                        <p class="mb-2">
                            We collect personal information through information that you submit directly through our reservation and checkout forms, including the booking interface available through our website. We may also collect information when you access the self-service reservation management interface, communicate with us through our official customer service channels, or otherwise provide information necessary to fulfill your booking.
                        </p>
                        <p>
                            Our website may use session cookies and similar technologies that are necessary to maintain your booking session, preserve required security controls, and support the proper operation of the website.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 2. PURPOSES AND LEGAL BASES FOR PROCESSING -->
            <section id="section-2" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    2. PURPOSES AND LEGAL BASES FOR PROCESSING
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">2.1 Purposes of Processing</h3>
                        <p class="mb-2">
                            We process personal information only for legitimate and reasonably necessary purposes related to the operation of our services. These purposes include receiving, verifying, and confirming reservations; processing payments and related transactions; preparing participant and passenger information required for applicable maritime operations; preparing appropriately sized equipment; coordinating transportation and camp arrangements; communicating booking confirmations, receipts, schedules, and operational advisories; responding to participant inquiries; and coordinating appropriate emergency response where necessary.
                        </p>
                        <p>
                            We may also process personal information where necessary to comply with applicable laws, regulations, government requirements, lawful orders, or legitimate requests from competent authorities.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">2.2 Legal Basis</h3>
                        <p class="mb-2">
                            Depending on the circumstances, Camp Freedive PH may process personal information based on the performance of a contract or steps taken at your request before entering into a contract, compliance with a legal obligation, protection of vital interests, consent where consent is required, or other lawful bases recognized under applicable Philippine data protection laws.
                        </p>
                        <p>
                            Where consent is relied upon as the legal basis for processing, you may withdraw such consent subject to applicable legal and operational limitations. Withdrawal of consent does not affect the lawfulness of processing carried out before the withdrawal.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 3. DISCLOSURE AND SHARING OF PERSONAL INFORMATION -->
            <section id="section-3" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    3. DISCLOSURE AND SHARING OF PERSONAL INFORMATION
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.1 Service Providers and Payment Processors</h3>
                        <p class="mb-2">
                            We may disclose personal information to trusted third-party service providers where such disclosure is reasonably necessary to provide our services, process transactions, operate our systems, or fulfill our legal and operational obligations.
                        </p>
                        <p>
                            Payment transactions may be processed through authorized payment service providers, including PayMongo Philippines, Inc. Payment information is transmitted through the applicable payment provider's secure systems. Camp Freedive PH does not intentionally store full payment card numbers or payment account login credentials on its own booking servers.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.2 Maritime and Government Authorities</h3>
                        <p class="mb-2">
                            Where required for maritime operations, vessel clearance, passenger safety, or compliance with applicable requirements, participant information may be provided to the Philippine Coast Guard, relevant port authorities, or other competent government authorities.
                        </p>
                        <p>
                            The information disclosed will be limited to information reasonably necessary for the applicable legal, safety, or operational purpose.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.3 Emergency Situations</h3>
                        <p>
                            Where an emergency occurs during a dive camp or related activity, we may disclose relevant personal or emergency information to first responders, medical personnel, hospitals, clinics, emergency services, or other persons or organizations where reasonably necessary to protect the health or safety of the participant.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">3.4 No Sale of Personal Information</h3>
                        <p>
                            Camp Freedive PH does not sell or rent personal information to advertisers or marketers. We may disclose personal information only where reasonably necessary to provide our services, comply with legal obligations, protect safety or legitimate interests, or otherwise as permitted by applicable law.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 4. DATA SECURITY -->
            <section id="section-4" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    4. DATA SECURITY
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.1 Security Measures</h3>
                        <p>
                            Camp Freedive PH implements reasonable and appropriate organizational, physical, and technical measures designed to protect personal information against unauthorized access, alteration, disclosure, loss, destruction, or other unlawful processing. These measures may include encrypted communications through HTTPS and Transport Layer Security (TLS), access controls, authentication mechanisms, role-based access restrictions, secure handling of system credentials, and other safeguards appropriate to the nature of the information being processed.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.2 Access to Personal Information</h3>
                        <p class="mb-2">
                            Access to personal information is restricted to authorized personnel who require such information to perform legitimate operational, administrative, instructional, safety, or support functions.
                        </p>
                        <p>
                            Where third-party service providers process personal information on our behalf, we take reasonable measures to ensure that such processing is subject to appropriate contractual, organizational, and technical safeguards.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">4.3 Security Limitations</h3>
                        <p>
                            Although we take reasonable measures to protect personal information, no method of electronic transmission, storage, or processing can be guaranteed to be completely secure. We therefore cannot guarantee absolute security of personal information transmitted through or stored by our systems.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 5. DATA RETENTION -->
            <section id="section-5" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    5. DATA RETENTION
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.1 Retention Periods</h3>
                        <p class="mb-2">
                            Camp Freedive PH retains personal information only for as long as reasonably necessary to fulfill the purposes for which it was collected, provide our services, maintain appropriate business and transaction records, comply with applicable legal and regulatory obligations, resolve disputes, enforce agreements, and protect our legitimate interests.
                        </p>
                        <p>
                            The specific retention period may vary depending on the type of information and the purpose for which it was collected.
                        </p>
                        <p>
                            For participant records, Camp Freedive PH applies the following periods, counted from a participant's last dive and reset by any new booking:
                        </p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>Health notes and swimming level: cleared 12 months after the last dive.</li>
                            <li>Name, birthdate, contact details and booking history: anonymised 3 years after the last dive.</li>
                            <li>Parent or guardian consent for a participant under 18: kept with the booking for the same 3 years.</li>
                            <li>Payment and booking amounts: kept without personal details for as long as tax and accounting rules require.</li>
                        </ul>
                        <p>
                            A participant may ask for their record to be anonymised sooner by contacting Camp Freedive PH.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">5.2 Deletion and Disposal</h3>
                        <p class="mb-2">
                            When personal information is no longer necessary for its legitimate purpose and is not required to be retained under applicable law or other lawful obligations, we will take reasonable steps to securely delete, destroy, anonymize, or otherwise dispose of the information in accordance with our applicable retention and disposal procedures.
                        </p>
                        <p>
                            A request for deletion does not necessarily require immediate deletion where retention is required or permitted by law, necessary to establish or defend legal claims, necessary to comply with regulatory requirements, or otherwise justified under applicable data protection rules.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 6. YOUR RIGHTS -->
            <section id="section-6" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    6. YOUR RIGHTS
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.1 Rights Under the Data Privacy Act</h3>
                        <p class="mb-2">
                            Subject to the conditions and limitations provided under applicable Philippine law, you may exercise rights relating to your personal information, including the right to be informed about how your personal information is processed, the right to access personal information held about you, the right to dispute or correct inaccurate or incomplete information, and the right to object to or withdraw consent from certain processing activities where applicable.
                        </p>
                        <p>
                            You may also have the right to request the blocking, removal, or destruction of personal information under circumstances permitted by law and the right to seek damages where you have suffered injury as a result of unlawful or unauthorized processing.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.2 Requests for Access or Correction</h3>
                        <p class="mb-2">
                            If you believe that personal information we hold about you is inaccurate, incomplete, or outdated, you may request that the information be corrected or updated.
                        </p>
                        <p>
                            Where supported by our booking system, certain booking information may also be reviewed or updated through the self-service booking management interface. Requests involving information that cannot be modified through the portal may be submitted through our designated privacy contact channel.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.3 Requests for Deletion or Blocking</h3>
                        <p class="mb-2">
                            You may request the deletion, blocking, or removal of personal information where such request is permitted under applicable law.
                        </p>
                        <p>
                            We may retain information where retention is required by law, necessary for regulatory or accounting purposes, necessary to fulfill an existing contractual obligation, necessary to establish or defend legal claims, or otherwise permitted under applicable data protection requirements.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">6.4 Exercising Your Rights</h3>
                        <p>
                            To exercise your privacy rights, you may contact Camp Freedive PH through the privacy contact information provided in Section 10. We may take reasonable steps to verify your identity before processing certain requests in order to protect your personal information from unauthorized disclosure or modification.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 7. COOKIES AND COMMUNICATIONS -->
            <section id="section-7" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    7. COOKIES AND COMMUNICATIONS
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.1 Transactional Communications</h3>
                        <p class="mb-2">
                            We may send communications that are necessary to provide or administer your requested services. These may include booking confirmations, payment notifications, receipts, schedule reminders, operational announcements, weather-related advisories, cancellation or rescheduling notices, and other communications directly related to your booking or participation.
                        </p>
                        <p>
                            Because these communications may be necessary for service fulfillment or participant safety, they may continue even where you have opted out of promotional communications.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.2 Marketing Communications</h3>
                        <p>
                            Camp Freedive PH does not send unsolicited marketing communications. If promotional communications are introduced in the future, you will be provided with an appropriate means of opting out, including an unsubscribe mechanism where applicable.
                        </p>
                    </div>

                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">7.3 Cookies</h3>
                        <p class="mb-2">
                            Our website may use cookies and similar technologies to maintain sessions, support booking functionality, improve website security, and enable necessary website features.
                        </p>
                        <p>
                            You may configure your browser to restrict or disable certain cookies. However, disabling cookies that are necessary for the operation of the booking system may affect your ability to use certain website functions.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 8. CHILDREN'S PRIVACY -->
            <section id="section-8" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    8. CHILDREN'S PRIVACY
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">8.1 Use by Minors</h3>
                        <p class="mb-2">
                            Our services may be used by minors where participation is permitted under our applicable eligibility and safety requirements. Where a minor is permitted to participate, the required consent and involvement of a parent or legal guardian must be obtained.
                        </p>
                        <p class="mb-2">
                            We do not intentionally process personal information of minors outside the circumstances permitted by applicable law and our service requirements.
                        </p>
                        <p>
                            If you believe that personal information belonging to a minor has been submitted to us without the required authorization, please contact us using the information provided in Section 10 so that we can review the circumstances and take appropriate action.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 9. INTERNATIONAL DATA PROCESSING -->
            <section id="section-9" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    9. INTERNATIONAL DATA PROCESSING
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">9.1 Cloud and Third-Party Infrastructure</h3>
                        <p class="mb-2">
                            Certain aspects of our website, booking system, databases, or third-party services may be hosted or processed using infrastructure located outside the Philippines.
                        </p>
                        <p class="mb-2">
                            Where personal information is transferred to or processed by a service provider outside the Philippines, Camp Freedive PH will take reasonable measures to ensure that the processing is subject to appropriate data protection safeguards and applicable legal requirements.
                        </p>
                        <p>
                            Third-party providers may maintain their own security certifications, policies, and contractual safeguards. The specific location and processing arrangements may vary depending on the services used by Camp Freedive PH.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 10. DATA PROTECTION CONTACT -->
            <section id="section-10" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    10. DATA PROTECTION CONTACT
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">10.1 Privacy Inquiries</h3>
                        <p class="mb-3">
                            For questions regarding this Privacy Policy, requests to exercise your data privacy rights, or concerns regarding the processing of your personal information, you may contact Camp Freedive PH through the following channels:
                        </p>

                        <div class="space-y-1.5 text-sm text-[#3A3A3C]">
                            <p class="font-bold text-[#1D1D1F]">Camp Freedive PH – Data Privacy Desk</p>
                            <p>Barangay Bagalangit, Mabini, Batangas, Philippines</p>
                            <p><span class="font-medium text-[#636366]">Facebook:</span> <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@Campfreediveph</a></p>
                            <p><span class="font-medium text-[#636366]">Instagram:</span> <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@campfreediveph</a></p>
                            <p><span class="font-medium text-[#636366]">Email:</span> <a href="mailto:privacy@campfreedive.ph" class="text-[#780000] font-bold underline hover:text-[#500000]">privacy@campfreedive.ph</a> / <a href="mailto:campfreediveph@gmail.com" class="text-[#780000] font-bold underline hover:text-[#500000]">campfreediveph@gmail.com</a></p>
                            <p><span class="font-medium text-[#636366]">Phone:</span> <a href="tel:+639278879894" class="text-[#780000] font-bold hover:underline">+63 927 887 9894</a></p>
                            <p><span class="font-medium text-[#636366]">Website:</span> <a href="https://campfreedive.ph" class="text-[#780000] font-bold hover:underline">https://campfreedive.ph</a></p>
                        </div>

                        <p class="text-xs text-[#8E8E93] pt-4">
                            Where Camp Freedive PH has formally designated a Data Protection Officer (“DPO”), the contact details of the designated DPO will be provided through the appropriate privacy notice or official communication channel.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 11. CHANGES TO THIS PRIVACY POLICY -->
            <section id="section-11" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    11. CHANGES TO THIS PRIVACY POLICY
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <div>
                        <h3 class="font-bold text-[#1D1D1F] text-base mb-1">11.1 Updates</h3>
                        <p class="mb-2">
                            Camp Freedive PH may update this Privacy Policy from time to time to reflect changes in our services, information-processing practices, security measures, applicable laws, regulations, or guidance issued by the National Privacy Commission.
                        </p>
                        <p class="mb-2">
                            The “Last Updated” date displayed at the beginning of this Privacy Policy indicates when the policy was most recently revised.
                        </p>
                        <p>
                            Where appropriate, material changes may be communicated through our website or other reasonable means. Your continued use of our website or services after an updated Privacy Policy becomes effective constitutes acknowledgment of the revised policy to the extent permitted by applicable law.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 12. CONTACT US -->
            <section id="section-12" class="scroll-mt-24 space-y-4 pt-4">
                <h2 class="text-xl sm:text-2xl font-bold text-[#1D1D1F] tracking-tight">
                    12. CONTACT US
                </h2>

                <div class="space-y-4 text-[#3A3A3C]">
                    <p>
                        If you have questions, concerns, or requests regarding this Privacy Policy or our handling of personal information, please contact us using the privacy contact information provided above.
                    </p>

                    <div class="space-y-1.5 text-sm text-[#3A3A3C]">
                        <p class="font-bold text-[#1D1D1F]">Camp Freedive PH</p>
                        <p>Barangay Bagalangit, Mabini, Batangas, Philippines</p>
                        <p><span class="font-medium text-[#636366]">Facebook:</span> <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@Campfreediveph</a></p>
                        <p><span class="font-medium text-[#636366]">Instagram:</span> <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" class="text-[#780000] font-bold underline hover:text-[#500000]">@campfreediveph</a></p>
                        <p><span class="font-medium text-[#636366]">Email:</span> <a href="mailto:privacy@campfreedive.ph" class="text-[#780000] font-bold underline hover:text-[#500000]">privacy@campfreedive.ph</a> / <a href="mailto:campfreediveph@gmail.com" class="text-[#780000] font-bold underline hover:text-[#500000]">campfreediveph@gmail.com</a></p>
                        <p><span class="font-medium text-[#636366]">Phone:</span> <a href="tel:+639278879894" class="text-[#780000] font-bold hover:underline">+63 927 887 9894</a></p>
                    </div>

                    <div class="text-xs text-[#8E8E93] pt-2">
                        <p><strong>Last Updated:</strong> September 26, 2026</p>
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
