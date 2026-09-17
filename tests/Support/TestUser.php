<?php

namespace VEximweb\Plugin\PDNS\Tests\Support;

use VEximweb\Core\Data\Models\User;

class TestUser extends User
{
    public bool $systemAdmin = false;

    public bool $domainAdmin = false;

    public function isSystemAdmin(): bool
    {
        return $this->systemAdmin;
    }

    public function isDomainAdmin(): bool
    {
        return $this->domainAdmin;
    }
}
