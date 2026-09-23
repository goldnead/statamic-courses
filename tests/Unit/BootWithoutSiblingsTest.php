<?php

use Symfony\Component\Process\Process;

/*
 * A site without statamic-private-media, payments, assessments or leadhub.
 * In a separate PHP process: this suite loads tests/Fakes, which define those
 * classes and interfaces and would hide a provider that touches a missing one
 * (0.2.0 crashed every such site at boot with "Interface not found").
 */
it('boots on a site without any of the optional siblings', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-siblings.php']);
    $process->setTimeout(120)->run();

    expect($process->getOutput().$process->getErrorOutput())->toContain('booted')
        ->not->toContain('not found');
});
