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
                <p class="text-[#92400E] mt-0.5">They still have the upcoming batches below. Reassign their students to other coaches (or remove them from the coach team) first, then try again.</p>
            </div>
            <ul class="divide-y divide-[#FDE68A] rounded-lg border border-[#FDE68A] bg-white">
                @foreach($blocked['batches'] as $b)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span><strong class="text-[#1D1D1F]">{{ $b['batch_number'] }}</strong> <span class="text-[#6E6E73]">&middot; {{ $b['date'] }}</span></span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-[#FFFBEB] text-[#92400E]">
                            {{ $b['team_only'] ? 'On the coach team' : $b['students'] . ' ' . Str::plural('student', $b['students']) . ' to reassign' }}
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
                             class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-4 z-50 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="font-bold text-sm text-[#1D1D1F]">Filter Staff</h4>
                                <a href="{{ route('admin.users.index') }}" class="text-sm text-[#780000] hover:underline font-bold">Reset</a>
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
                                         class="absolute right-0 mt-1.5 w-48 bg-white rounded-xl border border-[#E5E5EA] shadow-xl p-1.5 z-50 space-y-1 text-left">
                                        
                                        <!-- Edit Account -->
                                        <a href="{{ route('admin.users.edit', $user) }}" 
                                           class="w-full flex items-center gap-2.5 px-3 py-2 text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-lg transition-colors">
                                            <img src="{{ asset('icons/icons8-edit-60.png') }}" alt="Edit" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                                            <span>Edit Profile</span>
                                        </a>

                                        <!-- Account Status Toggle -->
                                        @if($user->id !== $currentUser->id)
                                            <form action="{{ route('admin.users.toggle_status', $user) }}" method="POST" class="block w-full">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" 
                                                        onclick="return confirm('Are you sure you want to {{ $user->isActive() ? 'deactivate' : ($user->isArchived() ? 'restore' : 'activate') }} this account?')"
                                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm font-semibold {{ $user->isActive() ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' }} rounded-lg transition-colors text-left cursor-pointer">
                                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                                                    <span>{{ $user->isActive() ? 'Deactivate' : ($user->isArchived() ? 'Restore Account' : 'Activate') }}</span>
                                                </button>
                                            </form>
                                            
                                            @unless($user->isArchived())
                                            <div class="border-t border-[#E5E5EA] my-1"></div>

                                            <!-- Remove (archive) User Option -->
                                            <button type="button" 
                                                    @click="confirmDelete({
                                                        id: {{ $user->id }},
                                                        name: '{{ addslashes($user->name) }}',
                                                        email: '{{ addslashes($user->email) }}',
                                                        role: '{{ addslashes($user->role_badge['label']) }}',
                                                        url: '{{ route('admin.users.destroy', $user) }}'
                                                    }); openMenu = false;"
                                                    class="w-full flex items-center gap-2 px-3 py-2 text-sm font-semibold text-[#780000] hover:bg-[#FEF2F2] rounded-lg transition-colors text-left cursor-pointer">
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

    <!-- Create Staff Account Modal -->
    <div x-show="openAddModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 sm:p-7 space-y-5 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openAddModal = false">
            <div>
                <h3 class="text-lg font-bold text-[#1D1D1F]">Create Staff Account</h3>
                <p class="text-sm text-[#6E6E73] mt-0.5">Add a new coach or staff member and set up their login credentials.</p>
            </div>

            <form action="{{ route('admin.users.store') }}" method="POST" class="space-y-3.5 text-sm">
                @csrf

                <div x-data="{ noMiddleName: false }">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block font-bold text-[#1D1D1F] mb-1.5">First Name <span class="text-[#780000]">*</span></label>
                            <input type="text" name="first_name" required placeholder="e.g. Maria Ma." class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                        </div>
                        <div>
                            <label class="block font-bold text-[#1D1D1F] mb-1.5">Middle Name <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                            <input type="text" name="middle_name" :disabled="noMiddleName" placeholder="Full middle name" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white disabled:bg-gray-100 disabled:text-gray-400">
                            <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-[#6E6E73] cursor-pointer">
                                <input type="checkbox" name="no_middle_name" value="1" x-model="noMiddleName" class="rounded border-[#D1D1D6] text-[#780000] focus:ring-[#780000]">
                                <span>I do not have a legal middle name</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-[#1D1D1F] mb-1.5">Last Name <span class="text-[#780000]">*</span></label>
                            <input type="text" name="last_name" required placeholder="e.g. Santos-Concepcion or De la Cruz" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                        </div>
                        <div>
                            <label class="block font-bold text-[#1D1D1F] mb-1.5">Suffix <span class="text-xs font-normal text-[#6E6E73]">(Optional)</span></label>
                            <select name="suffix" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
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
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Email Address (Login Username) <span class="text-[#780000]">*</span></label>
                    <input type="email" name="email" required placeholder="name@campfreedive.ph" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Contact Phone Number <span class="text-[#780000]">*</span></label>
                    <input type="tel" name="phone" required placeholder="0917 123 4567" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Assigned Staff Role <span class="text-[#780000]">*</span></label>
                    <select name="role" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="coach" selected>Freediving Coach (Instructor Portal Access)</option>
                        @if($currentUser->isOwner())
                            <option value="admin">Camp Admin (Operations & Booking Coordinator)</option>
                        @endif
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Temporary Initial Password (Optional)</label>
                    <input type="text" name="temp_password" placeholder="Leave blank to auto-generate secure password" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white">
                    <span class="text-sm text-[#6E6E73] mt-1 block">Staff member will be prompted to change password on first login.</span>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3">
                    <button type="button" @click="openAddModal = false" class="btn-secondary px-3.5 py-1.5 text-sm">Cancel</button>
                    <button type="submit" class="btn-primary px-4 py-1.5 text-sm font-bold shadow-2xs">Create Staff Account</button>
                </div>
            </form>
        </div>
    </div>

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
    <div x-show="showCredentialsModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 sm:p-7 space-y-5 shadow-2xl border-l border-[#E5E5EA] relative" @click.outside="showCredentialsModal = false">
            
            <!-- Modal Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-600 shrink-0">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <h3 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">
                            {{ !empty($creds['is_reset']) ? 'Password Reset Successfully' : 'Account Provisioned Successfully' }}
                        </h3>
                        <p class="text-sm text-[#6E6E73]">Copy and share these initial login credentials with the staff member.</p>
                    </div>
                </div>
                <button type="button" @click="showCredentialsModal = false" aria-label="Close credentials modal" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <!-- Credentials Box -->
            <div class="bg-[#F2F2F7] rounded-xl p-4 space-y-3.5">
                
                <!-- Staff Info -->
                <div class="flex items-center justify-between pb-3 border-b border-[#E5E5EA]">
                    <div>
                        <span class="text-sm text-[#6E6E73] block">Staff Name</span>
                        <strong class="text-sm font-bold text-[#1D1D1F]">{{ $creds['name'] }}</strong>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-sm font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                        {{ $creds['role_label'] }}
                    </span>
                </div>

                <!-- Username / Email Field -->
                <div>
                    <label class="block text-sm font-bold text-[#6E6E73] uppercase tracking-wider mb-1.5">Email (Username)</label>
                    <div class="flex items-center gap-2">
                        <input type="text" 
                               readonly 
                               value="{{ $creds['email'] }}" 
                               class="w-full px-3 py-2 rounded-lg border border-[#D1D1D6] bg-white font-mono text-sm text-[#1D1D1F] select-all">
                        <button type="button" 
                                @click="copyToClipboard('{{ addslashes($creds['email']) }}', 'email')" 
                                class="btn-secondary px-3 py-2 text-sm font-bold whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
                            <span x-text="copiedEmail ? 'Copied!' : 'Copy'"></span>
                            <img src="{{ asset('icons/icons8-copy-60.png') }}" class="w-4.5 h-4.5 object-contain shrink-0" alt="" aria-hidden="true" x-show="!copiedEmail">
                            <svg x-show="copiedEmail" class="w-4.5 h-4.5 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Temporary Password Field -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider">Temporary Password</label>
                        <button type="button" 
                                @click="showPassword = !showPassword" 
                                class="text-sm font-semibold text-[#780000] hover:underline cursor-pointer">
                            <span x-text="showPassword ? 'Hide' : 'Reveal'"></span>
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <input :type="showPassword ? 'text' : 'password'" 
                               readonly 
                               value="{{ $creds['temp_password'] }}" 
                               class="w-full px-3 py-2 rounded-lg border border-[#D1D1D6] bg-white font-mono text-sm text-[#1D1D1F] font-bold tracking-wider select-all">
                        <button type="button" 
                                @click="copyToClipboard('{{ addslashes($creds['temp_password']) }}', 'password')" 
                                class="btn-secondary px-3 py-2 text-sm font-bold whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
                            <span x-text="copiedPassword ? 'Copied!' : 'Copy'"></span>
                            <img src="{{ asset('icons/icons8-copy-60.png') }}" class="w-4.5 h-4.5 object-contain shrink-0" alt="" aria-hidden="true" x-show="!copiedPassword">
                            <svg x-show="copiedPassword" class="w-4.5 h-4.5 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Login URL -->
                <div>
                    <label class="block text-sm font-bold text-[#6E6E73] uppercase tracking-wider mb-1.5">Login Portal</label>
                    <span class="block px-3 py-2 rounded-lg border border-[#E5E5EA] bg-white text-sm font-mono text-[#6E6E73] truncate">
                        {{ $creds['login_url'] }}
                    </span>
                </div>

            </div>

            <!-- Notice & Instructions -->
            <div class="banner banner-warning flex items-start gap-2.5">
                <div class="space-y-0.5 leading-relaxed">
                    <strong class="font-bold block">First Login Password Change Required</strong>
                    <span>When logging in with this temporary password, the system will immediately require the user to set a permanent private password.</span>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2 border-t border-[#E5E5EA]">
                <button type="button" 
                        @click="copyToClipboard({{ json_encode($fullDetails) }}, 'all')" 
                        class="w-full sm:w-auto btn-primary px-4 py-2.5 text-sm font-bold flex items-center justify-center gap-2 shadow-2xs cursor-pointer">
                    <img src="{{ asset('icons/icons8-copy-60.png') }}" class="w-5 h-5 object-contain brightness-0 invert shrink-0" alt="" aria-hidden="true" x-show="!copiedAll">
                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" x-show="copiedAll" x-cloak><polyline points="20 6 9 17 4 12"/></svg>
                    <span x-text="copiedAll ? 'All Credentials Copied!' : 'Copy Complete Login Details'"></span>
                </button>

                <button type="button" 
                        @click="showCredentialsModal = false" 
                        class="w-full sm:w-auto btn-secondary px-4 py-2 text-sm font-bold cursor-pointer">
                    Done
                </button>
            </div>

        </div>
    </div>
    @endif

    <!-- ==================================================================== -->
    <!-- DELETE USER CONFIRMATION MODAL -->
    <!-- ==================================================================== -->
    <div x-show="openDeleteModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 sm:p-7 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openDeleteModal = false">
            
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-3">
                    <div>
                        <h3 class="text-base sm:text-lg font-extrabold text-[#780000]">Remove Staff Account</h3>
                        <p class="text-sm text-[#6E6E73]">The account is closed, but nothing is deleted.</p>
                    </div>
                </div>
                <button type="button" @click="openDeleteModal = false" aria-label="Close delete modal" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <div class="space-y-3 text-sm">
                <p class="text-[#1D1D1F]">
                    Remove the account for <strong x-text="deleteUser.name" class="text-[#780000]"></strong> (<span x-text="deleteUser.email" class="font-mono"></span>)?
                </p>

                <ul class="p-3 bg-[#F2F2F7] rounded-xl text-[#3A3A3C] text-sm space-y-1 list-disc pl-8">
                    <li>They can no longer sign in.</li>
                    <li>Their records (bookings, dives coached, history) are <strong>kept</strong>.</li>
                    <li>They will get an email saying their account was removed.</li>
                    <li>Coaches with upcoming batches must have their students reassigned first.</li>
                    <li>You can restore the account later from the <strong>Removed</strong> filter.</li>
                </ul>
            </div>

            <form :action="deleteUser.url" method="POST" class="pt-2 flex items-center justify-end border-t border-[#F2F2F7] gap-2">
                @csrf
                @method('DELETE')
                <button type="button" @click="openDeleteModal = false" class="btn-secondary px-3.5 py-2 text-sm font-semibold cursor-pointer">Cancel</button>
                <button type="submit" class="btn-danger px-4 py-2 text-sm font-bold shadow-2xs cursor-pointer">
                    Remove Account
                </button>
            </form>

        </div>
    </div>

</div>
@endsection

