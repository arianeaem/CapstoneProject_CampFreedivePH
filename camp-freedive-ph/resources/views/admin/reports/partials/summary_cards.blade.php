{{-- Summary cards shared by the report tabs (same look as the dashboard). $cards: [label, value, hint, tone?, badge?, badgeTone?] --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
    @foreach($cards as $card)
        <div class="relative overflow-hidden rounded-2xl border border-[#E5E5EA] bg-gradient-to-b from-[#780000]/[0.04] via-white to-white p-5 flex flex-col gap-2 shadow-2xs">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-[#780000]/15 via-[#9E2A2B]/8 to-transparent rounded-full blur-2xl pointer-events-none" aria-hidden="true"></div>
            <div class="relative flex items-center justify-between gap-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $card['label'] }}</span>
                @if(!empty($card['badge']))
                    <span class="px-1.5 py-0.5 rounded-md text-[11px] font-bold shrink-0 {{ $card['badgeTone'] ?? 'bg-[#F2F2F7] text-[#6E6E73]' }}">{{ $card['badge'] }}</span>
                @endif
            </div>
            <div class="relative text-2xl sm:text-3xl font-black tracking-tight break-words {{ $card['tone'] ?? 'text-[#780000]' }}">{{ $card['value'] }}</div>
            <div class="relative text-xs text-[#6E6E73]">{{ $card['hint'] }}</div>
        </div>
    @endforeach
</div>
