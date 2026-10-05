<!DOCTYPE html>
<html lang="en" class="h-full bg-[#F8F9FA]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
    <title>@yield('title', 'Staff Portal | Camp FreedivePH')</title>

    <!-- Fonts: Outfit (Display/Headings) & Plus Jakarta Sans (Body/UI) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap" rel="stylesheet">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">

    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="h-full text-[#1D1D1F] antialiased text-sm overflow-x-hidden min-w-[320px] bg-[#F8F9FA]"
      x-data="{ 
          sidebarCollapsed: localStorage.getItem('sidebar_collapsed') === 'true',
          mobileMenuOpen: false,
          toggleSidebar() {
              this.sidebarCollapsed = !this.sidebarCollapsed;
              localStorage.setItem('sidebar_collapsed', this.sidebarCollapsed);
          }
      }"
      @close-mobile-menu.window="mobileMenuOpen = false">

@php
    $userName = auth()->user()->name ?? 'User';
    $nameParts = array_values(array_filter(explode(' ', trim($userName))));
    $userInitials = '';
    if (count($nameParts) >= 2) {
        $userInitials = strtoupper(substr($nameParts[0], 0, 1) . substr(end($nameParts), 0, 1));
    } else {
        $userInitials = strtoupper(substr($nameParts[0] ?? 'U', 0, 2));
    }

    $routeName = request()->route() ? request()->route()->getName() : '';
    $pageBreadcrumbTitle = match(true) {
        str_starts_with($routeName, 'admin.dashboard') || str_starts_with($routeName, 'owner.dashboard') => 'Dashboard',
        str_starts_with($routeName, 'admin.bookings') || str_starts_with($routeName, 'owner.bookings') => 'Bookings',
        str_starts_with($routeName, 'admin.batches') || str_starts_with($routeName, 'owner.batches') => 'Batches',
        str_starts_with($routeName, 'admin.weather') || str_starts_with($routeName, 'owner.weather') => 'Safety Monitoring',
        str_starts_with($routeName, 'admin.pricing') || str_starts_with($routeName, 'owner.pricing') => 'Dynamic Pricing',
        str_starts_with($routeName, 'admin.demand') || str_starts_with($routeName, 'owner.demand') => 'Demand Forecast',
        str_starts_with($routeName, 'admin.payments') || str_starts_with($routeName, 'owner.payments') => 'Payments & Refunds',
        str_starts_with($routeName, 'admin.coaches') || str_starts_with($routeName, 'owner.coaches') => 'Coaches & Schedules',
        str_starts_with($routeName, 'admin.reports') || str_starts_with($routeName, 'owner.reports') => 'Reports & Analytics',
        str_starts_with($routeName, 'admin.users') || str_starts_with($routeName, 'owner.users') => 'User Management',
        str_starts_with($routeName, 'admin.audit_logs') || str_starts_with($routeName, 'owner.audit_logs') => 'Audit Logs',
        str_starts_with($routeName, 'admin.settings') || str_starts_with($routeName, 'owner.settings') => 'System Settings',
        str_starts_with($routeName, 'coach.dashboard') => 'Dashboard',
        str_starts_with($routeName, 'coach.availability') => 'Availability Calendar',
        str_starts_with($routeName, 'coach.schedule') => 'My Schedule & History',
        str_starts_with($routeName, 'coach.requests') => 'Open Slot Requests',
        default => 'Dashboard',
    };
@endphp

    <div class="min-h-full flex flex-col md:flex-row">

        <!-- Desktop Sidebar -->
        <aside id="desktop-sidebar" 
               class="hidden md:flex md:flex-col md:fixed md:inset-y-0 bg-white border-r border-[#E5E5EA] z-30 transition-all duration-300"
               :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'">
            
            <!-- Sidebar Header -->
            <div class="h-14 flex items-center bg-white transition-all overflow-hidden"
                 :class="sidebarCollapsed ? 'justify-center px-2' : 'px-4'">
                
                <!-- Expanded Logo and Brand -->
                <a href="{{ auth()->user()->isCoach() ? route('coach.dashboard') : (auth()->user()->isOwner() ? route('owner.dashboard') : route('admin.dashboard')) }}" 
                   x-show="!sidebarCollapsed" 
                   class="flex items-center gap-2.5 min-w-0 flex-1 group">
                    <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH Logo" class="w-8 h-8 rounded-full object-contain bg-white shadow-2xs border border-[#E5E5EA] group-hover:scale-105 transition-transform shrink-0">
                    <div class="min-w-0 flex-1">
                        <span class="font-extrabold text-sm text-[#1D1D1F] block leading-tight truncate tracking-tight">Camp Freedive<span class="text-[#780000]">PH</span></span>
                    </div>
                </a>

                <!-- Collapsed Logo -->
                <a href="{{ auth()->user()->isCoach() ? route('coach.dashboard') : (auth()->user()->isOwner() ? route('owner.dashboard') : route('admin.dashboard')) }}" 
                   x-show="sidebarCollapsed" 
                   class="flex items-center justify-center group"
                   title="Camp FreedivePH">
                    <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH Logo" class="w-8 h-8 rounded-full object-contain bg-white shadow-2xs border border-[#E5E5EA] transition-transform">
                </a>
            </div>

            <!-- Sidebar Navigation Links -->
            <nav class="flex-1 py-3 space-y-1.5 overflow-y-auto"
                 :class="sidebarCollapsed ? 'px-2' : 'px-3'">
                @if(auth()->user()->isOwner() || auth()->user()->isAdmin())
                    <!-- Dashboard -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.dashboard') : route('admin.dashboard') }}" 
                       title="Dashboard"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.dashboard', 'owner.dashboard']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-home-32.png') }}" class="w-5 h-5 shrink-0" alt="Dashboard">
                        <span x-show="!sidebarCollapsed" class="truncate">Dashboard</span>
                    </a>

                    <!-- Bookings -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.bookings.index') : route('admin.bookings.index') }}" 
                       title="Bookings"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.bookings.*', 'owner.bookings.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-booking-60.png') }}" class="w-5 h-5 shrink-0" alt="Bookings">
                        <span x-show="!sidebarCollapsed" class="truncate">Bookings</span>
                    </a>

                    <!-- Batches -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.batches.index') : route('admin.batches.index') }}" 
                       title="Batches"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.batches.*', 'owner.batches.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-medium-icons-32.png') }}" class="w-5 h-5 shrink-0" alt="Batches">
                        <span x-show="!sidebarCollapsed" class="truncate">Batches</span>
                    </a>

                    <!-- Coaches & Schedules -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.coaches.index') : route('admin.coaches.index') }}" 
                       title="Coaches & Schedules"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.coaches.*', 'owner.coaches.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-coach-60.png') }}" class="w-5 h-5 shrink-0" alt="Coaches">
                        <span x-show="!sidebarCollapsed" class="truncate">Coaches & Schedules</span>
                    </a>

                    <!-- Weather & Marine Safety -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.weather.index') : route('admin.weather.index') }}" 
                       title="Safety Monitoring"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.weather.*', 'owner.weather.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-warning-shield-32.png') }}" class="w-5 h-5 shrink-0" alt="SafetyMonitoring">
                        <span x-show="!sidebarCollapsed" class="truncate">Safety Monitoring</span>
                    </a>

                    <!-- Payments & Refunds -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.payments.index') : route('admin.payments.index') }}" 
                       title="Payments & Refunds"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.payments.*', 'owner.payments.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-magnetic-card-60.png') }}" class="w-5 h-5 shrink-0" alt="PaymentsAndRefunds">
                        <span x-show="!sidebarCollapsed" class="truncate">Payments & Refunds</span>
                    </a>

                    <!-- Dynamic Pricing -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.pricing.index') : route('admin.pricing.index') }}" 
                       title="Dynamic Pricing"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.pricing.*', 'owner.pricing.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-price-tag-60.png') }}" class="w-5 h-5 shrink-0" alt="DynamicPricing">
                        <span x-show="!sidebarCollapsed" class="truncate">Dynamic Pricing</span>
                    </a>

                    <!-- Demand Forecast -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.demand.index') : route('admin.demand.index') }}" 
                       title="Demand Forecast"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.demand.*', 'owner.demand.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-up-squared-60.png') }}" class="w-5 h-5 shrink-0" alt="DemandForecast">
                        <span x-show="!sidebarCollapsed" class="truncate">Demand Forecast</span>
                    </a>

                    <!-- Reports & Analytics -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.reports.index') : route('admin.reports.index') }}" 
                       title="Reports & Analytics"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.reports.*', 'owner.reports.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-analytics-60.png') }}" class="w-5 h-5 shrink-0" alt="Reports">
                        <span x-show="!sidebarCollapsed" class="truncate">Reports & Analytics</span>
                    </a>

                    <!-- Settings (Owner Only) -->
                    @if(auth()->user()->isOwner())
                        <a href="{{ route('owner.settings.index') }}" 
                           title="Settings"
                           class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs(['admin.settings.*', 'owner.settings.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                           :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                            <img src="{{ asset('icons/icons8-settings-60.png') }}" class="w-6 h-6 shrink-0" alt="Settings">
                            <span x-show="!sidebarCollapsed" class="truncate">Settings</span>
                        </a>
                    @endif
                @else
                    <!-- Coach Navigation -->
                    <a href="{{ route('coach.dashboard') }}" 
                       title="Dashboard"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs('coach.dashboard') ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-home-32.png') }}" class="w-5 h-5 shrink-0" alt="Dashboard">
                        <span x-show="!sidebarCollapsed" class="truncate">Dashboard</span>
                    </a>

                    <!-- Availability Calendar -->
                    <a href="{{ route('coach.availability.index') }}" 
                       title="Availability Calendar"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs('coach.availability.*') ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-calendar-60.png') }}" class="w-5 h-5 shrink-0" alt="Availability Calendar">
                        <span x-show="!sidebarCollapsed" class="truncate">Availability Calendar</span>
                    </a>

                    <!-- My Schedule & Students -->
                    <a href="{{ route('coach.schedule.index') }}" 
                       title="My Schedule & History"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs('coach.schedule.*') ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-booking-60.png') }}" class="w-5 h-5 shrink-0" alt="Schedule">
                        <span x-show="!sidebarCollapsed" class="truncate">My Schedule & History</span>
                    </a>

                    <!-- Open Requests Board -->
                    <a href="{{ route('coach.requests.index') }}" 
                       title="Open Slot Requests"
                       class="flex items-center rounded-xl font-semibold transition-all {{ request()->routeIs('coach.requests.*') ? 'bg-[#780000]/10 text-[#780000] font-bold shadow-2xs' : 'text-[#3A3A3C] hover:bg-[#F2F2F7] hover:text-[#780000]' }}"
                       :class="sidebarCollapsed ? 'justify-center p-2.5' : 'gap-3 px-3.5 py-2.5'">
                        <img src="{{ asset('icons/icons8-coach-60.png') }}" class="w-5 h-5 shrink-0" alt="OpenRequests">
                        <span x-show="!sidebarCollapsed" class="truncate">Open Slot Requests</span>
                    </a>
                @endif
            </nav>
        </aside>

        <!-- Mobile Drawer -->
        <div x-show="mobileMenuOpen" x-cloak class="md:hidden fixed inset-0 z-50 bg-black/50" @click="mobileMenuOpen = false"></div>

        <div x-show="mobileMenuOpen" 
             x-cloak 
             id="mobile-sidebar"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full"
             class="md:hidden fixed inset-y-0 left-0 z-50 w-72 h-full max-h-screen bg-white flex flex-col shadow-2xl border-r border-[#E5E5EA]">
            
            <!-- Mobile Drawer Header -->
            <div class="h-14 shrink-0 flex items-center justify-between px-4 border-b border-[#E5E5EA]">
                <a href="{{ auth()->user()->isCoach() ? route('coach.dashboard') : (auth()->user()->isOwner() ? route('owner.dashboard') : route('admin.dashboard')) }}" class="flex items-center gap-2.5 min-w-0">
                    <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH" class="w-8 h-8 rounded-full object-contain bg-white shadow-2xs border border-[#E5E5EA] shrink-0">
                    <span class="font-extrabold text-sm text-[#1D1D1F] tracking-tight">Camp Freedive<span class="text-[#780000]">PH</span></span>
                </a>
                <button type="button" @click="mobileMenuOpen = false" class="w-8 h-8 flex items-center justify-center text-[#8E8E93] hover:text-[#1D1D1F] rounded-lg hover:bg-[#F2F2F7] transition-colors cursor-pointer" aria-label="Close navigation menu">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <!-- Mobile Drawer Navigation -->
            <nav class="flex-1 min-h-0 px-3 py-3 space-y-1.5 overflow-y-auto overscroll-contain">
                @if(auth()->user()->isOwner() || auth()->user()->isAdmin())
                    <a href="{{ auth()->user()->isOwner() ? route('owner.dashboard') : route('admin.dashboard') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.dashboard', 'owner.dashboard']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-home-32.png') }}" class="w-5 h-5 shrink-0" alt="Dashboard">
                        <span>Dashboard</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.bookings.index') : route('admin.bookings.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.bookings.*', 'owner.bookings.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-booking-60.png') }}" class="w-5 h-5 shrink-0" alt="Bookings">
                        <span>Bookings</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.batches.index') : route('admin.batches.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.batches.*', 'owner.batches.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-medium-icons-32.png') }}" class="w-5 h-5 shrink-0" alt="Batches">
                        <span>Batches</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.coaches.index') : route('admin.coaches.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.coaches.*', 'owner.coaches.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-coach-60.png') }}" class="w-5 h-5 shrink-0" alt="Coaches">
                        <span>Coaches & Schedules</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.weather.index') : route('admin.weather.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.weather.*', 'owner.weather.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-warning-shield-32.png') }}" class="w-5 h-5 shrink-0" alt="SafetyMonitoring">
                        <span>Safety Monitoring</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.payments.index') : route('admin.payments.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.payments.*', 'owner.payments.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-magnetic-card-60.png') }}" class="w-5 h-5 shrink-0" alt="PaymentsAndRefunds">
                        <span>Payments & Refunds</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.pricing.index') : route('admin.pricing.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.pricing.*', 'owner.pricing.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-price-tag-60.png') }}" class="w-5 h-5 shrink-0" alt="DynamicPricing">
                        <span>Dynamic Pricing</span>
                    </a>
                    <!-- Demand Forecast -->
                    <a href="{{ auth()->user()->isOwner() ? route('owner.demand.index') : route('admin.demand.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.demand.*', 'owner.demand.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-up-squared-60.png') }}" class="w-5 h-5 shrink-0" alt="DemandForecast">
                        <span>Demand Forecast</span>
                    </a>
                    <a href="{{ auth()->user()->isOwner() ? route('owner.reports.index') : route('admin.reports.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.reports.*', 'owner.reports.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-analytics-60.png') }}" class="w-5 h-5 shrink-0" alt="Reports">
                        <span>Reports & Analytics</span>
                    </a>
                    @if(auth()->user()->isOwner())
                        <a href="{{ route('owner.settings.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs(['admin.settings.*', 'owner.settings.*']) ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                            <img src="{{ asset('icons/icons8-settings-60.png') }}" class="w-6 h-6 shrink-0" alt="Settings">
                            <span>Settings</span>
                        </a>
                    @endif
                @else
                    <!-- Coach Mobile Navigation -->
                    <a href="{{ route('coach.dashboard') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs('coach.dashboard') ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-home-32.png') }}" class="w-5 h-5 shrink-0" alt="Dashboard">
                        <span>Dashboard</span>
                    </a>
                    <a href="{{ route('coach.availability.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs('coach.availability.*') ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-calendar-60.png') }}" class="w-5 h-5 shrink-0" alt="Availability Calendar">
                        <span>Availability Calendar</span>
                    </a>
                    <a href="{{ route('coach.schedule.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs('coach.schedule.*') ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-booking-60.png') }}" class="w-5 h-5 shrink-0" alt="Schedule">
                        <span>My Schedule & History</span>
                    </a>
                    <a href="{{ route('coach.requests.index') }}" class="flex items-center gap-3 px-3.5 py-2 rounded-xl font-semibold {{ request()->routeIs('coach.requests.*') ? 'bg-[#780000]/10 text-[#780000] font-bold' : 'text-[#3A3A3C] hover:bg-[#F2F2F7]' }}">
                        <img src="{{ asset('icons/icons8-coach-60.png') }}" class="w-5 h-5 shrink-0" alt="OpenRequests">
                        <span>Open Slot Requests</span>
                    </a>
                @endif
            </nav>
        </div>

        <!-- Main Content Container -->
        <main class="flex-1 flex flex-col min-h-screen transition-all duration-300"
              :class="sidebarCollapsed ? 'md:pl-20' : 'md:pl-64'">
            
            <!-- Top Header -->
            <header class="sticky top-0 z-20 bg-white border-b border-[#E5E5EA] px-4 sm:px-6 h-14 flex items-center justify-between shadow-2xs">
                
                <!-- Sidebar Controls and Breadcrumbs -->
                <div class="flex items-center gap-3">
                    <!-- Desktop Sidebar Toggle -->
                    <button type="button" 
                            @click="toggleSidebar()" 
                            class="hidden md:flex items-center justify-center w-8 h-8 rounded-md hover:bg-[#F2F2F7] transition-all cursor-pointer"
                            :title="sidebarCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'"
                            :aria-label="sidebarCollapsed ? 'Expand desktop sidebar' : 'Collapse desktop sidebar'">
                        <img src="{{ asset('icons/icons8-sidebar-60.png') }}" class="w-5 h-5 shrink-0 opacity-80 hover:opacity-100 transition-opacity" alt="Toggle Sidebar">
                    </button>

                    <!-- Mobile Drawer Toggle -->
                    <button type="button" 
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            class="md:hidden flex items-center justify-center w-8 h-8 rounded-md hover:bg-[#F2F2F7] cursor-pointer"
                            aria-label="Toggle navigation menu">
                        <img src="{{ asset('icons/icons8-sidebar-60.png') }}" class="w-5 h-5 shrink-0 opacity-80 hover:opacity-100 transition-opacity" alt="Open Menu">
                    </button>

                    <!-- Section Separator -->
                    <div class="h-4 w-px bg-[#D1D1D6]"></div>

                    <!-- Breadcrumbs -->
                    <div id="header-breadcrumbs" class="flex items-center gap-1.5 text-xs sm:text-sm text-[#6E6E73] min-w-0">
                        @if(View::hasSection('breadcrumb'))
                            @yield('breadcrumb')
                        @else
                            <span class="font-bold text-[#1D1D1F]">{{ $pageBreadcrumbTitle }}</span>
                        @endif
                    </div>
                </div>

                <!-- User Profile Menu -->
                <div class="relative" x-data="{ profileMenuOpen: false }">
                    <button type="button" 
                            @click="profileMenuOpen = !profileMenuOpen" 
                            @click.outside="profileMenuOpen = false"
                            class="flex items-center gap-2 cursor-pointer focus:outline-none group"
                            aria-label="User account settings and menu">
                        
                        <!-- User Avatar -->
                        <div class="w-9 h-9 rounded-full bg-[#F8EAEA] border-2 border-[#780000] text-[#780000] flex items-center justify-center font-bold text-sm shrink-0 transition-all">
                            {{ $userInitials }}
                        </div>
                    </button>

                    <!-- User Profile Dropdown -->
                    <div x-show="profileMenuOpen" 
                         x-cloak
                         x-transition:enter="transition ease-out duration-150 transform"
                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100 transform"
                         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                         x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                         class="absolute right-0 mt-2 w-64 bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-3.5 z-50 space-y-3">
                        
                        <!-- User Information -->
                        <div class="flex items-center gap-3 pb-3 border-b border-[#E5E5EA]">
                            <div class="min-w-0 flex-1">
                                <span class="inline-block mt-1 px-2.5 py-0.5 rounded-md text-sm font-bold bg-[#F8EAEA] text-[#780000]">
                                    {{ auth()->user()->role_label ?? ucfirst(auth()->user()->role) }}
                                </span>
                                <div class="font-bold text-sm text-[#1D1D1F] truncate">{{ auth()->user()->name }}</div>
                                <div class="text-sm text-[#6E6E73] truncate mt-0.5">{{ auth()->user()->email }}</div>
                            </div>
                        </div>

                        <!-- Logout -->
                        <div>
                            <form action="{{ route('logout') }}" method="POST" data-no-spa data-native>
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[#1D1D1F] hover:bg-rose-50 font-bold text-sm transition-colors cursor-pointer">
                                    <img src="{{ asset('icons/icons8-logout-60.png') }}" class="w-5 h-5 shrink-0 object-contain" alt="Sign Out">
                                    <span>Log Out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </header>
            
            <!-- Global Flash Messages -->
            <div id="flash-messages-container" class="px-4 sm:px-8 pt-4">
                @if(session('success'))
                    <div class="p-4 mb-4 rounded-xl bg-[#ECFDF5] text-[#065F46] flex items-center justify-between text-sm font-medium shadow-2xs">
                        <div class="flex items-center gap-2.5">
                            <span>{{ session('success') }}</span>
                        </div>
                        <button type="button" @click="$el.parentElement.remove()" class="text-sm font-bold text-[#065F46]/60 hover:text-[#065F46]" aria-label="Dismiss success notification">✕</button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="p-4 mb-4 rounded-xl bg-[#FEF2F2] text-[#991B1B] flex items-center justify-between text-sm font-medium shadow-2xs">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2 h-2 rounded-full bg-[#FF3B3C]"></span>
                            <span>{{ session('error') }}</span>
                        </div>
                        <button type="button" @click="$el.parentElement.remove()" class="text-sm font-bold text-[#991B1B]/60 hover:text-[#991B1B]" aria-label="Dismiss error notification">✕</button>
                    </div>
                @endif

                @if(session('info'))
                    <div class="p-4 mb-4 rounded-xl bg-[#EFF6FF] text-[#1E40AF] flex items-center justify-between text-sm font-medium shadow-2xs">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2 h-2 rounded-full bg-[#0088FF]"></span>
                            <span>{{ session('info') }}</span>
                        </div>
                        <button type="button" @click="$el.parentElement.remove()" class="text-sm font-bold text-[#1E40AF]/60 hover:text-[#1E40AF]" aria-label="Dismiss notice">✕</button>
                    </div>
                @endif
            </div>

            <!-- Page Specific Content -->
            <div id="spa-page-content" class="flex-1 px-4 sm:px-6 lg:px-8 xl:px-10 pb-12 pt-2 max-w-[1600px] w-full mx-auto">
                @yield('content')
            </div>

        </main>

    </div>

    {{-- Page scripts live in their own container so the SPA router can swap and run them on navigation --}}
    <div id="spa-page-scripts">@stack('scripts')</div>
</body>
</html>
