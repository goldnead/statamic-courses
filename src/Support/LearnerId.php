<?php

namespace Goldnead\Courses\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User as StatamicUser;

/**
 * Turns whatever the caller holds for a learner into the string the tables key on.
 */
final class LearnerId
{
    public static function of(mixed $user): string
    {
        $id = match (true) {
            $user instanceof StatamicUser => $user->id(),
            $user instanceof Authenticatable => $user->getAuthIdentifier(),
            is_string($user), is_int($user) => $user,
            default => null,
        };

        if ($id === null || $id === '') {
            throw new InvalidArgumentException('A course learner needs an id: pass a user, an Authenticatable or the id itself.');
        }

        return (string) $id;
    }
}
