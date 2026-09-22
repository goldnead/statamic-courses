<?php

namespace Goldnead\Courses\Tests;

use Goldnead\Courses\ServiceProvider;
use Goldnead\Courses\Tags\Courses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** @var list<callable> Nav::extend() callbacks bootAddon() registered. */
    protected array $navCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AddonTestCase swaps Nav for a strict mock; let bootAddon() extend it
        // and keep the callback, so a test can run it against a real builder.
        Nav::shouldReceive('extend')->andReturnUsing(function ($callback) {
            $this->navCallbacks[] = $callback;
        });

        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        // Core discovers src/Tags from its booted callback, which Testbench
        // never fires; register the tag the way that discovery would.
        Courses::register();

        $this->artisan('courses:install')->run();
    }

    /**
     * Registered here, not by a `migrate` call in setUp(): Testbench runs this
     * before RefreshDatabase opens its transaction. DDL inside that transaction
     * commits it implicitly under MySQL, and the next savepoint (createOrFirst
     * uses one) then fails with "SAVEPOINT trans2 does not exist".
     *
     * The entitlements sibling's migrations are listed too: the addon manifest
     * names only this package, so Statamic never boots that one here.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
        ];
    }

    /**
     * The action routes as a real site mounts them: /!/courses/…, inside the
     * `web` group, named statamic.courses.*. Statamic pushes addon routes from
     * its booted callback, which runs after Testbench has loaded routes, so the
     * bed mounts the same file itself.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')
            ->name('statamic.')
            ->prefix('!/courses')
            ->group(__DIR__.'/../routes/actions.php');

        // Inside core's authenticated CP group, as Statamic mounts it, so an
        // anonymous request is redirected by core, not refused by the controller.
        $router->middleware(['statamic.cp', 'statamic.cp.authenticated'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');
    }

    protected function defineEnvironment($app): void
    {
        // Blueprints are files, not Stache items, so PreventsSavingStacheItemsToDisk
        // does not catch them: courses:install would write them into the
        // Testbench app in vendor/, where they outlive the run and a later
        // install finds "exists, kept". A directory of their own, wiped per test.
        $app['config']->set('statamic.system.blueprints_path', static::blueprintsPath());
        $app['files']->deleteDirectory(static::blueprintsPath());

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Neither UTC nor a zone the tests use: a drip date that forgets its
        // timezone must show up as a failure, not coincide with the right answer.
        $app['config']->set('app.timezone', 'America/Chicago');
        $app['config']->set('brand-context.multi_brand', false);

        // AddonTestCase swaps the Nav facade for a strict mock, and the
        // entitlements sibling's bootAddon() would call Nav::extend() on it.
        // Its CP is not what these tests are about.
        $app['config']->set('entitlements.cp.enabled', false);
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * In-memory SQLite by default; DB_DRIVER=mysql runs the identical suite
     * against a real server (phpunit.mysql.xml), which is the only place the
     * unique indexes the dedupe and enrollment rest on are really exercised.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'courses_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    public static function blueprintsPath(): string
    {
        return __DIR__.'/__fixtures__/blueprints';
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory(static::blueprintsPath());

        parent::tearDown();
    }

    protected function runningAgainstMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeCourse(string $slug, array $data = []): string
    {
        $entry = Entry::make()
            ->collection('courses')
            ->slug($slug)
            ->data(['title' => ucfirst($slug), ...$data]);
        $entry->save();

        return (string) $entry->id();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeLesson(string $courseId, string $slug, array $data = []): string
    {
        $entry = Entry::make()
            ->collection('course_lessons')
            ->slug($slug)
            ->data(['title' => ucfirst($slug), 'course' => $courseId, ...$data]);
        $entry->save();

        return (string) $entry->id();
    }
}
