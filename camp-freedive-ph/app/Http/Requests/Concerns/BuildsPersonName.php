<?php

namespace App\Http\Requests\Concerns;

/** Shared name rules for staff and guests: letters (including Ñ/ñ), spaces, hyphens, periods. */
trait BuildsPersonName
{
    public const NAME_REGEX = '/^[\p{L}\s\.\'\-]+$/u';

    protected function nameRules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:120', 'regex:' . self::NAME_REGEX],
            'middle_name' => ['nullable', 'string', 'max:120', 'regex:' . self::NAME_REGEX],
            'no_middle_name' => ['nullable', 'boolean'],
            'last_name' => ['nullable', 'string', 'max:120', 'regex:' . self::NAME_REGEX],
            'suffix' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function nameMessages(): array
    {
        return [
            'first_name.regex' => 'First name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'middle_name.regex' => 'Middle name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'last_name.regex' => 'Last name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
        ];
    }

    /** "Juan Dela Cruz Jr." from the separate fields, or '' when none were given. */
    public function fullName(): string
    {
        $suffix = trim((string) $this->input('suffix', ''));
        if (strtolower($suffix) === 'none') {
            $suffix = '';
        }

        return implode(' ', array_filter([
            trim((string) $this->input('first_name', '')),
            $this->boolean('no_middle_name') ? '' : trim((string) $this->input('middle_name', '')),
            trim((string) $this->input('last_name', '')),
            $suffix,
        ]));
    }
}
