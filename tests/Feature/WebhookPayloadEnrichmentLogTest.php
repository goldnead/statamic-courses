<?php

use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Integrations\WebhookManager\WebhookPayload;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/*
 * Anreicherung der Webhook-Nutzlast: schlägt eine Nachschlagung fehl, geht die
 * Nutzlast trotzdem raus (nur ohne Namen), aber der Fehler steht im Log.
 */

it('logs a failing user lookup for a team member and still sends the address', function () {
    User::shouldReceive('findByEmail')->andThrow(new RuntimeException('users unreachable'));
    User::shouldReceive('find')->andReturnNull();
    Entry::shouldReceive('find')->andReturnNull();
    Log::spy();

    $payload = WebhookPayload::for('courses.team_member_added', new TeamMemberAdded('owner-1', 'entry-1', 'club', 'mia@example.test'));

    expect($payload['member'])->toBe(['email' => 'mia@example.test']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'member') && ($context['exception'] ?? null) === RuntimeException::class)
        ->once();
});

it('logs a failing course lookup and still sends the course without a title', function () {
    User::shouldReceive('find')->andReturnNull();
    Entry::shouldReceive('find')->andThrow(new RuntimeException('entries unreachable'));
    Log::spy();

    $payload = WebhookPayload::for('courses.team_member_added', new TeamMemberAdded('owner-1', 'entry-1', 'club', 'mia@example.test'));

    expect($payload['course'])->toMatchArray(['id' => 'entry-1', 'slug' => 'club', 'title' => null]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'course') && ($context['exception'] ?? null) === RuntimeException::class)
        ->once();
});
