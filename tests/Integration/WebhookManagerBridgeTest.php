<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Integrations\WebhookManager\CoursesTrigger;
use Goldnead\Courses\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Courses\Models\LessonState;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Statamic\Facades\User;

function outboundHook(string $trigger, string $handle = 'hook'): OutboundWebhook
{
    return OutboundWebhook::create([
        'uuid' => (string) Str::uuid(),
        'name' => $handle,
        'handle' => $handle,
        'enabled' => true,
        'trigger_type' => $trigger,
        'url' => 'https://example.test/'.$handle,
        'method' => 'POST',
        'payload_type' => 'raw_json',
        'queue_enabled' => true,
    ]);
}

/** @return list<TriggerEvent> */
function detected(): array
{
    return Event::dispatched(TriggerDetected::class)->map(fn ($args) => $args[0]->trigger)->values()->all();
}

beforeEach(function () {
    $this->course = $this->makeCourse('stimme', ['title' => 'Stimme im Chor', 'sequencing_mode' => 'lesson']);
    $this->makeLesson($this->course, 'eins', ['sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($this->course, 'zwei', ['sort_order' => 2, 'item_type' => 'text']);

    tap(User::make()->id('lena')->email('lena@example.test')->set('name', 'Lena Sänger'))->save();
});

it('registers every course event as a trigger of its own source type, in the reader\'s language', function () {
    $registry = WebhookManager::triggers();

    expect(WebhookManagerBridge::TRIGGERS)->toHaveCount(12);

    foreach (WebhookManagerBridge::TRIGGERS as $handle) {
        expect($registry->get($handle))->toBeInstanceOf(CoursesTrigger::class)
            ->and($registry->get($handle)->sourceType())->toBe('courses')
            ->and($registry->options())->toHaveKey($handle);
    }

    app()->setLocale('de');
    expect($registry->get('courses.quiz_passed')->label())->toBe('Kurse: Quiz bestanden');

    app()->setLocale('en');
    expect($registry->get('courses.quiz_passed')->label())->toBe('Courses: quiz passed');
});

it('sends enrolment and completion with the learner looked up, the course named and the brand', function () {
    Event::fake([TriggerDetected::class]);

    Courses::enroll('lena', 'stimme');
    Courses::acknowledgeLesson('lena', 'stimme', 'eins');

    $events = collect(detected())->keyBy('triggerHandle');

    expect($events->keys()->all())->toContain('courses.learner_enrolled', 'courses.lesson_completed', 'courses.lesson_unlocked');

    $enrolled = $events['courses.learner_enrolled'];
    $brand = app('brand-context')->current();

    expect($enrolled->sourceType)->toBe('courses')
        ->and($enrolled->sourceReference)->toBe($this->course)
        ->and($enrolled->payload)->toBe([
            'event' => 'courses.learner_enrolled',
            'event_id' => $enrolled->payload['event_id'],
            'occurred_at' => $enrolled->payload['occurred_at'],
            'brand' => ['id' => $brand->id, 'handle' => $brand->handle],
            'subject_type' => 'course',
            'subject_id' => $this->course,
            'learner' => ['id' => 'lena', 'email' => 'lena@example.test', 'name' => 'Lena Sänger'],
            'course' => ['id' => $this->course, 'slug' => 'stimme', 'title' => 'Stimme im Chor'],
        ])
        ->and($enrolled->payload['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');

    // A lesson state row carries watch positions and item payloads; none of it goes out.
    $completed = $events['courses.lesson_completed']->payload;
    expect(array_keys($completed))->toBe(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'learner', 'course', 'lesson', 'source', 'completed_at'])
        ->and($completed['lesson']['slug'])->toBe('eins')
        ->and($completed['source'])->toBe('manual')
        ->and($completed['completed_at'])->toBeString();

    expect($events['courses.lesson_unlocked']->payload['lesson'])->toBe(['slug' => 'zwei']);
});

it('sends only the id of a learner no user answers for', function () {
    Event::fake([TriggerDetected::class]);

    Courses::enroll('gone', 'stimme');

    expect(detected()[0]->payload['learner'])->toBe(['id' => 'gone']);
});

it('sends a quiz attempt with its score and result, and a team seat with owner and invitee', function () {
    Event::fake([TriggerDetected::class]);

    event(new QuizFailed('lena', $this->course, 'stimme', 'zwei', 'stimmcheck', 40, 'unsicher', 17));
    event(new TeamMemberAdded('lena', $this->course, 'stimme', 'neu@example.test', 'chor-paket'));

    [$quiz, $seat] = detected();

    expect($quiz->triggerHandle)->toBe('courses.quiz_failed')
        ->and(array_diff_key($quiz->payload, array_flip(['event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id'])))->toBe([
            'event' => 'courses.quiz_failed',
            'learner' => ['id' => 'lena', 'email' => 'lena@example.test', 'name' => 'Lena Sänger'],
            'course' => ['id' => $this->course, 'slug' => 'stimme', 'title' => 'Stimme im Chor'],
            'lesson' => ['slug' => 'zwei'],
            'assessment' => 'stimmcheck',
            'score' => 40,
            'passed' => false,
            'result_key' => 'unsicher',
            'response_id' => 17,
        ]);

    expect($seat->triggerHandle)->toBe('courses.team_member_added')
        ->and($seat->payload['member'])->toBe(['email' => 'neu@example.test'])
        ->and($seat->payload['owner']['email'])->toBe('lena@example.test')
        ->and($seat->payload['product'])->toBe('chor-paket');
});

it('hands a lesson completion to the outbound webhook listening for it, and to no other', function () {
    Queue::fake();
    outboundHook('courses.lesson_completed', 'fertig');
    outboundHook('courses.quiz_passed', 'quiz');

    Courses::acknowledgeLesson('lena', 'stimme', 'eins');

    Queue::assertPushed(ProcessOutboundDeliveryJob::class, 1);
    // Filed under the course in the delivery log, so it can be read from there.
    $this->assertDatabaseHas('webhook_deliveries', [
        'trigger_type' => 'courses.lesson_completed',
        'trigger_reference' => $this->course,
        'subject_type' => 'course',
        'subject_id' => $this->course,
    ]);
    $this->assertDatabaseMissing('webhook_deliveries', ['trigger_type' => 'courses.quiz_passed']);
});

it('fires the hooks of the course\'s brand when no brand is current, as from a payment webhook', function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $akademie = Brand::create(['handle' => 'akademie', 'name' => 'Akademie']);
    $studio = Brand::create(['handle' => 'studio', 'name' => 'Studio']);
    $this->makeCourse('marke', ['brand' => 'akademie']);
    $this->makeLesson(Courses::course('marke')['id'], 'nur', ['item_type' => 'text']);

    Queue::fake();
    app('brand-context')->runFor($akademie, fn () => outboundHook('courses.learner_enrolled', 'akademie-hook'));
    app('brand-context')->runFor($studio, fn () => outboundHook('courses.learner_enrolled', 'studio-hook'));
    app('brand-context')->forget();

    Courses::enroll('lena', 'marke');

    Queue::assertPushed(ProcessOutboundDeliveryJob::class, 1);
    $delivery = DB::table('webhook_deliveries')->where('trigger_type', 'courses.learner_enrolled')->sole();
    expect((int) $delivery->brand_id)->toBe($akademie->id)
        ->and(json_decode((string) $delivery->request_body, true)['payload']['brand'] ?? null)->toBe(['id' => $akademie->id, 'handle' => 'akademie']);
});

it('gives the same moment the same event_id and the moment\'s own time, however often it is sent', function () {
    Event::fake([TriggerDetected::class]);
    Courses::acknowledgeLesson('lena', 'stimme', 'eins');
    $state = LessonState::query()->where('lesson_slug', 'eins')->sole();

    $this->travel(5)->minutes();

    // A second delivery of the same moment, as a replay would send it.
    $again = WebhookManager::triggers()->get('courses.lesson_completed')->build(new LessonCompleted($state, 'manual'));
    $first = collect(detected())->firstWhere('triggerHandle', 'courses.lesson_completed');

    expect($again->payload['event_id'])->toBe($first->payload['event_id'])
        // The suite's recipe: sha1(handle|<type>:<id>|<the row's time>).
        ->and($first->payload['event_id'])->toBe(sha1('courses.lesson_completed|lesson_state:'.$state->id.'|'.$state->completed_at->format(DATE_ATOM)))
        ->and($again->eventAt->format(DATE_ATOM))->toBe($state->completed_at->format(DATE_ATOM))
        ->and($again->payload['occurred_at'])->toBe($state->completed_at->format(DATE_ATOM));
});

it('sends nothing for a moment whose brand does not exist, rather than the current brand\'s hooks', function () {
    Queue::fake();
    Log::spy();
    outboundHook('courses.learner_enrolled', 'aktuell');

    event(new LearnerEnrolled('lena', $this->course, 'stimme', 999));

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('webhook_deliveries', 0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'brand that cannot be set') && ($context['brand_id'] ?? null) === 999);
});

it('sends a moment only once its transaction is committed, and never after a rollback', function () {
    Event::fake([TriggerDetected::class]);

    try {
        DB::transaction(function () {
            Courses::enroll('lena', 'stimme');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(detected())->toBe([]);

    DB::transaction(function () {
        Courses::enroll('lena', 'stimme');
        expect(detected())->toBe([]);
    });

    expect(collect(detected())->pluck('triggerHandle')->all())->toContain('courses.learner_enrolled');
});

it('never breaks the course write when the webhook manager throws', function () {
    Event::listen(TriggerDetected::class, fn () => throw new RuntimeException('down'));

    Courses::enroll('lena', 'stimme');

    expect(Courses::summary('lena', 'stimme'))->toBeArray();
});
