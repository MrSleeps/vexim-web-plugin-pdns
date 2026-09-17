<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class VwPersonalAccessToken extends SanctumToken
{
    protected $table = 'vw_personal_access_tokens';
}
