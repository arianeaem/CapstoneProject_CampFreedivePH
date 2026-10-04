@extends('layouts.admin')

@section('title', 'Reports & Analytics - Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm" x-data="{ activeTab: '{{ $activeTab }}' }">

    <!-- Page Header with Title, Description, and Actions on Right -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Reports & Analytics</h1>
        </div>

        <!-- Right Side: Export CSV & Print Summary Buttons -->
        <div class="flex items-center gap-2.5 flex-wrap" x-data="{ exportOpen: false, isPrinting: false, printReport() {
            this.isPrinting = true;
            const printUrl = '{{ (auth()->user()->isOwner() ? route('owner.reports.print') : route('admin.reports.print')) . '?' . http_build_query(['preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}';
            let iframe = document.getElementById('print_summary_frame');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'print_summary_frame';
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                document.body.appendChild(iframe);
            }
            iframe.onload = () => {
                setTimeout(() => {
                    this.isPrinting = false;
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                    } catch(e) {
                        window.open(printUrl, '_blank');
                    }
                }, 300);
            };
            iframe.src = printUrl;
        } }">
            
            <!-- Export CSV Dropdown -->
            <div class="relative">
                <button type="button" 
                        @click="exportOpen = !exportOpen" 
                        class="btn-secondary px-3.5 py-2 text-sm font-bold flex items-center gap-2">
                    <img src="{{ asset('icons/icons8-download-60.png') }}" class="w-4 h-4 shrink-0" alt="Export CSV">
                    <span>Export CSV</span>
                    <svg class="w-3 h-3 text-[#8E8E93]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>

                <div x-show="exportOpen" 
                     @click.away="exportOpen = false" 
                     x-cloak 
                     class="absolute right-0 mt-2 w-52 bg-white rounded-xl border border-[#E5E5EA] p-1.5 shadow-xl z-30 space-y-1">
                    @if($isOwner)
                        <a href="{{ (auth()->user()->isOwner() ? route('owner.reports.export') : route('admin.reports.export')) . '?' . http_build_query(['type' => 'revenue', 'preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}" 
                           class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-[#1D1D1F] hover:bg-[#F8EAEA] hover:text-[#780000] transition-colors">
                            <span>Revenue</span>
                        </a>
                    @endif

                    <a href="{{ (auth()->user()->isOwner() ? route('owner.reports.export') : route('admin.reports.export')) . '?' . http_build_query(['type' => 'batches', 'preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}" 
                       class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] transition-colors">
                        <span>Camp Batches</span>
                    </a>

                    <a href="{{ (auth()->user()->isOwner() ? route('owner.reports.export') : route('admin.reports.export')) . '?' . http_build_query(['type' => 'divers', 'preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}" 
                       class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] transition-colors">
                        <span>Guest Masterlist</span>
                    </a>
                </div>
            </div>

            <!-- Direct Print Summary Button -->
            <button type="button" 
                    @click="printReport()" 
                    :disabled="isPrinting"
                    class="btn-secondary px-3.5 py-2 text-sm font-bold flex items-center gap-2 cursor-pointer disabled:opacity-60">
                <template x-if="!isPrinting">
                    <img src="{{ asset('icons/icons8-print-60.png') }}" class="w-4 h-4 shrink-0" alt="Print Summary">
                </template>
                <template x-if="isPrinting">
                    <svg class="animate-spin w-4 h-4 text-[#780000]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </template>
                <span x-text="isPrinting ? 'Opening Print...' : 'Print Summary'"></span>
            </button>

        </div>
    </div>

    <!-- Global Date Range & Filter Bar -->
    @include('admin.reports.partials.filter_bar')

    <!-- Interactive Analytics Tab Navigation (Underline #780000 on active, No Icons) -->
    <div class="border-b border-[#E5E5EA] flex items-center gap-6 overflow-x-auto no-scrollbar">
        @if($isOwner)
            <button type="button" 
                    @click="activeTab = 'financial'"
                    class="pb-3 text-sm sm:text-sm transition-all border-b-2 whitespace-nowrap"
                    :class="activeTab === 'financial' ? 'border-[#780000] text-[#780000] font-bold' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F] hover:border-[#D1D1D6] font-semibold'">
                Revenue
            </button>
        @endif

        <button type="button" 
                @click="activeTab = 'bookings'"
                class="pb-3 text-sm sm:text-sm transition-all border-b-2 whitespace-nowrap"
                :class="activeTab === 'bookings' ? 'border-[#780000] text-[#780000] font-bold' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F] hover:border-[#D1D1D6] font-semibold'">
            Bookings & Guests
        </button>

        <button type="button" 
                @click="activeTab = 'operations'"
                class="pb-3 text-sm sm:text-sm transition-all border-b-2 whitespace-nowrap"
                :class="activeTab === 'operations' ? 'border-[#780000] text-[#780000] font-bold' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F] hover:border-[#D1D1D6] font-semibold'">
            Batches & Coach Workload
        </button>

        <button type="button" 
                @click="activeTab = 'forecast'"
                class="pb-3 text-sm sm:text-sm transition-all border-b-2 whitespace-nowrap"
                :class="activeTab === 'forecast' ? 'border-[#780000] text-[#780000] font-bold' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F] hover:border-[#D1D1D6] font-semibold'">
            Demand Forecast
        </button>
    </div>

    <!-- Tab Content Panes (Consistent Container Structure) -->
    <div class="w-full">
        <!-- Tab 1: Financial Analytics (Owner Exclusive) -->
        @if($isOwner)
            <div x-show="activeTab === 'financial'" x-cloak class="w-full transition-all">
                @include('admin.reports.partials.financial_tab')
            </div>
        @endif

        <!-- Tab 2: Bookings & Demographics -->
        <div x-show="activeTab === 'bookings'" x-cloak class="w-full transition-all">
            @include('admin.reports.partials.bookings_tab')
        </div>

        <!-- Tab 3: Batch Capacity & Coaches -->
        <div x-show="activeTab === 'operations'" x-cloak class="w-full transition-all">
            @include('admin.reports.partials.operations_tab')
        </div>

        <!-- Tab 4: Demand Forecast & Horizon Projections -->
        <div x-show="activeTab === 'forecast'" x-cloak class="w-full transition-all">
            @include('admin.reports.partials.forecast_section')
        </div>
    </div>

</div>
@endsection
