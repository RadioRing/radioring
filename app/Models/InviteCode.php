<?php

namespace App\Models;

use Database\Factories\InviteCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['code', 'note', 'created_by', 'used_by_user_id', 'used_at'])]
class InviteCode extends Model
{
    /** @use HasFactory<InviteCodeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by_user_id');
    }

    public static function generateCode(): string
    {
        return Str::upper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
    }
}
