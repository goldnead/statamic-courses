<?php

namespace Goldnead\Courses\Tests;

use Goldnead\Courses\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;

/**
 * The real goldnead/statamic-webhook-manager next to this addon, no fakes:
 * its provider, its tables, its dispatch pipeline.
 *
 * The site without it is tests/Unit/BootWithoutSiblingsTest, in a process of
 * its own.
 */
abstract class WebhookManagerTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $provider = $this->app->getProvider(WebhookManagerServiceProvider::class);
        $provider->bootAddon();

        // Statamic wires an addon's $listen map (TriggerDetected →
        // DispatchTriggerListener) from a booted callback Testbench never fires.
        $bootEvents = new \ReflectionMethod($provider, 'bootEvents');
        $bootEvents->invoke($provider);

        // Both of the bridge's booted attempts ran before the webhook manager's
        // bootAddon() above, and bailed on the missing binding, as they would
        // on a site where it boots later. This is the retry a site gets.
        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));
    }

    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            WebhookManagerServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-webhook-manager/database/migrations');
    }
}
