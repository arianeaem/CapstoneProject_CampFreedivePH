@extends('layouts.admin')

@section('title', 'Settings | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Settings</h1>
        </div>
    </div>

    <div class="space-y-6">

        <!-- =========================================================================
             SECTION 1: PROGRAM PRICING
             ========================================================================= -->
        <section class="space-y-3">
            <h2 class="text-base font-bold text-[#1D1D1F]">Program Pricing</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- Class Packages Card -->
                <a href="{{ route('owner.settings.programs') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        Class Packages
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        Base rates, package inclusions, and exclusions.
                    </p>
                </a>

                <!-- Deposit Rules Card -->
                <a href="{{ route('owner.settings.deposits') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        Deposit Rules
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        Required downpayment amounts for bookings.
                    </p>
                </a>

                <!-- Add-on Services Card -->
                <a href="{{ route('owner.settings.addons') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        Add-on Services
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        Transportation, boat rentals, and local fees.
                    </p>
                </a>

            </div>
        </section>

        <!-- =========================================================================
             SECTION 2: CAMP POLICIES
             ========================================================================= -->
        <section class="space-y-3">
            <h2 class="text-base font-bold text-[#1D1D1F]">Camp Policies</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- Cancellation Policies Card -->
                <a href="{{ route('owner.settings.cancellation') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        Cancellation Policies
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        Refund rules and rescheduling windows.
                    </p>
                </a>

            </div>
        </section>

        <!-- =========================================================================
             SECTION 3: SYSTEM ADMINISTRATION
             ========================================================================= -->
        <section class="space-y-3">
            <h2 class="text-base font-bold text-[#1D1D1F]">System Administration</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- User Management Card -->
                <a href="{{ route('owner.users.index') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        User Management
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        Staff user accounts and assigned roles.
                    </p>
                </a>

                <!-- Audit Logs Card -->
                <a href="{{ route('owner.audit_logs.index') }}" class="bg-transparent hover:bg-[#F2F2F7] p-4 rounded-xl transition-colors group flex flex-col justify-start">
                    <span class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000] transition-colors">
                        Audit Logs
                    </span>
                    <p class="text-xs text-[#6E6E73] leading-relaxed mt-1.5">
                        System security and staff activity records.
                    </p>
                </a>

            </div>
        </section>

    </div>

</div>
@endsection
