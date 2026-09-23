<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Statamic\Facades\User;

beforeEach(function () {
    $this->makeCourse('team-course', ['team_seats' => 1]);
    $this->owner = tap(User::make()->id('owner')->email('owner@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'owner'), 'team-course', 'test');
    $this->url = '/!/courses/team';
});

function teamTemplate(string $template): string
{
    $path = sys_get_temp_dir().'/courses-team-'.bin2hex(random_bytes(6)).'.antlers.html';
    file_put_contents($path, $template);

    try {
        return trim(view()->file($path)->render());
    } finally {
        @unlink($path);
    }
}

it('lets the buyer add and remove a member', function () {
    $this->actingAs($this->owner)
        ->postJson($this->url, ['course' => 'team-course', 'action' => 'add', 'email' => 'm@example.test'])
        ->assertOk()
        ->assertJsonPath('team.left', 0)
        ->assertJsonPath('team.members.0.email', 'm@example.test');

    $this->postJson($this->url, ['course' => 'team-course', 'action' => 'add', 'email' => 'n@example.test'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'no_seat');

    $this->postJson($this->url, ['course' => 'team-course', 'action' => 'remove', 'email' => 'm@example.test'])
        ->assertOk()
        ->assertJsonPath('team.left', 1);
});

it('turns away a guest and somebody who is not the buyer', function () {
    $this->postJson($this->url, ['course' => 'team-course', 'action' => 'add', 'email' => 'm@example.test'])
        ->assertStatus(401);

    $other = tap(User::make()->id('other')->email('other@example.test'))->save();

    $this->actingAs($other)
        ->postJson($this->url, ['course' => 'team-course', 'action' => 'add', 'email' => 'm@example.test'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'not_owner');
});

it('validates the address', function () {
    $this->actingAs($this->owner)
        ->postJson($this->url, ['course' => 'team-course', 'action' => 'add', 'email' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('shows the buyer their seats and renders a form that posts to the route', function () {
    Courses::addTeamMember($this->owner, 'team-course', 'm@example.test');
    $this->actingAs($this->owner);

    expect(teamTemplate('{{ courses:team course="team-course" }}{{ left }}/{{ seats }}:{{ members }}{{ email }}{{ /members }}{{ /courses:team }}'))
        ->toBe('0/1:m@example.test')
        ->and(teamTemplate('{{ courses:team_form course="team-course" do="remove" email="m@example.test" }}<button>x</button>{{ /courses:team_form }}'))
        ->toContain('action="http://localhost/!/courses/team"')
        ->toContain('name="action" value="remove"')
        ->toContain('name="email" value="m@example.test"');
});

it('shows nothing to somebody without a team', function () {
    $this->actingAs(tap(User::make()->id('x')->email('x@example.test'))->save());

    expect(teamTemplate('{{ courses:team course="team-course" }}seats{{ /courses:team }}'))->toBe('');
});
