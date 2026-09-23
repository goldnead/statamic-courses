<?php

namespace Goldnead\Courses\Support;

use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;

/**
 * The Statamic user behind whatever the caller holds for a learner, for the
 * rules that need more than an id: user groups, the email address.
 */
final class Learner
{
    public static function user(mixed $learner): ?StatamicUser
    {
        if ($learner instanceof StatamicUser) {
            return $learner;
        }

        if ($learner === null || $learner === '') {
            return null;
        }

        try {
            $user = User::find(LearnerId::of($learner));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $user instanceof StatamicUser ? $user : null;
    }

    public static function email(mixed $learner): ?string
    {
        $email = self::user($learner)?->email();

        if (! is_string($email) || $email === '') {
            $email = is_object($learner) && isset($learner->email) && is_string($learner->email) ? $learner->email : null;
        }

        return is_string($email) && $email !== '' ? mb_strtolower(trim($email)) : null;
    }
}
