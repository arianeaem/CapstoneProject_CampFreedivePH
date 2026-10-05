@extends('layouts.admin')

@section('title', 'System Audit Logs | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">System Audit Logs</h1>
            </div>
        </div>
    </div>

    <!-- Audit Logs Ledger -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        
        <!-- Audit Log Filters and Search -->
        <div class="p-3 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                
                <!-- Action Category Filters -->
                <div role="tablist" aria-label="Audit log event filters" class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    <a href="{{ request()->fullUrlWithQuery(['action' => '']) }}" 
                       role="tab"
                       aria-selected="{{ !request('action') ? 'true' : 'false' }}"
                       class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ !request('action') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Events
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['action' => 'LOGIN_SUCCESS']) }}" 
                       role="tab"
                       aria-selected="{{ request('action') === 'LOGIN_SUCCESS' ? 'true' : 'false' }}"
                       class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('action') === 'LOGIN_SUCCESS' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Logins
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['action' => 'LOGIN_FAILED']) }}" 
                       role="tab"
                       aria-selected="{{ request('action') === 'LOGIN_FAILED' ? 'true' : 'false' }}"
                       class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('action') === 'LOGIN_FAILED' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Failed Logins
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['action' => 'USER_UPDATED']) }}" 
                       role="tab"
                       aria-selected="{{ request('action') === 'USER_UPDATED' ? 'true' : 'false' }}"
                       class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('action') === 'USER_UPDATED' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        User Updates
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['action' => 'USER_STATUS_TOGGLED']) }}" 
                       role="tab"
                       aria-selected="{{ request('action') === 'USER_STATUS_TOGGLED' ? 'true' : 'false' }}"
                       class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('action') === 'USER_STATUS_TOGGLED' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Status Toggles
                    </a>
                </div>

                <!-- Search and Advanced Filters -->
                <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ route('admin.audit_logs.index') }}" class="flex-1 min-w-0 lg:flex-initial">
                        @if(request('action'))
                            <input type="hidden" name="action" value="{{ request('action') }}">
                        @endif
                        @if(request('date_from'))
                            <input type="hidden" name="date_from" value="{{ request('date_from') }}">
                        @endif
                        @if(request('date_to'))
                            <input type="hidden" name="date_to" value="{{ request('date_to') }}">
                        @endif

                        <div class="relative w-full sm:w-64">
                            <input type="text" 
                                   name="search" 
                                   value="{{ request('search') }}" 
                                   placeholder="Search actor, details, IP..." 
                                   aria-label="Search actor, details, or IP address"
                                   class="w-full min-h-[44px] pl-9 pr-3 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white focus:bg-white focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                            <svg class="w-4 h-4 text-[#6E6E73] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                    </form>

                    <!-- Advanced Filter Toggle -->
                    <div class="relative shrink-0">
                        <button type="button" 
                                @click="openFilters = !openFilters" 
                                :aria-expanded="openFilters ? 'true' : 'false'"
                                aria-haspopup="true"
                                class="btn-secondary min-h-[44px] flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold whitespace-nowrap shrink-0 cursor-pointer active:scale-[0.98] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                            <span class="whitespace-nowrap">Filter</span>
                            @if(request()->anyFilled(['action', 'date_from', 'date_to']))
                                <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                                <span class="sr-only">(Filters active)</span>
                            @endif
                        </button>

                        <!-- Advanced Filter Options Dropdown -->
                        <div x-show="openFilters" 
                             @click.outside="openFilters = false" 
                             x-cloak 
                             x-transition:enter="transition ease-out duration-150 transform"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100 transform"
                             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                             x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                             class="popover-panel absolute right-0 mt-2 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="popover-title">Filter Audit Logs</h4>
                                <a href="{{ route('admin.audit_logs.index') }}" class="popover-reset">Reset</a>
                            </div>

                            <form method="GET" action="{{ route('admin.audit_logs.index') }}" class="space-y-3 text-sm">
                                @if(request('search'))
                                    <input type="hidden" name="search" value="{{ request('search') }}">
                                @endif

                                <div>
                                    <label for="filter-action" class="block font-bold text-[#6E6E73] text-sm mb-1">Event Action</label>
                                    <select id="filter-action" name="action" class="w-full min-h-[44px] px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                                        <option value="">All Actions</option>
                                        @foreach($actions as $act)
                                            <option value="{{ $act }}" {{ request('action') === $act ? 'selected' : '' }}>
                                                {{ str_replace('_', ' ', $act) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label for="filter-date-from" class="block font-bold text-[#6E6E73] text-sm mb-1">Date From</label>
                                        <x-date-picker id="filter-date-from" name="date_from" :value="request('date_from')" placeholder="Any date" />
                                    </div>
                                    <div>
                                        <label for="filter-date-to" class="block font-bold text-[#6E6E73] text-sm mb-1">Date To</label>
                                        <x-date-picker id="filter-date-to" name="date_to" :value="request('date_to')" placeholder="Any date" />
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full min-h-[44px] py-2.5 rounded-xl text-sm font-bold shadow-2xs active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                                        Apply Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-[#6E6E73] font-bold text-xs uppercase tracking-wider">
                    <tr>
                        <th scope="col" class="py-3.5 px-4 text-left">Timestamp</th>
                        <th scope="col" class="py-3.5 px-4 text-left">Event Type</th>
                        <th scope="col" class="py-3.5 px-4 text-left">Actor</th>
                        <th scope="col" class="py-3.5 px-4 text-left">Details / Summary</th>
                        <th scope="col" class="py-3.5 px-4 text-left">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($logs as $log)
                    <tr class="hover:bg-[#F2F2F7] transition-colors">
                        <!-- Timestamp -->
                        <td class="py-3 px-4 text-left text-[#6E6E73] whitespace-nowrap font-mono text-sm">
                            {{ $log->created_at ? $log->created_at->format('M d, Y g:i:s A') : '-' }}
                        </td>

                        <!-- Action Badge -->
                        <td class="py-3 px-4 text-left whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded-md text-sm font-bold inline-block {{ $log->action_badge['class'] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $log->action_badge['label'] ?? $log->action }}
                            </span>
                        </td>

                        <!-- Actor -->
                        <td class="py-3 px-4 text-left whitespace-nowrap">
                            <strong class="text-[#1D1D1F] block">{{ $log->actor_name ?? 'System' }}</strong>
                            @if($log->user)
                                <span class="text-sm text-[#6E6E73]">{{ $log->user->email }} ({{ ucfirst($log->user->role) }})</span>
                            @endif
                        </td>

                        <!-- Description -->
                        <td class="py-3 px-4 text-left text-[#1D1D1F] max-w-md">
                            <p class="leading-relaxed">{{ $log->description }}</p>
                        </td>

                        <!-- IP Address -->
                        <td class="py-3 px-4 text-left font-mono text-sm text-[#6E6E73] whitespace-nowrap">
                            {{ $log->ip_address ?? '127.0.0.1' }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="py-10 text-center text-sm text-[#6E6E73]">
                            No audit log events recorded yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $logs->links() }}
    </div>

</div>
@endsection
