<?php

namespace Goldnead\Courses\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Turns whatever the caller holds for a learner into the string the tables key on.
 *
 * Statamic users, file or Eloquent, are Authenticatable and answer with their id.
 */
final class LearnerId
{
    public static function of(mixed $user): string
    {
        $id = match (true) {
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
