<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'must_change_password',
        'phone',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function coachAvailabilities(): HasMany
    {
        return $this->hasMany(CoachAvailability::class, 'coach_id');
    }

    public function assignedParticipants(): HasMany
    {
        return $this->hasMany(ParticipantAssignment::class, 'coach_id');
    }

    public function activeAssignedParticipants(): HasMany
    {
        return $this->hasMany(ParticipantAssignment::class, 'coach_id')->where('status', 'assigned');
    }

    public function coachRequests(): HasMany
    {
        return $this->hasMany(CoachRequest::class, 'coach_id');
    }

    public function releaseRequests(): HasMany
    {
        return $this->hasMany(AssignmentReleaseRequest::class, 'coach_id');
    }

    /**
     * Number of students assigned on a dive date.
     */
    public function assignedCountForDate($date): int
    {
        $dateStr = is_string($date) ? $date : $date->format('Y-m-d');
        return $this->assignedParticipants()
            ->whereDate('dive_date', $dateStr)
            ->where('status', 'assigned')
            ->count();
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCoach(): bool
    {
        return $this->role === 'coach';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Removed accounts are archived (not deleted) so we keep their history. */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function getRoleBadgeAttribute(): array
    {
        return match ($this->role) {
            'owner' => [
                'label' => 'Camp Owner',
                'class' => 'bg-purple-100 text-purple-800',
            ],
            'admin' => [
                'label' => 'Camp Admin',
                'class' => 'bg-blue-100 text-blue-800',
            ],
            'coach' => [
                'label' => 'Freediving Coach',
                'class' => 'bg-emerald-100 text-emerald-800',
            ],
            default => [
                'label' => ucfirst($this->role),
                'class' => 'bg-gray-100 text-gray-800',
            ],
        };
    }
}
