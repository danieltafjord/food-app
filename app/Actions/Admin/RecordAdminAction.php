<?php

namespace App\Actions\Admin;

use App\Models\AdminAction;
use App\Models\User;

/**
 * Appends one entry to the admin audit trail.
 */
class RecordAdminAction
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function handle(User $admin, string $action, ?User $subject = null, ?string $subjectLabel = null, ?array $changes = null): AdminAction
    {
        return AdminAction::query()->create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'action' => $action,
            'subject_user_id' => $subject?->id,
            'subject_label' => $subjectLabel ?? ($subject ? "{$subject->name} <{$subject->email}>" : ''),
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
