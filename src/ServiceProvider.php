<?php

namespace Goldnead\Courses;

use Goldnead\Courses\Access\ClosedCourseAccess;
use Goldnead\Courses\Access\EntitlementsCourseAccess;
use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Entitlements\EntitlementManager;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/courses.php', 'courses');

        // The access seam. entitlements is a `suggest`, so its presence is
        // checked, not assumed; without it every course is closed rather than
        // open. A site binding its own CourseAccess after this provider wins.
        $this->app->bindIf(CourseAccess::class, fn ($app) => class_exists(EntitlementManager::class)
            ? $app->make(EntitlementsCourseAccess::class)
            : new ClosedCourseAccess);

        // Bound by class name, never under a short slug: a container key named
        // after the addon is how a sibling once overwrote Laravel's own `events`.
        $this->app->singleton(CourseProgress::class);
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootCommands()
            ->bootPublishables();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * Registered by hand because core's own command discovery runs after
     * Statamic's boot sequence, which a plain console context never reaches.
     */
    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Commands\Install::class]);
        }

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'courses-migrations');

        return $this;
    }
}
