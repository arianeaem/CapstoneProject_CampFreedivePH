{{--
    Side panel that slides in from the right. Every panel in the app uses it so they look and work the same.

    Layout:
      Header  - small label (optional), title, one-line description (optional), close button
      Body    - scrolls on its own
      Footer  - stays at the bottom; "Cancel" on the left, the main action on the right, same width

    Closes with the close button, the Esc key, a click outside, or the footer "Cancel" button.
    Set dismissible="false" for panels the user must act on (e.g. session expired).

    Usage:
      <x-side-panel show="openMoveModal" eyebrow="Booking" title="Move booking" description="Pick another batch.">
          <form id="move-booking-form" ...> fields </form>
          <x-slot:footer>
              <button type="button" class="btn-secondary" @click="openMoveModal = false">Cancel</button>
              <button type="submit" form="move-booking-form" class="btn-primary">Move booking</button>
          </x-slot:footer>
      </x-side-panel>
--}}
@props([
    'show',
    'title' => null,
    'eyebrow' => null,
    'description' => null,
    'size' => 'md',
    'dismissible' => true,
    'role' => 'dialog',
])

@php
    $panelId = 'side-panel-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));
    $width = $size === 'lg' ? 'sm:max-w-lg' : 'sm:max-w-md';
@endphp

<div x-show="{{ $show }}" x-cloak
     class="fixed inset-0 z-50 flex justify-end bg-black/40"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @if($dismissible) @keydown.escape.window="{{ $show }} = false" @endif>
    <div {{ $attributes->class(['dive-side-panel flex h-full w-full flex-col bg-white shadow-2xl focus:outline-none sm:rounded-l-2xl', $width]) }}
         role="{{ $role }}"
         aria-modal="true"
         aria-labelledby="{{ $panelId }}-title"
         tabindex="-1"
         x-effect="if ({{ $show }}) $nextTick(() => $el.focus())"
         @if($dismissible) @click.outside="{{ $show }} = false" @endif>

        <header class="flex items-start justify-between gap-4 border-b border-[#E5E5EA] px-5 py-4 sm:px-6">
            <div class="min-w-0 space-y-0.5">
                @if($eyebrow)
                    <p class="side-panel-eyebrow">{{ $eyebrow }}</p>
                @endif
                <h2 id="{{ $panelId }}-title" class="text-lg font-bold leading-snug text-[#1D1D1F] break-words">{{ $title }}</h2>
                @if($description)
                    <p class="text-sm text-[#6E6E73]">{{ $description }}</p>
                @endif
            </div>
            @if($dismissible)
                <button type="button"
                        @click="{{ $show }} = false"
                        aria-label="Close panel"
                        class="-mr-2 -mt-1 flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full text-[#6E6E73] transition-colors hover:bg-[#F2F2F7] hover:text-[#1D1D1F] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            @endif
        </header>

        <div class="flex-1 space-y-5 overflow-y-auto overscroll-contain px-5 py-5 text-sm text-[#1D1D1F] sm:px-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer {{ $footer->attributes->class(['side-panel-footer border-t border-[#E5E5EA] bg-white px-5 py-4 sm:px-6']) }}>
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
