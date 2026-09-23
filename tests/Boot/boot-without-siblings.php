<?php

/*
 * Boots the addon the way a site without its optional siblings does: plain
 * Composer autoloading, none of tests/Fakes. Run in its own PHP process by
 * BootWithoutSiblingsTest, because the test suite itself loads those fakes
 * and would hide a provider that touches a missing class.
 *
 * Prints "booted" on success; a fatal error ends the process with its message.
 */

require __DIR__.'/../../vendor/autoload.php';

use Goldnead\Courses\ServiceProvider;
use Goldnead\Courses\Support\LessonBlocks;
use Orchestra\Testbench\Foundation\Application;

foreach ([
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

echo "booted\n";
