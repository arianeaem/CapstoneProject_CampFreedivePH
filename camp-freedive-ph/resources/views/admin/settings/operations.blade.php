@extends('layouts.admin')

@section('title', 'Camp Capacity | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('owner.settings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Settings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Camp Capacity</span>
@endsection

@section('content')
<div class="space-y-6 text-sm pb-16">
    
    <!-- Top Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Camp Capacity</h1>
        <p class="text-[#6E6E73] text-sm mt-1">
            Configure weekend trip diver capacity limits, safety coaching ratios, and minimum instructor dispatch rules.
        </p>
    </div>

    @if($errors->any())
        <div class="banner banner-error flex items-start gap-3">
            <div>
                <p class="font-bold text-sm">Please correct the errors below:</p>
                <ul class="list-disc list-inside text-xs text-rose-700 mt-1 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('owner.settings.operations.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- Max Batch Capacity -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Maximum Guests per Batch</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Total number of divers allowed across all programs for a single weekend batch.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="max_batch_capacity" class="block font-bold text-xs text-[#1D1D1F]">
                        Capacity Limit (Pax)
                    </label>
                    <input type="number" 
                           id="max_batch_capacity" 
                           name="max_batch_capacity" 
                           value="{{ old('max_batch_capacity', $settings['max_batch_capacity']) }}" 
                           min="5" 
                           max="200" 
                           required
                           class="w-full min-h-[44px] px-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    <p class="text-[11px] text-[#8E8E93]">Strict Coast Guard outrigger banca ceiling.</p>
                </div>
            </div>

            <!-- Coach to Student Ratio -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Coach to Student Ratio</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Standard safety allocation ratio in student matching queue.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="coach_student_ratio" class="block font-bold text-xs text-[#1D1D1F]">
                        Students per 1 Coach
                    </label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-[#6E6E73]">1 :</span>
                        <input type="number" 
                               id="coach_student_ratio" 
                               name="coach_student_ratio" 
                               value="{{ old('coach_student_ratio', $settings['coach_student_ratio']) }}" 
                               min="1" 
                               max="20" 
                               required
                               class="w-full min-h-[44px] px-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    </div>
                    <p class="text-[11px] text-[#8E8E93]">Standard is 1 coach per 4 students.</p>
                </div>
            </div>

            <!-- Minimum Coaches -->
            <div class="bg-white p-6 rounded-xl border border-[#E5E5EA] shadow-2xs space-y-4">
                <div class="space-y-1">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Minimum Staff Coaches</h2>
                    <p class="text-xs text-[#6E6E73] leading-relaxed">
                        Minimum confirmed coaches required before a batch is cleared for operation.
                    </p>
                </div>

                <div class="space-y-1.5 pt-2">
                    <label for="min_coaches_per_batch" class="block font-bold text-xs text-[#1D1D1F]">
                        Minimum Coaches
                    </label>
                    <input type="number" 
                           id="min_coaches_per_batch" 
                           name="min_coaches_per_batch" 
                           value="{{ old('min_coaches_per_batch', $settings['min_coaches_per_batch']) }}" 
                           min="1" 
                           max="10" 
                           required
                           class="w-full min-h-[44px] px-3.5 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white font-semibold text-[#1D1D1F] focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                    <p class="text-[11px] text-[#8E8E93]">Standard is 2 instructors.</p>
                </div>
            </div>

        </div>

        <!-- Bottom Action Bar -->
        <div class="flex items-center justify-end gap-3 pt-4">
            <a href="{{ route('owner.settings.index') }}" class="px-4 py-2 rounded-xl text-sm font-semibold text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all">
                Cancel
            </a>
            <button type="submit" class="btn-primary min-h-[44px] px-6 py-2.5 rounded-xl text-sm font-bold bg-[#780000] text-white hover:bg-[#5E0000] shadow-2xs active:scale-[0.98] transition-all flex items-center gap-2">
                <span>Save Changes</span>
            </button>
        </div>

    </form>

</div>
@endsection
