<?php

/*
 * A stand-in for statamic-offers' static surface: the one method courses calls
 * (Offers::contentBoxes()), with the shape the real one returns. Offers is a
 * `suggest`, not a dev dependency, so every test run uses this one.
 *
 * A box here is whatever the test put in `$boxes` under its id; an id that is
 * not there is "not shown", like a real box that is unpublished or outside the
 * audience. `$viewers` remembers who was asked for.
 */

namespace Goldnead\StatamicOffers;

if (! class_exists(Offers::class)) {
    final class Offers
    {
        /** @var array<string, array<string, mixed>> */
        public static array $boxes = [];

        /** @var list<mixed> */
        public static array $viewers = [];

        public static function reset(): void
        {
            self::$boxes = [];
            self::$viewers = [];
        }

        /**
         * @param  iterable<mixed>  $idsOrSlugs
         * @return list<array<string, mixed>>
         */
        public static function contentBoxes(iterable $idsOrSlugs, mixed $viewer = null): array
        {
            self::$viewers[] = $viewer;
            $found = [];

            foreach ($idsOrSlugs as $id) {
                if (is_string($id) && isset(self::$boxes[$id])) {
                    $found[] = self::$boxes[$id];
                }
            }

            return $found;
        }
    }
}
