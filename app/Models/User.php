<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'username',
        'email',
        'password',
        'user_type',
        'is_active',
        'can_login',
        'profile_image',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'email_verification_token',
        'email_verification_token_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'can_login' => 'boolean',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
        ];
    }

    /* Relationships */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRecords()
    {
        return $this->hasMany(PayrollRecord::class);
    }

    public function payslipRequests()
    {
        return $this->hasMany(PayslipRequest::class);
    }

    /* Helper Methods */
    public function canLogin(): bool
    {
        $canLogin = $this->is_active && $this->can_login;

        if ($this->employee_id) {
            // Load employee if not loaded and check status
            $employee = $this->relationLoaded('employee') ? $this->employee : $this->load('employee')->employee;
            return $canLogin && $employee && $employee->is_active;
        }

        return $canLogin;
    }

    public function updateLastLogin($ipAddress = null)
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress
        ]);
    }

    /**
     * Generate a unique email verification token
     */
    public function generateEmailVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->update([
            'email_verification_token' => $token,
            'email_verification_token_expires_at' => now()->addHours(24)
        ]);

        return $token;
    }

    /**
     * Mark the user's email as verified
     */
    public function markEmailAsVerified()
    {
        $this->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_token_expires_at' => null
        ]);
    }

    /**
     * Check if the user has verified their email
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }
}
