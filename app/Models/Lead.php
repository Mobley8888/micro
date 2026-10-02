<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'source_id', 'owner_id', 'name', 'email', 'phone', 'stage', 'potential_amount', 'next_action_at', 'notes'];

    protected function casts(): array
    {
        return ['potential_amount' => 'decimal:2', 'next_action_at' => 'date'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }
}
