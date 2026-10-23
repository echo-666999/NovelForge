<?php

namespace App\Policies;

use App\Models\Novel;
use App\Models\User;

class NovelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Novel $novel): bool
    {
        return $user->is_admin && (string) $user->id === (string) $novel->owner_id;
    }

    public function update(User $user, Novel $novel): bool
    {
        return $this->view($user, $novel) && $novel->status !== 'archived';
    }

    public function delete(User $user, Novel $novel): bool
    {
        return false;
    }
}
