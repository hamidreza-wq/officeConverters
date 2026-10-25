<?php

namespace App\Policies;

use App\Models\Conversion;
use App\Models\User;

class ConversionPolicy
{
    public function view(User $user, Conversion $conversion): bool
    {
        return $user->id === $conversion->user_id;
    }

    public function delete(User $user, Conversion $conversion): bool
    {
        return $this->view($user, $conversion);
    }
}
