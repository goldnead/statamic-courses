<?php

/*
 * Stand-ins for statamic-private-media: the link maker, url($user,
 * $resource, $asset) as the real one has it, and the MediaAccess contract.
 * The link maker answers null (as the real one does for a guest or a
 * switched-off route) until a test sets $signs.
 */

namespace Goldnead\PrivateMedia\Contracts {
    if (! interface_exists(MediaAccess::class)) {
        interface MediaAccess
        {
            public function allows(mixed $user, string $resource, string $path): bool;
        }
    }
}

namespace Goldnead\PrivateMedia {
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
}
