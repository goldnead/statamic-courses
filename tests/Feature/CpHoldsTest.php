<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function holdsCpUser(array $permissions): Statamic\Contracts\Auth\User
{
    $handle = 'role-'.Str::random(8);
    Role::make($handle)->addPermission(['access cp', ...$permissions])->save();

    return tap(User::make()->email(Str::random(8).'@example.test')->assignRole($handle))->save();
}

beforeEach(function () {
    config()->set('statamic.editions.pro', true);

    $this->makeCourse('club', ['title' => 'Club', 'on_payment_failure' => 'revoke']);
    $this->makeCourse('drip', ['title' => 'Drip', 'drip_mode' => 'days']);
    tap(User::make()->id('held')->email('held@example.test'))->save();

    Courses::suspendAccess('held', 'club', 'payment_failed', 'sub_7', ['tr_1']);
    Courses::pauseDrip('held', 'drip', 'payment_failed');
});

it('lists every payment hold on the progress screen, with what it is and where it came from', function () {
    $this->actingAs(holdsCpUser(['view course progress']))
        ->get(cp_route('courses.progress.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses::Progress/Index')
            ->has('holds', 2)
            ->where('holds.0.email', 'held@example.test')
            ->where('holds.0.kind', 'suspended')
            ->where('holds.0.subscription_id', 'sub_7')
            ->where('holds.0.blocks', true)
            ->where('holds.0.manual', false)
            ->where('holds.1.kind', 'paused')
            ->where('canRelease', false));
});

it('lets somebody with the permission lift a hold, and nobody else', function () {
    $enrollment = Enrollment::query()->whereNotNull('access_suspended_at')->first();
    $url = cp_route('courses.holds.release', $enrollment->getKey());

    // Core turns a failed `can:` into a redirect with an error toast; the
    // point is that nothing was lifted.
    expect($this->actingAs(holdsCpUser(['view course progress']))->post($url)->status())->toBeIn([302, 403])
        ->and(Courses::hold('held', 'club'))->not->toBeNull();

    $this->actingAs(holdsCpUser(['view course progress', 'manage course holds']))->post($url)->assertRedirect();
    expect(Courses::hold('held', 'club'))->toBeNull();
});

it('tells a hold that shuts the course from one another purchase keeps open, and marks manual holds', function () {
    Entitlements::grant(new SubjectReference('user', 'held'), 'club', 'manual', 'lifetime');
    $this->makeCourse('closed-by-hand', ['title' => 'Hand']);
    Courses::suspendAccess('held', 'closed-by-hand');

    $holds = collect(Courses::holds())->keyBy('course');

    expect($holds['club'])->toMatchArray(['blocks' => false, 'manual' => false])
        ->and($holds['closed-by-hand'])->toMatchArray(['blocks' => true, 'manual' => true]);
});

it('lifts a manual hold from the CP as well', function () {
    $this->makeCourse('closed-by-hand', ['title' => 'Hand']);
    Courses::suspendAccess('held', 'closed-by-hand');
    $enrollment = Enrollment::query()->whereNotNull('access_suspended_at')->whereNull('suspended_by_subscription_id')->first();

    $this->actingAs(holdsCpUser(['view course progress', 'manage course holds']))
        ->post(cp_route('courses.holds.release', $enrollment->getKey()))
        ->assertRedirect();

    expect(Courses::hold('held', 'closed-by-hand'))->toBeNull();
});

it('restarts a paused drip from the same action', function () {
    $enrollment = Enrollment::query()->whereNotNull('drip_paused_at')->first();

    $this->actingAs(holdsCpUser(['view course progress', 'manage course holds']))
        ->post(cp_route('courses.holds.release', $enrollment->getKey()))
        ->assertRedirect();

    expect($enrollment->fresh()->drip_paused_at)->toBeNull();
});

it('keeps an anonymous visitor out', function () {
    $this->post(cp_route('courses.holds.release', 1))->assertRedirect();
    expect(Courses::hold('held', 'club'))->not->toBeNull();
});
