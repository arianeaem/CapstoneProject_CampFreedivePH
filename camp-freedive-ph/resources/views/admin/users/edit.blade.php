@extends('layouts.admin')

@section('title', 'Edit Profile: ' . $user->name . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('users.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">User Management</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Edit Staff Profile</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6 text-sm">

    <!-- Edit User Profile Form -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-7 shadow-2xs space-y-5">
        <div class="border-b border-[#E5E5EA] pb-3">
            <h1 class="text-xl font-extrabold text-[#1D1D1F] tracking-tight">Edit Staff Profile</h1>
            <p class="text-sm text-[#6E6E73] mt-0.5">
                Update account info for <strong>{{ $user->name }}</strong> ({{ $user->role_badge['label'] }}).
            </p>
        </div>

        <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <!-- Name -->
            @php
                $suffixList = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'Jr', 'Sr'];
                $rawParts = preg_split('/\s+/', trim($user->name));
                $parsedSuffix = '';
                if (count($rawParts) > 1 && in_array(end($rawParts), $suffixList, true)) {
                    $parsedSuffix = array_pop($rawParts);
                }
                $parsedFirst = $rawParts[0] ?? '';
                $parsedMiddle = '';
                $parsedLast = '';
                if (count($rawParts) === 2) {
                    $parsedLast = $rawParts[1];
                } elseif (count($rawParts) > 2) {
                    $parsedLast = array_pop($rawParts);
                    array_shift($rawParts);
                    $parsedMiddle = implode(' ', $rawParts);
                }
                $firstName = old('first_name', $parsedFirst);
                $middleName = old('middle_name', $parsedMiddle);
                $lastName = old('last_name', $parsedLast);
                $suffix = old('suffix', $parsedSuffix);
                $hasNoMiddle = old('no_middle_name', empty($middleName) ? '1' : '0') === '1';
            @endphp
            <div x-data="{ noMiddleName: {{ $hasNoMiddle ? 'true' : 'false' }} }">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label for="first_name" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                            First Name <span class="text-[#780000]">*</span>
                        </label>
                        <input type="text" 
                               name="first_name" 
                               id="first_name" 
                               value="{{ $firstName }}" 
                               required
                               placeholder="e.g. Maria Ma."
                               class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                        @error('first_name')
                            <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="middle_name" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                            Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span>
                        </label>
                        <input type="text" 
                               name="middle_name" 
                               id="middle_name" 
                               value="{{ $middleName }}" 
                               :disabled="noMiddleName"
                               placeholder="Full middle name"
                               class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white disabled:bg-gray-100 disabled:text-gray-400">
                        <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73] cursor-pointer">
                            <input type="checkbox" name="no_middle_name" value="1" x-model="noMiddleName" class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000]">
                            <span>I do not have a legal middle name</span>
                        </label>
                        @error('middle_name')
                            <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label for="last_name" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                            Last Name <span class="text-[#780000]">*</span>
                        </label>
                        <input type="text" 
                               name="last_name" 
                               id="last_name" 
                               value="{{ $lastName }}" 
                               required
                               placeholder="e.g. Santos-Concepcion or De la Cruz"
                               class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                        @error('last_name')
                            <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="suffix" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                            Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span>
                        </label>
                        <select name="suffix" id="suffix" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white font-medium">
                            <option value="" {{ empty($suffix) ? 'selected' : '' }}>None</option>
                            <option value="Jr." {{ $suffix === 'Jr.' || $suffix === 'Jr' ? 'selected' : '' }}>Jr.</option>
                            <option value="Sr." {{ $suffix === 'Sr.' || $suffix === 'Sr' ? 'selected' : '' }}>Sr.</option>
                            <option value="II" {{ $suffix === 'II' ? 'selected' : '' }}>II</option>
                            <option value="III" {{ $suffix === 'III' ? 'selected' : '' }}>III</option>
                            <option value="IV" {{ $suffix === 'IV' ? 'selected' : '' }}>IV</option>
                            <option value="V" {{ $suffix === 'V' ? 'selected' : '' }}>V</option>
                        </select>
                        @error('suffix')
                            <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                    Email Address <span class="text-[#780000]">*</span>
                </label>
                <input type="email" 
                       name="email" 
                       id="email" 
                       value="{{ old('email', $user->email) }}" 
                       required
                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                @error('email')
                    <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Phone -->
            <div>
                <label for="phone" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                    Contact Phone Number <span class="text-[#780000]">*</span>
                </label>
                <input type="tel" 
                       name="phone" 
                       id="phone" 
                       value="{{ old('phone', $user->phone) }}" 
                       required
                       placeholder="0917 123 4567"
                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white">
                @error('phone')
                    <p class="text-sm text-[#780000] font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Role & Status -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="role" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                        Assigned Role <span class="text-[#780000]">*</span>
                    </label>
                    <select name="role" id="role" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="coach" {{ old('role', $user->role) === 'coach' ? 'selected' : '' }}>Freediving Coach</option>
                        @if($currentUser->isOwner())
                            <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Camp Admin</option>
                            <option value="owner" {{ old('role', $user->role) === 'owner' ? 'selected' : '' }}>Camp Owner</option>
                        @endif
                    </select>
                </div>

                <div>
                    <label for="status" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                        Account Status <span class="text-[#780000]">*</span>
                    </label>
                    <select name="status" id="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="active" {{ old('status', $user->status) === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $user->status) === 'inactive' ? 'selected' : '' }}>Inactive / Deactivated</option>
                    </select>
                </div>
            </div>

            <!-- Optional Password Reset by Admin/Owner -->
            <div class="pt-3">
                <label for="new_password" class="block font-bold text-[#1D1D1F] mb-1.5 text-sm">
                    Assign New Temporary Password (Optional)
                </label>
                <input type="text" 
                       name="new_password" 
                       id="new_password" 
                       placeholder="Leave blank to keep existing password"
                       class="w-full px-3 py-2 rounded-lg border border-[#D1D1D6] focus:border-[#780000] text-sm font-mono text-[#1D1D1F] bg-white">
                <span class="text-sm text-[#6E6E73] mt-1 block">
                    Setting a new password will force the user to change it upon their next login.
                </span>
            </div>

            <!-- Submit Controls -->
            <div class="flex items-center justify-end gap-2 pt-3">
                <a href="{{ route('admin.users.index') }}" class="btn-secondary px-3.5 py-1.5 text-sm font-semibold">Cancel</a>
                <button type="submit" class="btn-primary px-4 py-1.5 text-sm font-bold shadow-2xs">
                    Save Profile Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Danger Zone (Delete Account) -->
    @if($user->id !== $currentUser->id && ($currentUser->isOwner() || ($currentUser->isAdmin() && $user->isCoach())))
    <div class="bg-white rounded-xl border border-[#FECACA] p-5 sm:p-6 shadow-2xs space-y-4" x-data="{ openDeleteConfirm: false }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-extrabold text-[#780000]">Danger Zone</h3>
                <p class="text-sm text-[#6E6E73] mt-0.5">Permanently delete this staff account and all associated portal access.</p>
            </div>
            <button type="button" 
                    @click="openDeleteConfirm = true" 
                    class="btn-danger px-3.5 py-2 text-sm font-bold shrink-0 self-start sm:self-auto cursor-pointer">
                Delete Account
            </button>
        </div>

        <!-- Delete Modal inside Edit Page -->
        <div x-show="openDeleteConfirm" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
            <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openDeleteConfirm = false">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <div>
                            <h3 class="text-base font-extrabold text-[#780000]">Delete {{ $user->name }}</h3>
                            <p class="text-sm text-[#6E6E73]">Irreversible permanent deletion.</p>
                        </div>
                    </div>
                    <button type="button" @click="openDeleteConfirm = false" aria-label="Close delete modal" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
                </div>

                <p class="text-sm text-[#1D1D1F]">
                    Are you sure you want to permanently delete the account for <strong>{{ $user->name }}</strong> (<span class="font-mono text-[#6E6E73]">{{ $user->email }}</span>)? This action cannot be undone.
                </p>

                <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="flex items-center justify-end gap-2">
                    @csrf
                    @method('DELETE')
                    <button type="button" @click="openDeleteConfirm = false" class="btn-secondary px-3.5 py-1.5 text-sm font-semibold cursor-pointer">Cancel</button>
                    <button type="submit" class="btn-danger px-4 py-1.5 text-sm font-bold shadow-2xs cursor-pointer">
                        Permanently Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    @endif

</div>
@endsection
