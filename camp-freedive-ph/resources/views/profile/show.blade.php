@extends('layouts.admin')

@section('title', 'My Profile | Camp FreedivePH')

@section('breadcrumb')
    <span class="font-bold text-[#1D1D1F]">My Profile</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6 text-sm"
     x-data="{
         preview: null,
         photo: '',
         error: '',
         // Crop to a centred square and shrink to 256x256 JPEG before uploading
         pick(event) {
             this.error = '';
             const file = event.target.files[0];
             if (!file) return;
             if (!file.type.startsWith('image/')) { this.error = 'Please choose an image file.'; return; }
             if (file.size > 10 * 1024 * 1024) { this.error = 'Please choose an image under 10 MB.'; return; }
             const img = new Image();
             img.onload = () => {
                 const side = Math.min(img.width, img.height);
                 const canvas = document.createElement('canvas');
                 canvas.width = canvas.height = 256;
                 canvas.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, 256, 256);
                 this.photo = canvas.toDataURL('image/jpeg', 0.85);
                 this.preview = this.photo;
                 URL.revokeObjectURL(img.src);
             };
             img.onerror = () => { this.error = 'That image could not be read. Please try another photo.'; };
             img.src = URL.createObjectURL(file);
         }
     }">

    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">My Profile</h1>
        <p class="text-sm text-[#6E6E73] mt-1">Your photo appears in the top bar and on your dashboard. Without a photo, your initials are shown.</p>
    </div>

    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 space-y-5">
        <div class="flex items-center gap-4">
            <template x-if="preview">
                <img :src="preview" alt="New profile photo preview" class="w-20 h-20 rounded-full object-cover border-2 border-[#780000] shrink-0">
            </template>
            <template x-if="!preview">
                <x-user-avatar :user="$user" class="w-20 h-20 text-2xl" />
            </template>
            <div class="min-w-0">
                <div class="font-bold text-base text-[#1D1D1F] truncate">{{ $user->name }}</div>
                <div class="text-[#6E6E73] truncate">{{ $user->email }}</div>
                <span class="inline-block mt-1 px-2.5 py-0.5 rounded-md text-xs font-bold bg-[#F8EAEA] text-[#780000]">{{ $user->role_label ?? ucfirst($user->role) }}</span>
            </div>
        </div>

        @if($errors->has('photo'))
            <div class="banner banner-error" role="alert">{{ $errors->first('photo') }}</div>
        @endif
        <div x-show="error" x-cloak class="banner banner-error" role="alert" x-text="error"></div>

        <form action="{{ route('profile.photo.update') }}" method="POST" class="space-y-3" data-native>
            @csrf
            <input type="hidden" name="photo" :value="photo">
            <label class="block">
                <span class="block font-bold text-[#1D1D1F] mb-1.5">Choose a photo</span>
                <input type="file" accept="image/jpeg,image/png,image/webp" @change="pick($event)"
                       class="block w-full text-sm text-[#6E6E73] file:mr-3 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-[#F8EAEA] file:text-[#780000] file:font-bold hover:file:bg-[#F2DCDC]">
                <span class="block text-xs text-[#8E8E93] mt-1">JPG, PNG or WebP. It's cropped to a square and resized automatically.</span>
            </label>
            <button type="submit" :disabled="!photo" class="btn-primary min-h-[44px] px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-50 disabled:cursor-not-allowed">
                Save photo
            </button>
        </form>

        @if($user->avatar_url)
            <form action="{{ route('profile.photo.destroy') }}" method="POST" class="pt-4 border-t border-[#F2F2F7]" data-native>
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm font-bold text-[#B91C1C] hover:underline">Remove photo and use my initials</button>
            </form>
        @endif
    </div>
</div>
@endsection
