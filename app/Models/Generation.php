<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $key_label
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_urls
 * @property GenerationStatus $status
 * @property int $estimated_min_picos
 * @property int $estimated_max_picos
 * @property int|null $provider_cost_picos
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $rates
 * @property Carbon|null $accepted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $image_expires_at
 * @property string|null $image_url
 * @property string|null $failure_code
 * @property string|null $attention_reason
 * @property int $submit_attempts
 * @property bool $acceptance_uncertain
 * @property int $recovery_step
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read CreditTransaction|null $charge
 */
#[Fillable([
    'user_id',
    'provider_id',
    'key_label',
    'prompt',
    'size',
    'quality',
    'reference_urls',
    'status',
    'estimated_min_picos',
    'estimated_max_picos',
    'provider_cost_picos',
    'usage',
    'rates',
    'accepted_at',
    'completed_at',
    'next_check_at',
    'image_expires_at',
    'image_url',
    'failure_code',
    'attention_reason',
    'submit_attempts',
    'acceptance_uncertain',
    'recovery_step',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_urls' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'acceptance_uncertain' => 'boolean',
            'estimated_min_picos' => 'integer',
            'estimated_max_picos' => 'integer',
            'provider_cost_picos' => 'integer',
            'submit_attempts' => 'integer',
            'recovery_step' => 'integer',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_check_at' => 'datetime',
            'image_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<CreditTransaction, $this>
     */
    public function charge(): HasOne
    {
        return $this->hasOne(CreditTransaction::class);
    }
}
