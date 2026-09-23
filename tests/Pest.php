<?php

use Goldnead\Courses\Tests\TestCase;

foreach (glob(__DIR__.'/Fakes/*.php') ?: [] as $fake) {
    require_once $fake;
}

uses(TestCase::class)->in('Feature', 'Unit');
