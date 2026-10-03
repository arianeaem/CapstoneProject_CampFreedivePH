@if ($paginator->hasPages() || $paginator->total() > 0)
    <div class="px-3.5 sm:px-4 py-3 bg-[#F2F2F7] border-t border-[#E5E5EA] text-sm text-[#6E6E73] rounded-b-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 select-none">
        
        <!-- Left Section / Mobile Top Row: Per Page Selector & Summary Counter -->
        <div class="flex items-center justify-between sm:justify-start gap-3 sm:gap-4 w-full sm:w-auto">
            <!-- Rows Per Page Selector -->
            <div class="flex items-center gap-2 shrink-0">
                <span class="font-medium text-[#6E6E73] text-sm whitespace-nowrap">
                    <span class="hidden min-[380px]:inline">Rows per page:</span>
                    <span class="min-[380px]:hidden">Rows:</span>
                </span>
                <div class="relative inline-flex items-center">
                    <select onchange="window.handleTablePerPageChange(this.value)"
                            aria-label="Rows per page"
                            class="pl-3 pr-7 py-1 rounded-xl border border-[#D1D1D6] bg-white text-sm font-bold text-[#1D1D1F] hover:border-[#AEAEB2] focus:border-[#780000] cursor-pointer transition-all appearance-none shadow-2xs">
                        @php
                            $currentPerPage = (int) request('per_page', $paginator->perPage() ?? 10);
                        @endphp
                        @foreach([5, 10, 15, 20, 25, 50, 100] as $size)
                            <option value="{{ $size }}" {{ $currentPerPage === $size ? 'selected' : '' }}>
                                {{ $size }}
                            </option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center text-[#6E6E73]">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Subtle vertical separator on sm+ screens -->
            <div class="hidden sm:block h-3.5 w-px bg-[#D1D1D6]"></div>

            <!-- Pagination Result Counter -->
            <div class="font-medium text-[#6E6E73] text-right sm:text-left text-sm shrink-0">
                @if ($paginator->total() > 0)
                    <span class="hidden sm:inline">Showing </span>
                    <span class="font-bold text-[#1D1D1F]">{{ $paginator->firstItem() }}</span> to <span class="font-bold text-[#1D1D1F]">{{ $paginator->lastItem() }}</span>
                    <span class="text-[#6E6E73]"> of </span>
                    <span class="font-bold text-[#1D1D1F]">{{ $paginator->total() }}</span>
                    <span class="hidden sm:inline"> results</span>
                @else
                    Showing <span class="font-bold text-[#1D1D1F]">0</span> results
                @endif
            </div>
        </div>

        <!-- Right Section / Mobile Bottom Row: Page Status & Navigation Controls -->
        <div class="flex items-center justify-between sm:justify-end gap-2.5 sm:gap-3 w-full sm:w-auto pt-2.5 sm:pt-0 border-t border-[#E5E5EA] sm:border-t-0">
            <!-- Page Summary Indicator -->
            <span class="font-medium text-[#6E6E73] text-sm shrink-0">
                Page <span class="font-bold text-[#1D1D1F]">{{ $paginator->currentPage() }}</span> 
                of <span class="font-bold text-[#1D1D1F]">{{ $paginator->lastPage() }}</span>
            </span>

            <!-- Navigation Buttons -->
            <nav role="navigation" aria-label="Pagination Navigation" class="inline-flex items-center gap-1 shrink-0">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="@lang('pagination.previous')"
                          class="inline-flex items-center justify-center px-2.5 py-1 min-h-[30px] rounded-lg border border-[#E5E5EA] bg-[#F2F2F7] text-[#8E8E93] text-sm font-semibold cursor-not-allowed select-none transition-colors">
                        <svg class="w-3.5 h-3.5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                        </svg>
                        Prev
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')"
                       class="inline-flex items-center justify-center px-2.5 py-1 min-h-[30px] rounded-lg border border-[#D1D1D6] bg-white text-[#1D1D1F] hover:bg-[#F2F2F7] hover:border-[#8E8E93] active:bg-[#E5E5EA] text-sm font-bold transition-all">
                        <svg class="w-3.5 h-3.5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                        </svg>
                        Prev
                    </a>
                @endif

                {{-- Page Number Links (condensed on mobile, visible on tablet/desktop) --}}
                <div class="hidden md:inline-flex items-center gap-1">
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true" class="px-2 py-1 text-sm text-[#8E8E93] font-bold">{{ $element }}</span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page"
                                          class="px-2.5 py-1 min-h-[30px] min-w-[30px] inline-flex items-center justify-center rounded-lg bg-[#780000] text-white text-sm font-black">
                                        {{ $page }}
                                    </span>
                                @else
                                    <a href="{{ $url }}"
                                       class="px-2.5 py-1 min-h-[30px] min-w-[30px] inline-flex items-center justify-center rounded-lg border border-[#D1D1D6] bg-white text-[#1D1D1F] hover:bg-[#F2F2F7] hover:border-[#8E8E93] active:bg-[#E5E5EA] text-sm font-bold transition-all">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                </div>

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')"
                       class="inline-flex items-center justify-center px-2.5 py-1 min-h-[30px] rounded-lg border border-[#D1D1D6] bg-white text-[#1D1D1F] hover:bg-[#F2F2F7] hover:border-[#8E8E93] active:bg-[#E5E5EA] text-sm font-bold transition-all">
                        Next
                        <svg class="w-3.5 h-3.5 ml-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                    </a>
                @else
                    <span aria-disabled="true" aria-label="@lang('pagination.next')"
                          class="inline-flex items-center justify-center px-2.5 py-1 min-h-[30px] rounded-lg border border-[#E5E5EA] bg-[#F2F2F7] text-[#8E8E93] text-sm font-semibold cursor-not-allowed select-none transition-colors">
                        Next
                        <svg class="w-3.5 h-3.5 ml-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                    </span>
                @endif
            </nav>
        </div>

    </div>
@endif

<script>
if (typeof window.handleTablePerPageChange !== 'function') {
    window.handleTablePerPageChange = function(perPage) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', perPage);
        url.searchParams.set('page', '1');
        if (window.SPARouter && typeof window.SPARouter.navigate === 'function') {
            window.SPARouter.navigate(url.toString());
        } else {
            window.location.href = url.toString();
        }
    };
}
</script>
