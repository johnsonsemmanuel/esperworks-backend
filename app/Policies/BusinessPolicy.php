<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\TeamMember;
use App\Models\User;

class BusinessPolicy
{
    private function isActiveTeamMember(User $user, Business $business): bool
    {
        return TeamMember::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    public function view(User $user, Business $business): bool
    {
        return $user->id === $business->user_id
            || $user->isAdmin()
            || $this->isActiveTeamMember($user, $business);
    }

    public function update(User $user, Business $business): bool
    {
        if ($user->id === $business->user_id || $user->isAdmin()) {
            return true;
        }

        return TeamMember::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('role', 'admin')
            ->exists();
    }

    public function delete(User $user, Business $business): bool
    {
        return $user->id === $business->user_id || $user->isAdmin();
    }
}
