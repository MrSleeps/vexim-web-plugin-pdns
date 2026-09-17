<?php

namespace App\Traits;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as BaseCanResetPassword;

trait CanResetPassword
{
    use BaseCanResetPassword;

    public function getEmailForPasswordReset()
    {
        return $this->username;
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPassword($token));
    }
}
