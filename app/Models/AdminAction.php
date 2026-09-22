<?php

namespace App\Models;

use Database\Factories\AdminActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of what admins did in the backoffice. The admin and subject
 * names are copied so the log still reads well after either account is gone.
 */
#[Fillable(['admin_id', 'admin_name', 'action', 'subject_user_id', 'subject_label', 'changes', 'created_at'])]
class AdminAction extends Model
{
    /** @use HasFactory<AdminActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const USER_UPDATED = 'user.updated';

    public const USER_DEACTIVATED = 'user.deactivated';

    public const USER_REACTIVATED = 'user.reactivated';

    public const USER_DELETED = 'user.deleted';

    public const AI_MODEL_UPDATED = 'ai.model_updated';

    public const AI_LIMITS_UPDATED = 'ai.limits_updated';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'admin_name' => $this->admin_name,
            'subject_label' => $this->subject_label,
            'subject_user_id' => $this->subject_user_id,
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
