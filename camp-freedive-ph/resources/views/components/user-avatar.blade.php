{{--
    Profile photo, or the user's initials when there is no photo.

    Usage:
        <x-user-avatar :user="$user" />
        <x-user-avatar :user="$coach" class="w-16 h-16 text-xl" />
--}}
@props(['user'])
@php
    $url = $user?->avatar_url;
    $initials = $user?->initials ?? 'U';
@endphp
@if($url)
    <img src="{{ $url }}" alt="{{ $user->name }}"
         {{ $attributes->merge(['class' => 'w-9 h-9 rounded-full object-cover border-2 border-[#780000] shrink-0']) }}>
@else
    <div aria-label="{{ $user?->name }}" role="img"
         {{ $attributes->merge(['class' => 'w-9 h-9 rounded-full bg-[#F8EAEA] border-2 border-[#780000] text-[#780000] flex items-center justify-center font-bold text-sm shrink-0']) }}>
        {{ $initials }}
    </div>
@endif
