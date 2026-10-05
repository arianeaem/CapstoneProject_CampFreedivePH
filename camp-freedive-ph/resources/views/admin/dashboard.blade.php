@extends('layouts.admin')

@section('title', 'Dashboard | Camp FreedivePH')

@section('content')
<div class="space-y-6">

    <!-- Top Command Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#1B6B8A] via-[#164B60] to-[#2e80a3]">Welcome back, {{ $user->name }}</span>
            </h1>
            <p class="text-sm text-[#6E6E73] mt-1">
                <span>It's {{ now('Asia/Manila')->format('l, F d, Y') }}</span>
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">

            <a href="{{ auth()->user()->isOwner() ? route('owner.bookings.create') : route('admin.bookings.create') }}" class="min-h-[44px] px-4 py-2 rounded-xl text-sm font-extrabold bg-[#00c3d0] hover:bg-[#00abb7] active:bg-[#009da7] active:scale-[0.98] text-[#1D1D1F] transition-all hover:-translate-y-px active:translate-y-0 shadow-2xs hover:shadow-xs inline-flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-[#00c3d0] focus:ring-offset-2">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Walk-in Booking</span>
            </a>
        </div>
    </div>

        <!-- ========================================================================= -->
        <!-- OPERATIONS (everyone) -->
        <!-- ========================================================================= -->

        <!-- Operational KPI Metrics -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 items-stretch gap-4 sm:gap-6 divide-y sm:divide-y-0 divide-[#E5E5EA]">
                
                <!-- Active Divers -->
                <div class="px-2 sm:px-4 py-1">
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Active Divers (This Month)</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-[#1D1D1F] mt-0.5 break-words">{{ $operationalStats['active_divers_month'] }}</div>
                </div>

                <!-- Average Trip Occupancy -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Average Trip Occupancy</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-[#1D1D1F] mt-0.5 break-words">{{ $operationalStats['avg_occupancy'] }}%</div>
                </div>

                <!-- Active Instructors -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Active Instructors</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-[#1D1D1F] mt-0.5 break-words">{{ $operationalStats['active_coaches_count'] }}</div>
                </div>

                <!-- Unmatched Students -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Unmatched Students</span>
                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                        <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold break-words {{ $operationalStats['unmatched_students_count'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                            {{ $operationalStats['unmatched_students_count'] }}
                        </div>
                        @if($operationalStats['unmatched_students_count'] > 0)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold bg-amber-50 text-amber-800" 
                                  aria-label="{{ $operationalStats['unmatched_students_count'] }} unmatched students requiring instructor matching">
                                <span>Needs Coach</span>
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold bg-emerald-50 text-emerald-800" 
                                  aria-label="All students matched to instructors">
                                <svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <span>All Matched</span>
                            </span>
                        @endif
                    </div>
                </div>

            </div>
        </div>

        <!-- Action Items and Upcoming Weekend Batches -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
            
            @if($actionInbox['total_count'] > 0)
                <!-- Action Items -->
                <div class="lg:col-span-4 flex flex-col h-full space-y-3">
                    <div class="flex items-center justify-between pb-0.5">
                        <h2 class="text-base font-bold text-[#1D1D1F]">Action Items</h2>
                        <span class="text-sm font-bold text-[#780000]">{{ $actionInbox['total_count'] }} alert(s)</span>
                    </div>

                    <div class="flex-1 flex flex-col justify-between gap-3">
                        <!-- Reschedules -->
                        @if($actionInbox['reschedules']->count() > 0)
                            <a href="{{ route('admin.bookings.requests') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#FEF3C7] hover:bg-[#FDE68A]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-amber-950 block">Pending Reschedules</span>
                                    <p class="text-sm text-amber-800 font-medium">{{ $actionInbox['reschedules']->count() }} guest request(s)</p>
                                </div>
                                <span class="text-sm font-bold text-amber-950 group-hover:underline shrink-0">Review</span>
                            </a>
                        @endif

                        <!-- Cancellations -->
                        @if($actionInbox['cancellations']->count() > 0)
                            <a href="{{ route('admin.bookings.requests') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#FEE2E2] hover:bg-[#FECDD3]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-rose-950 block">Cancellation Requests</span>
                                    <p class="text-sm text-rose-800 font-medium">{{ $actionInbox['cancellations']->count() }} pending cancellation(s)</p>
                                </div>
                                <span class="text-sm font-bold text-rose-950 group-hover:underline shrink-0">Review</span>
                            </a>
                        @endif

                        <!-- Coach Releases -->
                        @if($actionInbox['releases']->count() > 0)
                            <a href="{{ route('admin.coaches.requests') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#DBEAFE] hover:bg-[#BFDBFE]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-blue-950 block">Coach Release Requests</span>
                                    <p class="text-sm text-blue-800 font-medium">{{ $actionInbox['releases']->count() }} instructor release(s)</p>
                                </div>
                                <span class="text-sm font-bold text-blue-950 group-hover:underline shrink-0">Review</span>
                            </a>
                        @endif

                        <!-- Pending Refunds -->
                        @if($actionInbox['refunds']->count() > 0)
                            <a href="{{ route('admin.bookings.requests') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#F3E8FF] hover:bg-[#E9D5FF]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-purple-950 block">Guest Cancellation Claims</span>
                                    <p class="text-sm text-purple-800 font-medium">{{ $actionInbox['refunds']->count() }} cancellation claim(s) to process</p>
                                </div>
                                <span class="text-sm font-bold text-purple-950 group-hover:underline shrink-0">Process</span>
                            </a>
                        @endif

                        <!-- Understaffed Batches -->
                        @if($actionInbox['understaffed']->count() > 0)
                            <a href="{{ route('admin.coaches.matching') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#FEF3C7] hover:bg-[#FDE68A]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-amber-950 block">Under-staffed Batches</span>
                                    <p class="text-sm text-amber-800 font-medium">{{ $actionInbox['understaffed']->count() }} batch(es) need instructors</p>
                                </div>
                                <span class="text-sm font-bold text-amber-950 group-hover:underline shrink-0">Match</span>
                            </a>
                        @endif

                        <!-- Marine Weather Warnings -->
                        @if($actionInbox['weather_alerts']->count() > 0)
                            <a href="{{ route('admin.weather.index') }}" class="flex-1 min-h-[85px] p-4 sm:p-5 rounded-xl bg-[#FEE2E2] hover:bg-[#FECDD3]/70 active:scale-[0.99] active:brightness-95 flex items-center justify-between gap-3 group shadow-2xs transition-all">
                                <div class="space-y-1">
                                    <span class="text-sm font-extrabold text-red-950 block">Marine Weather Warning</span>
                                    <p class="text-sm text-red-800 font-medium">{{ $actionInbox['weather_alerts']->count() }} batch(es) under advisory</p>
                                </div>
                                <span class="text-sm font-bold text-red-950 group-hover:underline shrink-0">Inspect</span>
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Upcoming Weekend Batches -->
                <div class="lg:col-span-8 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-[#1D1D1F]">Upcoming Weekend Batches</h2>
                        </div>
                        <a href="{{ route('admin.batches.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm font-bold text-[#780000] hover:bg-[#F2F2F7] transition-colors inline-flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-[#780000]">
                            <span>View All Batches</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        @forelse($upcomingBatches as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $coachesNeeded = $pax > 0 ? (int) ceil($pax / 4) : 0;
                                $coachesAssigned = $batch->assigned_coaches_count;
                            @endphp
                            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs hover:border-[#D1D1D6] transition-all space-y-3 flex flex-col justify-between">
                                <div class="space-y-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <h3 class="font-extrabold text-[#1D1D1F] text-base">{{ $batch->batch_number }}</h3>
                                        <span class="px-2.5 py-0.5 rounded-md text-sm font-bold {{ $batch->risk_badge['class'] }}">
                                            {{ $batch->risk_badge['label'] }}
                                        </span>
                                    </div>

                                    <div>
                                        <p class="text-sm text-[#6E6E73]">
                                            {{ $batch->formatted_date_range }}
                                            <span class="text-[#780000] font-semibold">({{ $batch->start_date->diffForHumans() }})</span>
                                        </p>
                                    </div>

                                    <!-- Participants Count -->
                                    <div class="flex items-center justify-between pt-1 text-sm">
                                        <span class="text-[#6E6E73] font-medium">Participants:</span>
                                        <span class="font-bold text-[#1D1D1F]">{{ $pax }} {{ Str::plural('participant', $pax) }}</span>
                                    </div>

                                    <!-- Staffing Status -->
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-[#6E6E73] font-medium">Coaches:</span>
                                        @if($pax === 0)
                                            <span class="text-[#6E6E73] font-medium">
                                                {{ $coachesAssigned }} Assigned (0 Needed)
                                            </span>
                                        @elseif($coachesAssigned >= $coachesNeeded)
                                            <span class="text-emerald-700 font-bold flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                {{ $coachesAssigned }}/{{ $coachesNeeded }} Staffed
                                            </span>
                                        @else
                                            <span class="text-amber-700 font-bold flex items-center gap-1">
                                                {{ $coachesAssigned }}/{{ $coachesNeeded }} (Needs {{ $coachesNeeded - $coachesAssigned }})
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-2 py-8 text-center bg-white rounded-xl border border-[#E5E5EA] text-sm text-[#6E6E73]">
                                No upcoming confirmed batches found. <a href="{{ route('admin.batches.create') }}" class="text-[#780000] font-bold hover:underline">Create a new batch</a>
                            </div>
                        @endforelse
                    </div>
                </div>
            @else
                <!-- Upcoming Weekend Batches -->
                <div class="lg:col-span-12 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-[#1D1D1F]">Upcoming Weekend Batches</h2>
                        </div>
                        <a href="{{ route('admin.batches.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm font-bold text-[#780000] hover:bg-[#F2F2F7] transition-colors inline-flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-[#780000]">
                            <span>View All Batches</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @forelse($upcomingBatches as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $coachesNeeded = $pax > 0 ? (int) ceil($pax / 4) : 0;
                                $coachesAssigned = $batch->assigned_coaches_count;
                            @endphp
                            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs hover:border-[#D1D1D6] transition-all space-y-3 flex flex-col justify-between">
                                <div class="space-y-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <h3 class="font-extrabold text-[#1D1D1F] text-base">{{ $batch->batch_number }}</h3>
                                        <span class="px-2.5 py-0.5 rounded-md text-sm font-bold {{ $batch->risk_badge['class'] }}">
                                            {{ $batch->risk_badge['label'] }}
                                        </span>
                                    </div>

                                    <div>
                                        <p class="text-sm text-[#6E6E73]">
                                            {{ $batch->formatted_date_range }}
                                            <span class="text-[#780000] font-semibold">({{ $batch->start_date->diffForHumans() }})</span>
                                        </p>
                                    </div>

                                    <!-- Participants Count -->
                                    <div class="flex items-center justify-between pt-1 text-sm">
                                        <span class="text-[#6E6E73] font-medium">Participants:</span>
                                        <span class="font-bold text-[#1D1D1F]">{{ $pax }} {{ Str::plural('participant', $pax) }}</span>
                                    </div>

                                    <!-- Staffing Status -->
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-[#6E6E73] font-medium">Coaches:</span>
                                        @if($pax === 0)
                                            <span class="text-[#6E6E73] font-medium">
                                                {{ $coachesAssigned }} Assigned (0 Needed)
                                            </span>
                                        @elseif($coachesAssigned >= $coachesNeeded)
                                            <span class="text-emerald-700 font-bold flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                {{ $coachesAssigned }}/{{ $coachesNeeded }} Staffed
                                            </span>
                                        @else
                                            <span class="text-amber-700 font-bold flex items-center gap-1">
                                                {{ $coachesAssigned }}/{{ $coachesNeeded }} (Needs {{ $coachesNeeded - $coachesAssigned }})
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-4 py-8 text-center bg-white rounded-xl border border-[#E5E5EA] text-sm text-[#6E6E73]">
                                No upcoming confirmed batches found. <a href="{{ route('admin.batches.create') }}" class="text-[#780000] font-bold hover:underline">Create a new batch</a>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif

        </div>

        <!-- Recent Confirmed Bookings -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
            <div class="p-4 sm:p-5 border-b border-[#E5E5EA] flex items-center justify-between">
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-[#1D1D1F]">Recent Confirmed Reservations</h2>
                    <p class="text-sm text-[#6E6E73]">Real-time incoming bookings with paid downpayments.</p>
                </div>
                <a href="{{ route('admin.bookings.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm font-bold text-[#780000] hover:bg-[#F2F2F7] transition-colors inline-flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    <span>All Bookings ({{ $operationalStats['total_active_batches'] }} Batches)</span>
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-[#F2F2F7] text-[#6E6E73] font-bold uppercase tracking-wider border-b border-[#E5E5EA]">
                            <th class="py-3 px-4">Booking #</th>
                            <th class="py-3 px-4">Lead Guest</th>
                            <th class="py-3 px-4">Class Package</th>
                            <th class="py-3 px-4">Trip Dates</th>
                            <th class="py-3 px-4">Headcount</th>
                            <th class="py-3 px-4 pr-6">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E5EA]">
                        @forelse($recentBookings as $b)
                            <tr tabindex="0"
                                role="link"
                                onclick="window.location='{{ route('admin.bookings.show', $b) }}'"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('admin.bookings.show', $b) }}';}"
                                aria-label="View booking {{ $b->booking_number }} for {{ $b->contact_name }}"
                                class="hover:bg-[#F2F2F7] focus:bg-[#F2F2F7] focus:outline-none focus:ring-2 focus:ring-[#780000] focus:ring-inset cursor-pointer transition-colors group">
                                <td class="py-3 px-4 font-mono font-bold text-[#780000] group-hover:underline group-focus:underline">
                                    {{ $b->booking_number }}
                                </td>
                                <td class="py-3 px-4 font-semibold text-[#1D1D1F]">
                                    {{ $b->contact_name }}
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded-md font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                                        {{ $b->formatted_class_type }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-[#6E6E73]">
                                    {{ $b->start_date->format('M d') }} - {{ $b->end_date->format('M d, Y') }}
                                </td>
                                <td class="py-3 px-4 font-bold text-[#1D1D1F]">
                                    {{ $b->participants->count() }} pax
                                </td>
                                <td class="py-3 px-4 pr-6">
                                    <span class="px-2 py-0.5 rounded-md font-bold {{ $b->status_badge['class'] }}">
                                        {{ $b->status_badge['label'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-sm text-[#6E6E73]">
                                    No confirmed reservations yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @if($isOwner)
        <!-- ========================================================================= -->
        <!-- REVENUE & BUSINESS (owner only, same page - no view switching) -->
        <!-- ========================================================================= -->
        <div class="pt-2">
            <h2 class="text-lg font-extrabold text-[#1D1D1F]">Revenue &amp; business</h2>
            <p class="text-sm text-[#6E6E73]">Money collected, package sales, pricing and recent activity.</p>
        </div>

        <!-- Executive Financial Metrics -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 items-stretch gap-4 sm:gap-6 divide-y sm:divide-y-0 divide-[#E5E5EA]">
                
                <!-- Gross Collected Revenue -->
                <div class="px-2 sm:px-4 py-1">
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Gross Collected Revenue</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-[#780000] mt-0.5 break-words">₱{{ number_format($financials['gross_revenue'], 2) }}</div>
                </div>

                <!-- Outstanding Balances -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Outstanding Balances</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-amber-700 mt-0.5 break-words">₱{{ number_format($financials['outstanding_balances'], 2) }}</div>
                </div>

                <!-- Total Refunds Issued -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Total Refunds Issued</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-rose-700 mt-0.5 break-words">₱{{ number_format($financials['refunds_processed'], 2) }}</div>
                </div>

                <!-- Net Business Yield -->
                <div class="relative px-2 sm:px-4 pt-3 sm:pt-1 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Net Business Yield</span>
                    <div class="text-2xl sm:text-2xl lg:text-3xl font-extrabold text-emerald-700 mt-0.5 break-words">₱{{ number_format($financials['net_revenue'], 2) }}</div>
                </div>

            </div>
        </div>

        <!-- Class Package Revenue Contribution -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-2 bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs space-y-5">
                <div>
                    <h2 class="text-base font-bold text-[#1D1D1F]">Class Package Revenue Contribution</h2>
                </div>

                <!-- Package Revenue Share -->
                <div class="space-y-2">
                    <div role="img" 
                         aria-label="Package revenue share: {{ collect($packageAnalytics)->filter(fn($p) => ($p['share_percentage'] ?? 0) > 0)->map(fn($p) => $p['name'] . ' ' . $p['share_percentage'] . '% (' . $p['bookings_count'] . ' bookings)')->implode(', ') }}"
                         class="w-full bg-[#E5E5EA] h-6 sm:h-7 rounded-xl overflow-hidden flex shadow-inner">
                        @foreach($packageAnalytics as $pKey => $pData)
                            @if($pData['share_percentage'] > 0)
                                <div class="{{ $pData['bg_color'] }} transition-all flex items-center justify-center text-white text-sm font-bold px-1 truncate"
                                     style="width: {{ $pData['share_percentage'] }}%"
                                     title="{{ $pData['name'] }}: {{ $pData['share_percentage'] }}% ({{ $pData['bookings_count'] }} bookings)">
                                    @if($pData['share_percentage'] >= 10)
                                        <span>{{ $pData['share_percentage'] }}%</span>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                    
                    <!-- Progress Bar 0% and 100% Labels -->
                    <div class="flex justify-between items-center text-sm font-bold text-[#6E6E73] px-0.5">
                        <span>0%</span>
                        <span>100%</span>
                    </div>
                </div>

                <!-- Package Metrics Breakdown -->
                <div class="p-2 sm:p-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 divide-y sm:divide-y-0 sm:divide-x divide-[#E5E5EA]">
                        @foreach($packageAnalytics as $pKey => $pData)
                            <div class="space-y-2 {{ !$loop->first ? 'pt-3 sm:pt-0 sm:pl-4 lg:pl-6' : '' }}">
                                <!-- Line Indicator & Package Name -->
                                <div class="flex items-center gap-2">
                                    <span class="w-1.5 h-4 rounded-full {{ $pData['dot_class'] }} shrink-0"></span>
                                    <span class="font-extrabold text-sm text-[#1D1D1F]">{{ $pData['name'] }}</span>
                                </div>

                                <!-- Large Primary Booking Count & Share Percentage -->
                                <div>
                                    <div class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                                        {{ $pData['bookings_count'] }}
                                    </div>
                                    <div class="text-sm font-bold {{ $pData['text_color'] }} mt-0.5">
                                        {{ $pData['share_percentage'] }}%
                                    </div>
                                </div>

                                <!-- Revenue Metric -->
                                <div class="text-sm text-[#6E6E73]">
                                    <span class="font-bold text-[#1D1D1F]">₱{{ number_format($pData['revenue'], 2) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Net Dynamic Yield Strategy Card -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs flex flex-col justify-between">
                <div>
                    <!-- Header -->
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-[#1D1D1F]">Net Dynamic Yield</h2>
                        <a href="{{ route('admin.pricing.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm font-bold text-[#780000] hover:bg-[#F2F2F7] transition-colors inline-flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-[#780000]">
                            <span>Rules</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>

                    <!-- Hero Metric -->
                    <div class="mt-4">
                        <div class="text-3xl sm:text-4xl font-extrabold text-[#1D1D1F] tracking-tight">
                            {{ $dynamicPricingStats['net_lift'] < 0 ? '−' : '' }}₱{{ number_format(abs($dynamicPricingStats['net_lift']), 2) }}
                        </div>
                    </div>
                </div>

                <!-- Breakdown Rows -->
                <div class="mt-6 space-y-3 pt-4 border-t border-[#F2F2F7]">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-[#6E6E73] font-medium">Demand Surge Lift</span>
                        <strong class="text-emerald-700 font-bold">+₱{{ number_format($dynamicPricingStats['positive_yield'], 2) }}</strong>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-[#6E6E73] font-medium">Incentive Discounts</span>
                        <strong class="text-rose-700 font-bold">−₱{{ number_format($dynamicPricingStats['discount_given'], 2) }}</strong>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-[#6E6E73] font-medium">Active Pricing Rules</span>
                        <strong class="text-[#1D1D1F] font-bold">{{ $dynamicPricingStats['active_rules'] }} Rules</strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- Governance & Audit Log Stream -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-[#1D1D1F]">Audit Trail & System Activity</h2>
                    <p class="text-sm text-[#6E6E73]">Immutable records of administrative decisions, price changes, and refunds.</p>
                </div>
                <a href="{{ route('admin.audit_logs.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm font-bold text-[#780000] hover:bg-[#F2F2F7] transition-colors inline-flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-[#780000]">
                    <span>Full Audit Log</span>
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
            </div>

            <div class="divide-y divide-[#E5E5EA]">
                @forelse($recentAuditLogs as $log)
                    <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-sm">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-[#1D1D1F]">{{ $log->user?->name ?? 'System' }}</span>
                                <span class="px-2 py-0.5 rounded-md font-bold bg-[#F2F2F7] text-[#780000] font-mono text-sm">
                                    {{ $log->action }}
                                </span>
                            </div>
                            <p class="text-[#6E6E73]">{{ $log->description ?? $log->details }}</p>
                        </div>
                        <span class="text-[#6E6E73] text-sm shrink-0">
                            {{ $log->created_at->diffForHumans() }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-[#6E6E73] py-4 text-center">No audit logs recorded yet.</p>
                @endforelse
            </div>
        </div>

    @endif

</div>
@endsection
