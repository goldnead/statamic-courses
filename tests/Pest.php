<?php

use Goldnead\Courses\Tests\TestCase;
use Goldnead\Courses\Tests\WebhookManagerTestCase;

foreach (glob(__DIR__.'/Fakes/*.php') ?: [] as $fake) {
    require_once $fake;
}

uses(TestCase::class)->in('Feature', 'Unit');
uses(WebhookManagerTestCase::class)->in('Integration');
