<?php

namespace App\Http\Requests\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * Age rules shared by the public and admin booking forms:
 * - every participant is 8-85 (also when only a birthdate is given)
 * - the primary contact is 18 or older
 * - participants under 18 need a parent/guardian's consent
 */
trait ChecksMinorsAndContact
{
    public const MIN_PARTICIPANT_AGE = 8;
    public const MAX_PARTICIPANT_AGE = 85;
    public const ADULT_AGE = 18;

    /** Rules for the guardian / contact fields (added to rules()). */
    protected function minorConsentRules(): array
    {
        return [
            'selected_lead_participant' => 'nullable',
            'contact_birthdate' => 'nullable|date|before_or_equal:today',
            'guardian_name' => 'nullable|string|min:2|max:255|regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u',
            'guardian_relationship' => 'nullable|in:parent,legal_guardian',
            'guardian_phone' => ['nullable', 'string', 'regex:/^(\+?63|0)?[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}$/'],
            'guardian_consent' => 'nullable|boolean',
        ];
    }

    public static function ageFrom(?string $birthdate, $age = null): ?int
    {
        if (!empty($birthdate)) {
            try {
                return Carbon::parse($birthdate)->age;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return is_numeric($age) ? (int) $age : null;
    }

    /** Call from withValidator(). */
    protected function checkMinorsAndContact(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $participants = (array) $this->input('participants', []);
            $hasMinor = false;

            foreach ($participants as $i => $p) {
                $age = self::ageFrom($p['birthdate'] ?? null, $p['age'] ?? null);
                if ($age === null) {
                    continue; // the "required" rules already report this
                }
                if ($age < self::MIN_PARTICIPANT_AGE || $age > self::MAX_PARTICIPANT_AGE) {
                    $v->errors()->add("participants.{$i}.birthdate", 'Participant #' . ($i + 1) . ' must be between '
                        . self::MIN_PARTICIPANT_AGE . ' and ' . self::MAX_PARTICIPANT_AGE . ' years old.');
                }
                if ($age < self::ADULT_AGE) {
                    $hasMinor = true;
                }
            }

            // Primary contact: one of the participants, or someone else ("custom") with their own birthdate
            $lead = $this->input('selected_lead_participant') ?? 0; // the forms default to the first participant
            if (is_numeric($lead) && isset($participants[(int) $lead])) {
                $p = $participants[(int) $lead];
                $contactAge = self::ageFrom($p['birthdate'] ?? null, $p['age'] ?? null);
            } else {
                $contactAge = self::ageFrom($this->input('contact_birthdate'));
                if ($contactAge === null && $lead === 'custom') {
                    $v->errors()->add('contact_birthdate', "Please enter the primary contact's birthdate.");
                }
            }
            if ($contactAge !== null && $contactAge < self::ADULT_AGE) {
                $v->errors()->add('contact_birthdate', 'The primary contact must be 18 or older. '
                    . 'Choose an adult participant, or enter a parent or guardian as the contact.');
            }

            // Participants under 18 need a parent or legal guardian's consent
            if ($hasMinor) {
                if (blank($this->input('guardian_name'))) {
                    $v->errors()->add('guardian_name', "Please enter the parent or guardian's full name for the participant(s) under 18.");
                }
                if (blank($this->input('guardian_relationship'))) {
                    $v->errors()->add('guardian_relationship', 'Please select whether the guardian is a parent or legal guardian.');
                }
                if (blank($this->input('guardian_phone'))) {
                    $v->errors()->add('guardian_phone', "Please enter the parent or guardian's mobile number.");
                }
                if (!$this->boolean('guardian_consent')) {
                    $v->errors()->add('guardian_consent', 'Parent or guardian consent is required for participants under 18.');
                }
            }
        });
    }

    /** Guardian fields to save on the booking (null when no participant is under 18). */
    protected function guardianAttributes(array $validated): array
    {
        $hasMinor = collect($validated['participants'] ?? [])
            ->contains(fn ($p) => (self::ageFrom($p['birthdate'] ?? null, $p['age'] ?? null) ?? 99) < self::ADULT_AGE);

        return [
            'contact_birthdate' => $validated['contact_birthdate'] ?? null,
            'guardian_name' => $hasMinor ? ($validated['guardian_name'] ?? null) : null,
            'guardian_relationship' => $hasMinor ? ($validated['guardian_relationship'] ?? null) : null,
            'guardian_phone' => $hasMinor ? ($validated['guardian_phone'] ?? null) : null,
            'guardian_consent_at' => $hasMinor ? now() : null,
        ];
    }

    public function guardianFields(): array
    {
        return $this->guardianAttributes($this->validated());
    }
}
