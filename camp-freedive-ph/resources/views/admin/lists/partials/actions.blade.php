{{-- Export Excel / Print for a management list. Keeps the current filters and sort, not the page. --}}
@php $listQuery = request()->except(['page', 'per_page']); @endphp
<a href="{{ portal_route($module . '.export', $listQuery) }}" class="btn-secondary px-3.5 py-2 text-sm font-semibold flex items-center gap-2">
    <span>Export Excel</span>
</a>
<a href="{{ portal_route($module . '.print', $listQuery) }}" target="_blank" rel="noopener" class="btn-secondary px-3.5 py-2 text-sm font-semibold flex items-center gap-2">
    <span>{{ $printLabel }}</span>
</a>
