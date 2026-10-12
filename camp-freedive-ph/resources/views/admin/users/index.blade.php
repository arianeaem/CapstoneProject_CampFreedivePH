@extends('layouts.admin')

@section('title', 'User Management | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm" 
     x-data="{ 
         openAddModal: false, 
         openDeleteModal: false,
         deleteUser: { id: null, name: '', email: '', role: '', url: '' },
         showCredentialsModal: {{ session('new_user_credentials') ? 'true' : 'false' }},
         showPassword: false,
         copiedEmail: false,
         copiedPassword: false,
         copiedAll: false,
         confirmDelete(user) {
             this.deleteUser = user;
             this.openDeleteModal = true;
         },
         copyToClipboard(text, type) {
             navigator.clipboard.writeText(text).then(() => {
                 if (type === 'email') {
                     this.copiedEmail = true;
                     setTimeout(() => this.copiedEmail = false, 2000);
                 } else if (type === 'password') {
                     this.copiedPassword = true;
                     setTimeout(() => this.copiedPassword = false, 2000);
                 } else if (type === 'all') {
                     this.copiedAll = true;
                     setTimeout(() => this.copiedAll = false, 2500);
                 }
             }).catch(() => {
                 // Fallback if clipboard API is restricted
                 const textarea = document.createElement('textarea');
                 textarea.value = text;
                 document.body.appendChild(textarea);
                 textarea.select();
                 document.execCommand('copy');
                 document.body.removeChild(textarea);
                 if (type === 'all') {
                     this.copiedAll = true;
                     setTimeout(() => this.copiedAll = false, 2500);
                 } else if (type === 'password') {
                     this.copiedPassword = true;
                     setTimeout(() => this.copiedPassword = false, 2000);
                 } else if (type === 'email') {
                     this.copiedEmail = true;
                     setTimeout(() => this.copiedEmail = false, 2000);
                 }
             });
         }
     }">
    
    @if(session('blocked_coach'))
        @php $blocked = session('blocked_coach'); @endphp
        <div class="banner banner-warning space-y-3" role="alert">
            <div>
                <p class="font-extrabold text-[#92400E]">{{ $blocked['name'] }} can't be {{ $blocked['action'] }} yet</p>
                <p class="text-[#92400E] mt-0.5">They still have the upcoming batches below. Reassign their participants to other coaches (or remove them from the coach team) first, then try again.</p>
            </div>
            <ul class="divide-y divide-[#FDE68A] rounded-lg border border-[#FDE68A] bg-white">
                @foreach($blocked['batches'] as $b)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span><strong class="text-[#1D1D1F]">{{ $b['batch_number'] }}</strong> <span class="text-[#6E6E73]">&middot; {{ $b['date'] }}</span></span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-[#FFFBEB] text-[#92400E]">
                            {{ $b['team_only'] ? 'On the coach team' : $b['students'] . ' ' . Str::plural('participant', $b['students']) . ' to reassign' }}
                        </span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('admin.coaches.matching') }}" class="btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-bold shadow-2xs">
                Go to Coach Matching to reassign
            </a>
        </div>
    @endif

    <!-- Top Header & Add User Action -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">User Management</h1>
        </div>

        <div>
            <button type="button" 
                    @click="openAddModal = true"
                    class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-1.5 shadow-2xs">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span>New Account</span>
            </button>
        </div>
    </div>

    <!-- Staff Summary Metrics -->
    @if(isset($stats))
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-2.5 sm:p-4 shadow-2xs">
        <div class="grid grid-cols-2 lg:grid-cols-4 items-center gap-2 sm:gap-4">
            <!-- Total Staff -->
            <div class="px-2 sm:px-4 py-1">
                <span class="text-sm sm:text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Total Staff</span>
                <div class="text-lg sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['total'] }}</div>
            </div>

            <!-- Coaches -->
            <div class="relative px-2 sm:px-4 py-1 border-l border-[#E5E5EA] sm:border-l-0">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Coaches</span>
                <div class="text-lg sm:text-2xl font-extrabold text-[#780000] mt-0.5">{{ $stats['coaches'] }}</div>
            </div>

            <!-- Admins & Owners -->
            <div class="relative px-2 sm:px-4 py-1 pt-2 sm:pt-1 border-t lg:border-t-0 border-[#E5E5EA]">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Admins & Owners</span>
                <div class="text-lg sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['admins'] }}</div>
            </div>

            <!-- Active Accounts -->
            <div class="relative px-2 sm:px-4 py-1 pt-2 sm:pt-1 border-t lg:border-t-0 border-l border-[#E5E5EA] sm:border-l-0">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm text-[#6E6E73] font-bold uppercase tracking-wider block truncate">Active Accounts</span>
                <div class="text-lg sm:text-2xl font-extrabold text-emerald-700 mt-0.5">{{ $stats['active'] }}</div>
            </div>
        </div>
    </div>
    @endif

    <!-- Staff Directory Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        
        <!-- Table Toolbar Header -->
        <div class="p-2.5 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-2.5 sm:gap-3">
                
                <!-- Role Filter Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none -mx-0.5 px-0.5">
                    <a href="{{ request()->fullUrlWithQuery(['role' => '']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ !request('role') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Staff
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['role' => 'coach']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('role') === 'coach' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Coaches
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['role' => 'admin']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('role') === 'admin' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Admins
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['role' => 'owner']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('role') === 'owner' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Owners
                    </a>
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ route('admin.users.index') }}" class="flex-1 min-w-0 lg:flex-initial">
                        @if(request('role'))
                            <input type="hidden" name="role" value="{{ request('role') }}">
                        @endif
                        @if(request('status'))
                            <input type="hidden" name="status" value="{{ request('status') }}">
                        @endif

                        <div class="relative w-full sm:w-64">
                            <input type="text" 
                                   name="search" 
                                   value="{{ request('search') }}" 
                                   placeholder="Search name, email, phone..." 
                                   class="w-full pl-8 pr-3 py-1.5 text-sm rounded-lg border border-[#D1D1D6] bg-white focus:bg-white focus:border-[#780000]">
                            <svg class="w-3.5 h-3.5 text-[#8E8E93] absolute left-2.5 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                    </form>

                    <!-- Filter Controls -->
                    <div class="relative shrink-0">
                        <button type="button" 
                                @click="openFilters = !openFilters" 
                                class="btn-secondary flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm font-semibold whitespace-nowrap shrink-0 cursor-pointer">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                            <span class="whitespace-nowrap">Filter</span>
                            @if(request()->filled('status'))
                                <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                            @endif
                        </button>

                        <!-- Filter Form Dropdown -->
                        <div x-show="openFilters" 
                             @click.outside="openFilters = false" 
                             x-cloak 
                             x-transition:enter="transition ease-out duration-150 transform"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100 transform"
                             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                             x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                             class="popover-panel absolute right-0 mt-2 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="popover-title">Filter Staff</h4>
                                <a href="{{ route('admin.users.index') }}" class="popover-reset">Reset</a>
                            </div>

                            <form method="GET" action="{{ route('admin.users.index') }}" class="space-y-3 text-sm">
                                @if(request('role'))
                                    <input type="hidden" name="role" value="{{ request('role') }}">
                                @endif
                                @if(request('search'))
                                    <input type="hidden" name="search" value="{{ request('search') }}">
                                @endif

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Account Status</label>
                                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Statuses</option>
                                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                        <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Removed</option>
                                    </select>
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full py-2 text-sm font-bold shadow-2xs">
                                        Apply Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-[#6E6E73] font-bold">
                    <tr>
                        <th class="py-3 px-4 text-left">Staff Member</th>
                        <th class="py-3 px-4 text-left">Contact Phone</th>
                        <th class="py-3 px-4 text-left">Role</th>
                        <th class="py-3 px-4 text-left">Status</th>
                        <th class="py-3 px-4 text-left">Last Login</th>
                        <th class="py-3 px-4 text-right pr-6">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($users as $user)
                    <tr onclick="window.location='{{ route('admin.users.edit', $user) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors group">
                        <!-- Staff Details -->
                        <td class="py-3 px-4 text-left">
                            <div class="font-bold text-sm text-[#1D1D1F] group-hover:text-[#780000]">
                                {{ $user->name }}
                                @if($user->id === $currentUser->id)
                                    <span class="text-sm px-1.5 py-0.2 rounded bg-gray-100 text-gray-700 font-bold ml-1">You</span>
                                @endif
                            </div>
                            <span class="text-sm text-[#6E6E73] block mt-0.5">{{ $user->email }}</span>
                        </td>

                        <!-- Phone Number -->
                        <td class="py-3 px-4 text-left text-[#1D1D1F]">
                            {{ $user->phone ?? '-' }}
                        </td>

                        <!-- Role Badge -->
                        <td class="py-3 px-4 text-left">
                            <span class="px-2 py-0.5 rounded-md text-sm font-bold inline-block {{ $user->role_badge['class'] }}">
                                {{ $user->role_badge['label'] }}
                            </span>
                        </td>

                        <!-- Status Badge -->
                        <td class="py-3 px-4 text-left">
                            @if($user->isActive())
                                <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-emerald-50 text-emerald-700 inline-block">
                                    Active
                                </span>
                            @elseif($user->isArchived())
                                <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#E5E5EA] text-[#3A3A3C] inline-block">
                                    Removed
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-rose-50 text-rose-700 inline-block">
                                    Inactive
                                </span>
                            @endif
                        </td>

                        <!-- Last Login Timestamp -->
                        <td class="py-3 px-4 text-left text-[#6E6E73] whitespace-nowrap">
                            {{ $user->last_login_at ? $user->last_login_at->format('M d, Y g:i A') : 'Never logged in' }}
                        </td>

                        <!-- Actions Menu -->
                        <td class="py-3 px-4 text-right pr-6 whitespace-nowrap" onclick="event.stopPropagation()">
                            @if($currentUser->isOwner() || ($currentUser->isAdmin() && $user->isCoach()))
                                <div class="relative inline-block text-left" x-data="{ openMenu: false }">
                                    <button type="button" 
                                            @click="openMenu = !openMenu" 
                                            class="w-8 h-8 rounded-lg flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-colors cursor-pointer"
                                            title="Actions">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="12" cy="5" r="2"></circle>
                                            <circle cx="12" cy="12" r="2"></circle>
                                            <circle cx="12" cy="19" r="2"></circle>
                                        </svg>
                                    </button>

                                    <!-- Actions Dropdown Menu -->
                                    <div x-show="openMenu" 
                                         @click.outside="openMenu = false" 
                                         x-cloak 
                                         x-transition:enter="transition ease-out duration-150 transform"
                                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-100 transform"
                                         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                         x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                                         class="menu-panel absolute right-0 mt-2">
                                        
                                        <!-- Edit Account -->
                                        <a href="{{ route('admin.users.edit', $user) }}" 
                                           class="menu-item">
                                            <span>Edit Profile</span>
                                        </a>

                                        <!-- Account Status Toggle -->
                                        @if($user->id !== $currentUser->id)
                                            <form action="{{ route('admin.users.toggle_status', $user) }}" method="POST" class="block w-full">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" 
                                                        onclick="return confirm('Are you sure you want to {{ $user->isActive() ? 'deactivate' : ($user->isArchived() ? 'restore' : 'activate') }} this account?')"
                                                        class="menu-item">
                                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                                                    <span>{{ $user->isActive() ? 'Deactivate' : ($user->isArchived() ? 'Restore Account' : 'Activate') }}</span>
                                                </button>
                                            </form>
                                            
                                            @unless($user->isArchived())
                                            <div class="menu-divider"></div>

                                            <!-- Remove (archive) User Option -->
                                            <button type="button" 
                                                    @click="confirmDelete({
                                                        id: {{ $user->id }},
                                                        name: '{{ addslashes($user->name) }}',
                                                        email: '{{ addslashes($user->email) }}',
                                                        role: '{{ addslashes($user->role_badge['label']) }}',
                                                        url: '{{ route('admin.users.destroy', $user) }}'
                                                    }); openMenu = false;"
                                                    class="menu-item menu-item-danger">
                                                <svg class="w-3.5 h-3.5 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                <span>Remove Account</span>
                                            </button>
                                            @endunless
                                        @endif
                                    </div>
                                </div>
                            @else
                                <span class="text-sm text-[#8E8E93] italic">Owner only</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-sm text-[#8E8E93]">
                            No staff accounts found matching your search.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </div>

    <!-- Create Staff Account Panel -->
    <x-side-panel show="openAddModal" size="lg"
                  eyebrow="Staff"
                  title="Create staff account"
                  description="Add a coach or staff member and set up their login.">
        <form id="create-staff-form" action="{{ route('admin.users.store') }}" method="POST" class="space-y-4">
            @csrf

            <div x-data="{ noMiddleName: false }" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="staff-first-name" class="form-label form-label-required">First name</label>
                        <input id="staff-first-name" type="text" name="first_name" required placeholder="e.g. Maria" class="form-input">
                    </div>
                    <div>
                        <label for="staff-middle-name" class="form-label">Middle name (optional)</label>
                        <input id="staff-middle-name" type="text" name="middle_name" :disabled="noMiddleName" placeholder="Full middle name" class="form-input disabled:bg-[#F2F2F7] disabled:text-[#8E8E93]">
                        <label class="mt-1.5 inline-flex cursor-pointer items-center gap-1.5 text-sm text-[#6E6E73]">
                            <input type="checkbox" name="no_middle_name" value="1" x-model="noMiddleName" class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000]">
                            <span>No legal middle name</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="staff-last-name" class="form-label form-label-required">Last name</label>
                        <input id="staff-last-name" type="text" name="last_name" required placeholder="e.g. De la Cruz" class="form-input">
                    </div>
                    <div>
                        <label for="staff-suffix" class="form-label">Suffix (optional)</label>
                        <select id="staff-suffix" name="suffix" class="form-select w-full">
                            <option value="">None</option>
                            <option value="Jr.">Jr.</option>
                            <option value="Sr.">Sr.</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                            <option value="V">V</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label for="staff-email" class="form-label form-label-required">Email (used to sign in)</label>
                <input id="staff-email" type="email" name="email" required placeholder="name@campfreedive.ph" class="form-input">
            </div>

            <div>
                <label for="staff-phone" class="form-label form-label-required">Phone number</label>
                <input id="staff-phone" type="tel" name="phone" required placeholder="0917 123 4567" class="form-input">
            </div>

            <div>
                <label for="staff-role" class="form-label form-label-required">Role</label>
                <select id="staff-role" name="role" required class="form-select w-full">
                    <option value="coach" selected>Freediving Coach (Coach Portal Access)</option>
                    @if($currentUser->isOwner())
                        <option value="admin">Camp Admin (Operations & Booking Coordinator)</option>
                    @endif
                </select>
            </div>

            <div>
                <label for="staff-temp-password" class="form-label">Temporary password (optional)</label>
                <input id="staff-temp-password" type="text" name="temp_password" placeholder="Leave blank to generate one" class="form-input">
                <span class="form-hint">They will be asked to change it the first time they sign in.</span>
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openAddModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="create-staff-form" class="btn-primary">Create account</button>
        </x-slot:footer>
    </x-side-panel>

    <!-- ==================================================================== -->
    <!-- NEWLY PROVISIONED / RESET CREDENTIALS MODAL -->
    <!-- ==================================================================== -->
    @if(session('new_user_credentials'))
    @php
        $creds = session('new_user_credentials');
        $fullDetails = "Camp FreedivePH - Staff Account Credentials\n"
            . "Name: " . ($creds['name'] ?? '') . "\n"
            . "Role: " . ($creds['role_label'] ?? ucfirst($creds['role'] ?? 'Staff')) . "\n"
            . "Portal Login: " . ($creds['login_url'] ?? url('/login')) . "\n"
            . "Email (Username): " . ($creds['email'] ?? '') . "\n"
            . "Temporary Password: " . ($creds['temp_password'] ?? '') . "\n\n"
            . "(Note: You will be required to change your temporary password upon your first login.)";
    @endphp
    <x-side-panel show="showCredentialsModal" size="lg"
                  :eyebrow="$creds['name'] . ' · ' . $creds['role_label']"
                  :title="!empty($creds['is_reset']) ? 'Password reset' : 'Account created'"
                  description="Copy these login details and send them to the staff member.">
        <div class="banner banner-success">
            {{ !empty($creds['is_reset']) ? 'The password was reset.' : 'The account is ready.' }} They must change this temporary password the first time they sign in.
        </div>

        <div class="space-y-4">
            <div>
                <label for="cred-email" class="form-label">Email (used to sign in)</label>
                <div class="flex items-center gap-2">
                    <input id="cred-email" type="text" readonly value="{{ $creds['email'] }}" class="form-input font-mono select-all">
                    <button type="button"
                            @click="copyToClipboard('{{ addslashes($creds['email']) }}', 'email')"
                            class="btn-secondary shrink-0 whitespace-nowrap">
                        <span x-text="copiedEmail ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <label for="cred-password" class="form-label mb-0">Temporary password</label>
                    <button type="button" @click="showPassword = !showPassword" class="cursor-pointer text-sm font-semibold text-[#780000] hover:underline">
                        <span x-text="showPassword ? 'Hide' : 'Show'"></span>
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <input id="cred-password" :type="showPassword ? 'text' : 'password'" readonly value="{{ $creds['temp_password'] }}" class="form-input font-mono font-bold tracking-wider select-all">
                    <button type="button"
                            @click="copyToClipboard('{{ addslashes($creds['temp_password']) }}', 'password')"
                            class="btn-secondary shrink-0 whitespace-nowrap">
                        <span x-text="copiedPassword ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>
            </div>

            <div>
                <span class="form-label">Sign-in page</span>
                <span class="block truncate rounded-xl border border-[#E5E5EA] bg-[#F2F2F7] px-3.5 py-2.5 font-mono text-sm text-[#6E6E73]">
                    {{ $creds['login_url'] }}
                </span>
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="showCredentialsModal = false" class="btn-secondary">Done</button>
            <button type="button" @click="copyToClipboard({{ json_encode($fullDetails) }}, 'all')" class="btn-primary">
                <span x-text="copiedAll ? 'Copied!' : 'Copy all details'"></span>
            </button>
        </x-slot:footer>
    </x-side-panel>
    @endif

    <!-- Remove Staff Account Panel -->
    <x-side-panel show="openDeleteModal" role="alertdialog" eyebrow="Staff account" title="Remove account">
        <x-slot:description><span x-text="deleteUser.name"></span> · <span x-text="deleteUser.email"></span></x-slot:description>

        <div class="panel-context">
            <p class="panel-section-title">What happens</p>
            <ul class="list-disc space-y-1 pl-5 text-[#3A3A3C]">
                <li>They can no longer sign in.</li>
                <li>Their records (bookings, dives coached, history) are <strong>kept</strong>.</li>
                <li>They get an email saying their account was removed.</li>
                <li>Coaches with upcoming batches must have their participants moved first.</li>
                <li>You can restore the account later from the <strong>Removed</strong> filter.</li>
            </ul>
        </div>

        <form id="remove-user-form" :action="deleteUser.url" method="POST">
            @csrf
            @method('DELETE')
        </form>

        <x-slot:footer>
            <button type="button" @click="openDeleteModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="remove-user-form" class="btn-danger">Remove account</button>
        </x-slot:footer>
    </x-side-panel>

</div>
@endsection

