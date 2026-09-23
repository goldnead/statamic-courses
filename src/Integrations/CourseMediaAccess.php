<?php

namespace Goldnead\Courses\Integrations;

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Support\LessonBlocks;
use Goldnead\PrivateMedia\Contracts\MediaAccess;

/**
 * statamic-private-media asking about a course's private download.
 *
 * Wraps whatever MediaAccess the site has. A link signed for the resource
 * `course:<slug>` (what a private download block signs) is answered by
 * Courses::canAccess(), so a team member, a bundle holder and a learner
 * whose course a hold closed get exactly the answer the course itself gives.
 * Every other resource goes to the wrapped access unchanged.
 *
 * Registered only when statamic-private-media is installed.
 */
class CourseMediaAccess implements MediaAccess
{
    public const PREFIX = LessonBlocks::RESOURCE_PREFIX;

    /*
     * Nothing static here that the rest of the addon calls: loading this
     * class needs private-media's interface. The provider checks for it by
     * name (ServiceProvider::PRIVATE_MEDIA_ACCESS) before it touches the class.
     */
    public function __construct(protected MediaAccess $inner) {}

    public static function resourceFor(string $courseSlug): string
    {
        return LessonBlocks::resourceFor($courseSlug);
    }

    public function allows(mixed $user, string $resource, string $path): bool
    {
        if (! str_starts_with($resource, self::PREFIX)) {
            return $this->inner->allows($user, $resource, $path);
        }

        $slug = substr($resource, strlen(self::PREFIX));

        return $user !== null && $slug !== '' && app(CourseProgress::class)->canAccess($user, $slug);
    }
}
