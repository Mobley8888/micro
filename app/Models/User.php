<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['company_id', 'name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['is_active' => true];

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_COMPANY_ADMIN = 'company_admin';

    public const ROLE_OPERATOR = 'operator';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $role): bool
    {
        $legacyRole = $role === self::ROLE_SUPER_ADMIN ? 'super-administrator' : $role;

        return $this->roles()->whereIn('slug', [$role, $legacyRole])->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()->whereHas('permissions', fn ($query) => $query->where('slug', $permission))->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function isCompanyAdmin(): bool
    {
        return ! $this->isSuperAdmin() && $this->hasRole(self::ROLE_COMPANY_ADMIN);
    }

    public function isOperator(): bool
    {
        return ! $this->isSuperAdmin() && ! $this->isCompanyAdmin();
    }
}
