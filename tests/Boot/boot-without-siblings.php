<?php

/*
 * Boots the addon the way a site without its optional siblings does: plain
 * Composer autoloading, none of tests/Fakes. Run in its own PHP process by
 * BootWithoutSiblingsTest, because the test suite itself loads those fakes
 * and would hide a provider that touches a missing class.
 *
 * Prints "booted" on success; a fatal error ends the process with its message.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';

// The webhook manager is a dev dependency so the live bridge test can run
// against the real addon. Here the site must not have it: take its namespace
// out of the autoloader, which is exactly what an install without it sees.
$loader->setPsr4('Goldnead\\WebhookManager\\', []);

use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Courses\ServiceProvider;
use Goldnead\Courses\Support\LessonBlocks;
use Orchestra\Testbench\Foundation\Application;

foreach ([
    'Goldnead\\WebhookManager\\Facades\\WebhookManager',
    'Goldnead\\WebhookManager\\Contracts\\TriggerInterface',
    'Goldnead\\PrivateMedia\\Contracts\\MediaAccess',
    'Goldnead\\PrivateMedia\\PrivateMedia',
    'Goldnead\\StatamicPayments\\Events\\SubscriptionStarted',
    'Goldnead\\Assessments\\Events\\AssessmentCompleted',
    'Goldnead\\Leadhub\\LeadHubManager',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is installed, this check proves nothing\n");
        exit(2);
    }
}

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
// register() is where the optional couplings are decided; that is what must
// survive their absence. Statamic itself is left out: it would need a whole
// site's configuration, and it is not what is being checked here.
$app->register(ServiceProvider::class);

// What a lesson page calls on the way to a private download.
LessonBlocks::resourceFor('probe');

// What the provider's booted callback does, and a course event after it:
// neither may touch a webhook-manager class.
$app->make(WebhookManagerBridge::class)->boot($app['events']);
$app['events']->dispatch(new LearnerEnrolled('1', 'course-id', 'probe', null));

echo "booted\n";
