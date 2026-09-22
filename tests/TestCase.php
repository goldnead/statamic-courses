<?php

namespace Goldnead\Courses\Tests;

use Goldnead\Courses\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

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

    protected function defineEnvironment($app): void
    {
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
