<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Events\CourseAccessSuspended;
use Goldnead\Courses\Events\CourseCompleted;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Tests\Fakes\FakeSubscription;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

beforeEach(function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->akademie = Brand::create(['handle' => 'akademie', 'name' => 'Akademie']);
    $this->studio = Brand::create(['handle' => 'studio', 'name' => 'Studio']);

    $own = $this->makeCourse('own-brand', ['brand' => 'akademie', 'sequencing_mode' => 'lesson', 'on_payment_failure' => 'revoke']);
    $this->makeLesson($own, 'one', ['sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($own, 'two', ['sort_order' => 2, 'item_type' => 'text']);

    $none = $this->makeCourse('no-brand', ['on_payment_failure' => 'revoke']);
    $this->makeLesson($none, 'only', ['item_type' => 'text']);
});

it('stamps every event with the brand of the course, whatever brand is current', function () {
    Event::fake([LearnerEnrolled::class, LessonCompleted::class, LessonUnlocked::class, CourseCompleted::class]);

    app('brand-context')->runFor($this->studio, function () {
        Courses::enroll('u', 'own-brand');
        Courses::acknowledgeLesson('u', 'own-brand', 'one');
        Courses::acknowledgeLesson('u', 'own-brand', 'two');
    });

    $id = $this->akademie->id;

    Event::assertDispatched(LearnerEnrolled::class, fn ($e) => $e->brandId === $id);
    Event::assertDispatched(LessonCompleted::class, fn ($e) => $e->brandId === $id);
    Event::assertDispatched(LessonUnlocked::class, fn ($e) => $e->brandId === $id);
    Event::assertDispatched(CourseCompleted::class, fn ($e) => $e->brandId === $id);
});

it('stamps a course without a brand with the brand current when it fires', function () {
    Event::fake([LessonCompleted::class]);

    app('brand-context')->runFor($this->studio, fn () => Courses::acknowledgeLesson('u', 'no-brand', 'only'));

    Event::assertDispatched(LessonCompleted::class, fn ($e) => $e->brandId === $this->studio->id);
});

it('takes the brand of the entry\'s site when the course names none', function () {
    config()->set('brand-context.sites', ['default' => 'studio']);

    expect(Courses::course('no-brand')['brand_id'])->toBe($this->studio->id);
});

it('fires the events of a webhook in the subscription\'s brand for a course without one', function () {
    Event::fake([CourseAccessSuspended::class]);
    Catalogue::$entries = ['no-brand' => ['grants' => ['no-brand']]];
    tap(User::make()->id('buyer')->email('buyer@example.test'))->save();
    app('brand-context')->runFor($this->studio, fn () => Entitlements::grant(new SubjectReference('user', 'buyer'), 'no-brand', 'statamic-payments', 'tr_first'));

    // A webhook: no brand is current.
    app('brand-context')->forget();
    event(new SubscriptionCycleFailed(new FakeSubscription(product: 'no-brand', brand_id: $this->studio->id)));

    Event::assertDispatched(CourseAccessSuspended::class, fn ($e) => $e->brandId === $this->studio->id);
});

it('lists the courses of one brand, and those without a brand, for a picker', function () {
    expect(array_column(Courses::courses($this->akademie->id), 'slug'))->toEqualCanonicalizing(['own-brand', 'no-brand'])
        ->and(array_column(Courses::courses($this->studio->id), 'slug'))->toBe(['no-brand'])
        ->and(Courses::courses())->toHaveCount(2);
});

it('keeps an event constructed the old way working, with the brand current then', function () {
    $event = app('brand-context')->runFor($this->studio, fn () => new CourseCompleted('u', 'c', 'slug'));

    expect($event->brandId)->toBe($this->studio->id);
});
