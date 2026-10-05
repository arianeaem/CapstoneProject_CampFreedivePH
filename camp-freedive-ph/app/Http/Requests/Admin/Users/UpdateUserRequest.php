<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\Concerns\BuildsPersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for Admin/UserManagementController::update(). Admins may only edit coach accounts (UserPolicy). */
class UpdateUserRequest extends FormRequest
{
    use BuildsPersonName;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function allowedRoles(): array
    {
        return $this->user()->isOwner() ? ['owner', 'admin', 'coach'] : ['coach'];
    }

    public function rules(): array
    {
        return $this->nameRules() + [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')?->id)],
            'phone' => ['required', 'string', 'max:50'],
            'role' => ['required', Rule::in($this->allowedRoles())],
            'status' => ['required', 'in:active,inactive'],
            'new_password' => ['nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return $this->nameMessages();
    }
}
