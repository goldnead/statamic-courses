<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Integrations\WebhookManager\CoursesTrigger;
use Goldnead\Courses\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        ->and($enrolled->sourceReference)->toBe('course:'.$this->course.':user:lena')
        ->and($enrolled->payload)->toBe([
            'event' => 'courses.learner_enrolled',
            'occurred_at' => $enrolled->payload['occurred_at'],
            'brand' => ['id' => $brand->id, 'handle' => $brand->handle],
            'learner' => ['id' => 'lena', 'email' => 'lena@example.test', 'name' => 'Lena Sänger'],
            'course' => ['id' => $this->course, 'slug' => 'stimme', 'title' => 'Stimme im Chor'],
        ])
        ->and($enrolled->payload['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');

    // A lesson state row carries watch positions and item payloads; none of it goes out.
    $completed = $events['courses.lesson_completed']->payload;
    expect(array_keys($completed))->toBe(['event', 'occurred_at', 'brand', 'learner', 'course', 'lesson', 'source', 'completed_at'])
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
        ->and(array_diff_key($quiz->payload, array_flip(['occurred_at', 'brand'])))->toBe([
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
    $this->assertDatabaseHas('webhook_deliveries', [
        'trigger_type' => 'courses.lesson_completed',
        'trigger_reference' => 'course:'.$this->course.':user:lena',
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

it('never breaks the course write when the webhook manager throws', function () {
    Event::listen(TriggerDetected::class, fn () => throw new RuntimeException('down'));

    Courses::enroll('lena', 'stimme');

    expect(Courses::summary('lena', 'stimme'))->toBeArray();
});
