<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashMovement extends Model
{
    use HasFactory, HasUuids;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const TYPE_OPENING = 'opening';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'company_id', 'cash_register_id', 'cash_session_id', 'type', 'direction', 'amount',
        'occurred_at', 'description', 'reference', 'source_type', 'source_id', 'created_by', 'metadata', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::created(function (CashMovement $movement): void {
            FinancialJournalEntry::query()->firstOrCreate([
                'company_id' => $movement->company_id,
                'account_type' => 'cash',
                'account_id' => $movement->cash_register_id,
                'source_type' => self::class,
                'source_id' => $movement->id,
            ], [
                'direction' => $movement->direction,
                'amount' => $movement->amount,
                'occurred_at' => $movement->occurred_at,
                'reference' => $movement->reference,
                'description' => $movement->description,
                'created_by' => $movement->created_by,
            ]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
