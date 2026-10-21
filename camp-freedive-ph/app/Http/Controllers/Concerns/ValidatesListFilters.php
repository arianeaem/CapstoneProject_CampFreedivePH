<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Management lists (screen, Excel and print) reject bad filters with a 422
 * instead of guessing or crashing (e.g. a malformed date).
 */
trait ValidatesListFilters
{
    protected function validateListFilters(Request $request, array $rules): void
    {
        // An end date before the start date is an error, but only check it when a start date was given
        if ($request->filled('date_from') && isset($rules['date_to'])) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        $validator = validator($request->only(array_keys($rules)), $rules);
        abort_if($validator->fails(), 422, $validator->errors()->first());
    }

    /** Searches for a phone number or email are not repeated on printouts or in the audit log. */
    protected static function searchLabel(string $search): string
    {
        return str_contains($search, '@') || preg_match_all('/\d/', $search) >= 7 ? '(contact details search)' : '"' . $search . '"';
    }
}
