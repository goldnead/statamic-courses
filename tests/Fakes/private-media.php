<?php

/*
 * Stand-in for statamic-private-media's link maker: url($user, $resource,
 * $asset) as the real one has it. Answers null (as the real one does for a
 * guest or a switched-off route) until a test sets $signs.
 */

namespace Goldnead\PrivateMedia;

if (! class_exists(PrivateMedia::class)) {
    class PrivateMedia
    {
        public static bool $signs = false;

        public function url(mixed $user, string $resource, mixed $asset, ?int $ttlMinutes = null): ?string
        {
            if (! self::$signs || $user === null) {
                return null;
            }

            return '/!/private-media/'.$resource.'/'.$asset->path().'?signature=test';
        }
    }
}
