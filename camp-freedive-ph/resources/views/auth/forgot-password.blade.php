<!DOCTYPE html>
<html lang="en" class="h-full bg-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | Camp FreedivePH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full antialiased font-sans bg-white text-[#1D1D1F]">

<div class="min-h-screen flex flex-col lg:flex-row">
    
    <!-- Auth Brand Banner -->
    <div class="hidden lg:block lg:w-1/2 relative bg-[#1D1D1F] overflow-hidden min-h-screen">
        <img src="{{ asset('images/bg-auth.png') }}" 
             alt="Camp FreedivePH Ocean" 
             class="absolute inset-0 w-full h-full object-cover">
    </div>

    <!-- Forgot Password Form -->
    <div class="w-full lg:w-1/2 flex flex-col justify-center p-8 sm:p-12 lg:p-16 xl:p-20 bg-white min-h-screen">

        <!-- Form Container -->
        <div class="max-w-md w-full mx-auto py-8">
            
            <div class="space-y-2 mb-8">
                <h1 class="text-3xl sm:text-4xl font-bold text-[#1D1D1F] tracking-tight">Recover Password</h1>
                <p class="text-sm text-[#6E6E73]">Enter your registered staff email address to receive a secure password reset link.</p>
            </div>

            @if(session('status'))
                <div role="status" aria-live="polite" class="banner banner-success mb-6 font-semibold space-y-2">
                    <div class="flex items-start gap-3">
                        <div class="leading-relaxed">{{ session('status') }}</div>
                    </div>
                    @if(session('dev_reset_link'))
                        <div class="pt-2 border-t border-[#A7F3D0]/60">
                            <span class="font-bold text-sm uppercase tracking-wider block text-emerald-800 mb-1">Local Testing Link:</span>
                            <a href="{{ session('dev_reset_link') }}" class="font-bold underline text-[#780000] break-all block hover:text-[#500000]">
                                Open password reset form
                            </a>
                        </div>
                    @endif
                </div>
            @endif

            @if(session('error'))
                <div role="alert" aria-live="polite" class="banner banner-error mb-6 font-semibold flex items-start gap-3">
                    <div class="leading-relaxed">{{ session('error') }}</div>
                </div>
            @endif

            <form action="{{ route('password.email') }}" method="POST" class="space-y-5" data-no-spa data-native
                  x-data="{ submitting: false }" @submit="submitting = true">
                @csrf

                <!-- Email Input -->
                <div class="space-y-1.5">
                    <label for="email" class="block text-sm font-semibold text-[#1D1D1F]">
                        Registered Email
                    </label>
                    <input type="email" 
                           name="email" 
                           id="email" 
                           value="{{ old('email') }}" 
                           placeholder="name@example.com" 
                           autocomplete="email"
                           inputmode="email"
                           autocapitalize="none"
                           spellcheck="false"
                           required 
                           autofocus
                           class="w-full px-4 py-3 rounded-xl border {{ $errors->has('email') ? 'border-[#FF3B3C] bg-rose-50/10' : 'border-[#D1D1D6]' }} focus:border-[#780000] text-sm text-[#1D1D1F] bg-white transition-all">
                    
                    @error('email')
                        <p class="text-sm text-[#FF3B3C] font-semibold mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Submit Button -->
                <button type="submit" 
                        :disabled="submitting"
                        class="btn-primary w-full py-3.5 min-h-[44px] text-sm font-bold shadow-md hover:bg-[#5E0000] focus:outline-none focus:ring-2 focus:ring-[#780000] focus:ring-offset-2 transition-all cursor-pointer flex items-center justify-center gap-2 disabled:opacity-75 disabled:cursor-not-allowed">
                    <svg x-show="submitting" x-cloak class="animate-spin w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke-opacity="1"></path></svg>
                    <span x-text="submitting ? 'Sending link…' : 'Send Password Reset Link'">Send Password Reset Link</span>
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-[#E5E5EA] text-center">
                <a href="{{ route('login') }}" class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 text-sm font-bold text-[#780000] hover:underline gap-1.5 focus:outline-none focus:ring-2 focus:ring-[#780000]/30 rounded-lg" data-no-spa>
                    <svg class="w-3.5 h-3.5" style="transform: translateY(1px);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Return to Login</span>
                </a>
            </div>
        </div>

    </div>

</div>

</body>
</html>
