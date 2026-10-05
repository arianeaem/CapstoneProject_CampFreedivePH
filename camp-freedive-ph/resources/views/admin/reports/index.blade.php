@extends('layouts.admin')

@section('title', 'Reports & Analytics - Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm" x-data="{ activeTab: '{{ $activeTab }}' }">

    <!-- Page Header with Title, Description, and Actions on Right -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Reports & Analytics</h1>
        </div>

        <!-- Right side: Download Excel & Print summary -->
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
            
            <!-- Download Excel menu -->
            @php
                $exportBase = (auth()->user()->isOwner() ? route('owner.reports.export') : route('admin.reports.export'));
                $exportQuery = ['preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')];
                $exports = array_filter([
                    'revenue' => $isOwner ? ['Revenue', 'Money summary, by package, by month, and every payment'] : null,
                    'bookings' => ['Bookings', 'Booking summary, every booking and every diver'],
                    'batches' => ['Batches & Coaches', 'Batch summary, every batch and every coach'],
                ]);
            @endphp
            <div class="relative" @keydown.escape.window="exportOpen = false">
                <button type="button" @click="exportOpen = !exportOpen" :aria-expanded="exportOpen"
                        class="btn-secondary min-h-[40px] px-3.5 py-2 text-sm font-bold inline-flex items-center gap-2">
                    <span>Download Excel</span>
                    <svg class="w-3.5 h-3.5 text-[#8E8E93] transition-transform" :class="exportOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>

                <div x-show="exportOpen" @click.outside="exportOpen = false" x-cloak
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                     class="menu-panel absolute right-0 mt-2 w-72">
                    <p class="px-3 pt-1.5 pb-1 text-[11px] font-bold uppercase tracking-wider text-[#8E8E93]">{{ $range['label'] }}</p>
                    @foreach($exports as $type => [$label, $hint])
                        <a href="{{ $exportBase . '?' . http_build_query(['type' => $type] + $exportQuery) }}" @click="exportOpen = false" class="menu-item">
                            <span>
                                {{ $label }}
                                <span class="menu-item-hint">{{ $hint }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            <!-- Print summary (one print dialog: the hidden frame does not print itself) -->
            <button type="button" @click="printReport()" :disabled="isPrinting"
                    class="btn-secondary min-h-[40px] px-3.5 py-2 text-sm font-bold inline-flex items-center gap-2 cursor-pointer disabled:opacity-60">
                <span x-text="isPrinting ? 'Preparing...' : 'Print summary'"></span>
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
            Bookings
        </button>

        <button type="button" 
                @click="activeTab = 'operations'"
                class="pb-3 text-sm sm:text-sm transition-all border-b-2 whitespace-nowrap"
                :class="activeTab === 'operations' ? 'border-[#780000] text-[#780000] font-bold' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F] hover:border-[#D1D1D6] font-semibold'">
            Batches & Coaches
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
    </div>

</div>
@endsection
