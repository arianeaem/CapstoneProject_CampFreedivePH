{{--
    "1st booking" / "3rd booking" next to a participant's name, linked to their history.

    Usage: <x-participant-history-badge :bp="$bookingParticipant" />
--}}
@props(['bp'])
@php
    $user = auth()->user();
    $n = $bp?->participant_id ? app(\App\Services\ParticipantDirectoryService::class)->diveNumber($bp) : null;
    $ordinal = $n ? $n . (in_array($n % 100, [11, 12, 13]) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th')) : null;
    $url = null;
    if ($n && $user) {
        $url = $user->isCoach()
            ? route('coach.participants.show', $bp->participant_id)
            : portal_route('participants.show', $bp->participant_id);
    }
@endphp
@if($ordinal)
    <a href="{{ $url }}" onclick="event.stopPropagation()" title="Open this participant's history"
       {{ $attributes->merge(['class' => 'inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold whitespace-nowrap hover:underline ' . ($n > 1 ? 'bg-[#F8EAEA] text-[#780000]' : 'bg-[#F2F2F7] text-[#6E6E73]')]) }}>
        {{ $ordinal }} booking
    </a>
@endif
