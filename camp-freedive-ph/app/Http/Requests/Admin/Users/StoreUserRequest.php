<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\Concerns\BuildsPersonName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for Admin/UserManagementController::store(). Owners can add admins and coaches; admins only coaches. */
class StoreUserRequest extends FormRequest
{
    use BuildsPersonName;

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function allowedRoles(): array
    {
        return $this->user()->isOwner() ? ['admin', 'coach'] : ['coach'];
    }

    public function rules(): array
    {
        return $this->nameRules() + [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:50'],
            'role' => ['required', Rule::in($this->allowedRoles())],
            'temp_password' => ['nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return $this->nameMessages();
    }
}
