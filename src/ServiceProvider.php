<?php

namespace Goldnead\Courses;

use Goldnead\Courses\Access\ClosedCourseAccess;
use Goldnead\Courses\Access\EntitlementsCourseAccess;
use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Integrations\AssessmentsBridge;
use Goldnead\Courses\Integrations\CourseMediaAccess;
use Goldnead\Courses\Integrations\PaymentsBridge;
use Goldnead\Entitlements\EntitlementManager;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Control Panel bundle. Statamic 6 reads it from this property only;
     * the three values must byte-match `laravel()` in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js'],
    ];

    /**
     * The sibling that owns the shared "Suite" nav section, when installed.
     */
    public const SUITE_NAV = '\Goldnead\StatamicPayments\Cp\SuiteNav';

    /**
     * `courses::blocks.download`, not the package name core would pick.
     */
    protected $viewNamespace = 'courses';

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

        // private-media asks the course about a course's private downloads.
        // `extend`, not `bind`: the site's own MediaAccess, or private-media's
        // default, stays in charge of every other resource.
        if (CourseMediaAccess::available()) {
            $this->app->extend(CourseMediaAccess::CONTRACT, fn ($inner) => new CourseMediaAccess($inner));
        }

        // Bound by class name, never under a short slug: a container key named
        // after the addon is how a sibling once overwrote Laravel's own `events`.
        $this->app->singleton(CourseProgress::class);

        // On the resolving translator rather than in boot: nav and permission
        // labels are built before bootAddon() runs.
        $langPath = __DIR__.'/../resources/lang';
        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('courses', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('courses', $langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootCommands()
            ->bootComputedValues()
            ->bootPermissions()
            ->bootNavigation()
            ->bootIntegrations()
            ->bootPublishables();
    }

    /**
     * The optional siblings, each only when installed. Registered by hand from
     * src/Integrations rather than discovered from src/Listeners: discovery
     * would wire them to event classes that may not exist, and registering
     * both ways doubles every call.
     */
    protected function bootIntegrations(): self
    {
        // Once per application, however often bootAddon() runs: a second
        // registration would count every payment and every quiz attempt twice.
        if ($this->app->bound(self::class.'.integrations')) {
            return $this;
        }

        $this->app->instance(self::class.'.integrations', true);

        if (PaymentsBridge::available()) {
            Event::listen(PaymentsBridge::STARTED, [PaymentsBridge::class, 'started']);
            Event::listen(PaymentsBridge::RENEWED, [PaymentsBridge::class, 'renewed']);
            Event::listen(PaymentsBridge::CYCLE_FAILED, [PaymentsBridge::class, 'cycleFailed']);
        }

        if (AssessmentsBridge::available()) {
            Event::listen(AssessmentsBridge::COMPLETED, [AssessmentsBridge::class, 'completed']);
        }

        return $this;
    }

    /**
     * `course_slug` on every lesson, for the lesson route
     * `/courses/{course_slug}/{slug}` that courses:install writes.
     */
    protected function bootComputedValues(): self
    {
        Collection::computed(
            (string) config('courses.collections.lessons', 'course_lessons'),
            'course_slug',
            function ($entry): ?string {
                $id = $entry->value('course');
                $id = is_array($id) ? ($id[0] ?? null) : $id;

                $course = is_string($id) && $id !== '' ? Entry::find($id) : null;

                return $course instanceof \Statamic\Entries\Entry ? $course->slug() : null;
            },
        );

        return $this;
    }

    /**
     * Reading who is where in which course, and, under it, lifting a
     * learner's payment hold: the one write the screen offers.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('courses', __('courses::cp.nav'), function (): void {
                Permission::register('view course progress')
                    ->label(__('courses::cp.permission_view'))
                    ->children([
                        Permission::make('manage course holds')
                            ->label(__('courses::cp.permission_holds')),
                    ]);
            });
        });

        return $this;
    }

    /**
     * Under the suite's shared section when statamic-payments provides one,
     * under Content otherwise. Guarded by class_exists: payments is not a
     * dependency, and a missing class must not take the whole nav down.
     */
    protected function bootNavigation(): self
    {
        if (! config('courses.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $suiteNav = self::SUITE_NAV;
            $section = class_exists($suiteNav) ? $suiteNav::section() : 'Content';

            $nav->create(__('courses::cp.nav'))
                ->section($section)
                // Not chart-monitoring-indicator: insights' nav entry wears it.
                ->icon('content-book-open')
                ->route('courses.progress.index')
                ->can('view course progress');
        });

        return $this;
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

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/courses'),
        ], 'courses-translations');

        return $this;
    }
}
