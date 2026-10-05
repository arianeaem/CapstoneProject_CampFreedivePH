<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
    <title>@yield('title', 'Camp FreedivePH | Freediving Camp in Mabini, Batangas')</title>
    <meta name="description" content="@yield('meta_description', 'Learn freediving in Mabini, Batangas. Discovery classes for non-swimmers, fundives, and refinement practice dives.')">

    <!-- Fonts: Outfit (Display/Headings) & Plus Jakarta Sans (Body/UI) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap" rel="stylesheet">

    <!-- Favicon / Logo -->
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-full flex flex-col bg-[#F2F2F7] text-[#1D1D1F] antialiased text-sm overflow-x-hidden min-w-[320px]">

    @unless(View::hasSection('hide_header'))
    <!-- Navigation Bar -->
    <header class="sticky top-0 z-40 bg-white border-b border-[#E5E5EA]" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 sm:h-20 flex items-center justify-between gap-3">
            <!-- Brand Logo -->
            <a href="{{ route('landing') }}" class="flex items-center gap-2.5 sm:gap-3 group shrink-0">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH Logo" class="w-9 h-9 sm:w-12 sm:h-12 rounded-full object-contain bg-white shrink-0">
                <div class="block">
                    <span class="font-extrabold text-base sm:text-xl tracking-tight text-[#1D1D1F] block leading-none">Camp Freedive<span class="text-[#780000]">PH</span></span>
                    <span class="text-xs sm:text-sm text-[#6E6E73] font-medium tracking-wider block mt-0.5">Mabini, Batangas</span>
                </div>
            </a>

            <!-- Desktop Navigation Actions -->
            <div class="hidden sm:flex items-center gap-4 sm:gap-6">
                <a href="{{ route('manage.index') }}" class="text-sm sm:text-base font-semibold text-[#1D1D1F] hover:text-[#780000] transition-colors whitespace-nowrap">
                    Manage Booking
                </a>
                <a href="{{ route('booking.create') }}" class="btn-primary px-5 py-2.5 text-sm sm:text-base font-bold shadow-2xs hover:shadow transition-all whitespace-nowrap">
                    Book Now
                </a>
            </div>

            <!-- Mobile Menu Toggle Button -->
            <div class="flex sm:hidden items-center">
                <button type="button" 
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        :aria-expanded="mobileMenuOpen"
                        aria-label="Toggle navigation menu"
                        class="w-10 h-10 rounded-xl flex items-center justify-center text-[#1D1D1F] hover:bg-[#F2F2F7] active:bg-[#E5E5EA] transition-colors focus:outline-none cursor-pointer">
                    <svg x-show="!mobileMenuOpen" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" y1="6" x2="20" y2="6"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="18" x2="20" y2="18"></line>
                    </svg>
                    <svg x-show="mobileMenuOpen" x-cloak class="w-6 h-6 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile Menu Dropdown -->
        <div x-show="mobileMenuOpen"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             @click.away="mobileMenuOpen = false"
             @keydown.escape.window="mobileMenuOpen = false"
             class="sm:hidden border-t border-[#E5E5EA] bg-white px-4 py-4 space-y-3 shadow-lg">
            
            <nav class="space-y-2">
                <a href="{{ route('manage.index') }}" 
                   @click="mobileMenuOpen = false"
                   class="flex items-center justify-between px-4 py-3 rounded-xl font-semibold text-sm text-[#1D1D1F] hover:bg-[#F2F2F7] active:bg-[#E5E5EA] transition-colors">
                    <span>Manage Booking</span>
                    <svg class="w-4 h-4 text-[#8E8E93]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </a>

                <a href="{{ route('booking.create') }}" 
                   @click="mobileMenuOpen = false"
                   class="btn-primary w-full py-3 text-center text-sm font-bold block shadow-sm">
                    Book Now
                </a>
            </nav>
        </div>
    </header>
    @endunless

    <!-- Flash Messages -->
    <div id="app-flash-messages">
        @if(session('success'))
            <div class="banner banner-success banner-bar text-center flex items-center justify-center gap-2">
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('info'))
            <div class="banner banner-info banner-bar text-center flex items-center justify-center gap-2">
                <span>{{ session('info') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="banner banner-error banner-bar text-center flex items-center justify-center gap-2">
                <span>{{ session('error') }}</span>
            </div>
        @endif
    </div>

    <!-- Main Content -->
    <main id="app-page-content" class="flex-grow w-full max-w-full">
        @yield('content')
    </main>

    @unless(View::hasSection('hide_footer'))
    <!-- Footer -->
    <footer class="bg-[#1D1D1F] text-white pt-12 pb-10 border-t border-[#3A3A3C] mt-20 text-sm sm:text-base">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-16 pb-10 border-b border-[#3A3A3C]">
                
                <!-- Contact and Location Details -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH Logo" class="w-11 h-11 rounded-full object-contain bg-white border border-white/20">
                        <div>
                            <span class="font-extrabold text-xl text-white block leading-none">Camp Freedive<span class="text-[#00C3D0]">PH</span></span>
                            <span class="text-sm text-[#8E8E93] block mt-0.5">Mabini, Batangas</span>
                        </div>
                    </div>

                    <!-- Email Contact -->
                    <div class="flex items-center gap-2 text-sm sm:text-base text-[#D1D1D6]">
                        <a href="mailto:campfreediveph@gmail.com" class="hover:text-white hover:underline transition-colors font-medium">
                            campfreediveph@gmail.com
                        </a>
                    </div>

                    <!-- Phone Contact -->
                    <div class="flex items-center gap-2 text-sm sm:text-base text-[#D1D1D6]">
                        <a href="tel:+639278879894" class="hover:text-white hover:underline transition-colors font-medium">
                            +63 927 887 9894
                        </a>
                    </div>

                    <!-- Location Address -->
                    <div class="text-sm text-[#D1D1D6]">
                        <a href="https://maps.app.goo.gl/xJ1iSQbwETsdgzf37" 
                           target="_blank" 
                           rel="noopener noreferrer" 
                           class="hover:text-white hover:underline transition-colors inline-block"
                           aria-label="View The Shack Hideaway on Google Maps (opens in a new tab)">
                            The Shack Hideaway by Mayumi Resort, located along the National Road in Barangay Bagalangit, Mabini, Batangas, Philippines.
                        </a>
                    </div>
                </div>

                <!-- Resort Partner and Social Links -->
                <div class="space-y-5 md:text-right flex flex-col md:items-end justify-between">
                    <div>
                        <div class="text-sm text-[#8E8E93] font-semibold mb-1">Official Resort Partner</div>
                        <div class="text-base sm:text-lg font-bold text-white">
                            In Partnership with <span class="text-[#00C3D0]">The Shack Hideaway</span>
                        </div>
                        <p class="text-sm text-[#8E8E93] mt-1 max-w-sm md:ml-auto">
                            Enjoy direct ocean access, sunset views, and dedicated dive training facilities in Anilao, Mabini.
                        </p>
                    </div>

                    <!-- Social Media Links -->
                    <div class="space-y-2">
                        <div class="text-sm text-[#8E8E93] font-semibold">Follow Our Adventures</div>
                        <div class="flex items-center gap-3 md:justify-end">
                            <!-- Facebook -->
                            <a href="https://www.facebook.com/Campfreediveph/" 
                               target="_blank" 
                               rel="noopener noreferrer" 
                               title="Camp FreedivePH on Facebook"
                               class="w-10 h-10 rounded-full bg-[#3A3A3C] hover:bg-[#1877F2] text-white flex items-center justify-center transition-colors">
                                <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>

                            <!-- Instagram -->
                            <a href="https://www.instagram.com/campfreediveph/" 
                               target="_blank" 
                               rel="noopener noreferrer" 
                               title="Camp FreedivePH on Instagram"
                               class="w-10 h-10 rounded-full bg-[#3A3A3C] hover:bg-gradient-to-tr hover:from-[#F58529] hover:via-[#DD2A7B] hover:to-[#8134AF] text-white flex items-center justify-center transition-colors">
                                <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.13-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer Copyright & Legal Links -->
            <div class="pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-[#8E8E93]">
                <p>&copy; 2026 Camp FreedivePH. All rights reserved.</p>
                <div class="flex items-center gap-4 text-xs sm:text-sm">
                    <a href="{{ route('legal.terms') }}" class="hover:text-white transition-colors">Terms &amp; Conditions</a>
                    <a href="{{ route('legal.privacy') }}" class="hover:text-white transition-colors">Privacy Policy</a>
                </div>
            </div>
        </div>
    </footer>
    @endunless

    <!-- Cookie Consent Banner -->
    <aside x-data="{
               showConsent: false,
               init() {
                   if (!localStorage.getItem('cfph_cookie_consent')) {
                       // Small delay so it appears smoothly after page load
                       setTimeout(() => { this.showConsent = true; }, 600);
                   }
               },
               accept(type) {
                   localStorage.setItem('cfph_cookie_consent', type);
                   this.showConsent = false;
               }
           }" 
           x-show="showConsent" 
           x-cloak
           x-transition:enter="transition ease-out duration-300 transform"
           x-transition:enter-start="opacity-0 translate-y-8 scale-95"
           x-transition:enter-end="opacity-100 translate-y-0 scale-100"
           x-transition:leave="transition ease-in duration-200 transform"
           x-transition:leave-start="opacity-100 translate-y-0 scale-100"
           x-transition:leave-end="opacity-0 translate-y-8 scale-95"
           class="fixed bottom-4 sm:bottom-6 left-3 right-3 sm:left-auto sm:right-6 sm:max-w-md z-50 bg-[#1D1D1F] text-white border border-[#3A3A3C] p-4 sm:p-5 rounded-2xl shadow-2xl space-y-3"
           aria-label="Cookie consent banner">
        <div class="flex items-start gap-3">
            <div class="space-y-1 text-xs sm:text-sm">
                <p class="font-bold text-white text-sm sm:text-base leading-tight">
                    We Value Your Privacy
                </p>
                <p class="text-[#8E8E93] leading-relaxed text-xs">
                    We use strictly essential cookies for secure booking sessions, CSRF protection, and PayMongo transactions. We do not track or sell your personal data. Read our <a href="{{ route('legal.privacy') }}" class="text-[#00C3D0] hover:underline font-semibold">Privacy Policy</a>.
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 pt-1">
            <button type="button" 
                    @click="accept('all')" 
                    class="flex-1 py-2 px-3 bg-[#780000] hover:bg-[#5E0000] text-white font-bold text-xs rounded-xl transition-all shadow-sm">
                Accept All
            </button>
            <button type="button" 
                    @click="accept('essential')" 
                    class="flex-1 py-2 px-3 bg-[#3A3A3C] hover:bg-[#48484A] text-[#D1D1D6] hover:text-white font-semibold text-xs rounded-xl transition-all">
                Essential Only
            </button>
        </div>
    </aside>

    @stack('scripts')
</body>
</html>
