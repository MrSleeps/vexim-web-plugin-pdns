<?php

use App\Traits\CanResetPassword;
use App\Traits\LogsAllActivities;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\EximUser;
use VEximweb\Core\Data\Models\User;

it('provides the VExim host classes expected by core packages', function () {
    expect(trait_exists(LogsAllActivities::class))->toBeTrue()
        ->and(trait_exists(CanResetPassword::class))->toBeTrue()
        ->and(class_exists(Domain::class))->toBeTrue()
        ->and(class_exists(User::class))->toBeTrue()
        ->and(class_exists(EximUser::class))->toBeTrue();
});
