<?php

use Goldnead\Assessments\Events\AssessmentCompleted;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\QuizPassed;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $id = $this->makeCourse('cvt', ['sequencing_mode' => 'lesson']);
    $this->makeLesson($id, 'check', ['sort_order' => 1, 'item_type' => 'quiz', 'assessment' => 'stimm-check', 'assessment_min_score' => 6]);
    $this->makeLesson($id, 'next', ['sort_order' => 2, 'item_type' => 'text']);

    $this->user = tap(User::make()->id('learner')->email('learner@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'learner'), 'cvt', 'test');
});

function submitted(int $score, ?string $level = null, string $handle = 'stimm-check'): AssessmentCompleted
{
    return new AssessmentCompleted((object) [
        'id' => 42,
        'email' => 'learner@example.test',
        'score' => $score,
        'result_key' => $level,
        'assessment' => (object) ['handle' => $handle],
    ]);
}

it('completes the lesson and opens the next one when the learner passes', function () {
    Event::fake([QuizPassed::class, LessonCompleted::class, LessonUnlocked::class]);
    $this->actingAs($this->user);

    expect(Courses::isLessonLocked('learner', 'cvt', 'next'))->toBeTrue();

    event(submitted(8));

    $lesson = Courses::lesson('learner', 'cvt', 'check');

    expect($lesson['is_completed'])->toBeTrue()
        ->and($lesson['progress']['item_payload'])->toMatchArray(['assessment' => 'stimm-check', 'score' => 8, 'attempts' => 1, 'passed' => true])
        ->and(Courses::isLessonLocked('learner', 'cvt', 'next'))->toBeFalse();

    Event::assertDispatched(QuizPassed::class, fn ($e) => $e->lessonSlug === 'check' && $e->score === 8 && $e->responseId === 42);
    Event::assertDispatched(LessonCompleted::class, fn ($e) => $e->source === 'assessment');
    Event::assertDispatched(LessonUnlocked::class, fn ($e) => $e->lessonSlug === 'next' && $e->source === 'assessment');
});

it('records a failed attempt and keeps the lesson open', function () {
    Event::fake([QuizFailed::class, QuizPassed::class]);
    $this->actingAs($this->user);

    event(submitted(3));
    event(submitted(4));

    $lesson = Courses::lesson('learner', 'cvt', 'check');

    expect($lesson['is_completed'])->toBeFalse()
        ->and($lesson['progress']['status'])->toBe('in_progress')
        ->and($lesson['progress']['item_payload'])->toMatchArray(['score' => 4, 'attempts' => 2, 'passed' => false])
        ->and(Courses::isLessonLocked('learner', 'cvt', 'next'))->toBeTrue();

    Event::assertDispatchedTimes(QuizFailed::class, 2);
    Event::assertNotDispatched(QuizPassed::class);
});

it('can require a result level instead of, or as well as, a score', function () {
    $id = $this->makeCourse('levels');
    $this->makeLesson($id, 'q', ['item_type' => 'quiz', 'assessment' => 'stimm-check', 'assessment_pass_levels' => ['advanced']]);
    Entitlements::grant(new SubjectReference('user', 'learner'), 'levels', 'test');
    $this->actingAs($this->user);

    event(submitted(9, 'beginner'));
    expect(Courses::lesson('learner', 'levels', 'q')['is_completed'])->toBeFalse();

    event(submitted(9, 'advanced'));
    expect(Courses::lesson('learner', 'levels', 'q')['is_completed'])->toBeTrue();
});

it('ignores a submission without a signed-in learner, whatever address it carries', function () {
    event(submitted(10));

    expect(Courses::lesson('learner', 'cvt', 'check')['is_completed'])->toBeFalse();
});

it('ignores other assessments and courses the learner cannot open', function () {
    $id = $this->makeCourse('closed');
    $this->makeLesson($id, 'q', ['item_type' => 'quiz', 'assessment' => 'stimm-check']);
    $this->actingAs($this->user);

    event(submitted(10, null, 'another-check'));
    event(submitted(10));

    expect(Courses::lesson('learner', 'closed', 'q')['is_completed'])->toBeFalse();
});

it('announces a pass once, not again on a retake', function () {
    Event::fake([QuizPassed::class]);
    $this->actingAs($this->user);

    event(submitted(8));
    event(submitted(9));

    Event::assertDispatchedTimes(QuizPassed::class, 1);
});

it('tells the lesson template where the quiz is and how the learner did', function () {
    $this->actingAs($this->user);
    $lessonId = Entry::query()->where('slug', 'check')->first()->id();
    $render = function (string $template): string {
        $path = sys_get_temp_dir().'/courses-quiz-'.bin2hex(random_bytes(6)).'.antlers.html';
        file_put_contents($path, $template);

        try {
            return trim(view()->file($path)->render());
        } finally {
            @unlink($path);
        }
    };
    $template = '{{ courses:quiz lesson="'.$lessonId.'" }}{{ assessment }}:{{ passed ? "yes" : "no" }}:{{ attempts }}:{{ pass_score }}{{ /courses:quiz }}';

    expect($render($template))->toBe('stimm-check:no:0:6');

    event(submitted(3));
    expect($render($template))->toBe('stimm-check:no:1:6');

    event(submitted(7));
    expect($render($template))->toBe('stimm-check:yes:2:6');
});

it('shows a super user the quiz of a lesson its audience rule hides from them', function () {
    $lesson = Entry::query()->where('slug', 'check')->first();
    $lesson->set('audience_groups', ['altos'])->save();
    $this->actingAs(tap(User::make()->id('admin')->email('admin@example.test')->makeSuper())->save());

    $path = sys_get_temp_dir().'/courses-quiz-'.bin2hex(random_bytes(6)).'.antlers.html';
    file_put_contents($path, '{{ courses:quiz lesson="'.$lesson->id().'" }}{{ assessment }}:{{ attempts }}{{ /courses:quiz }}');

    try {
        expect(trim(view()->file($path)->render()))->toBe('stimm-check:0');
    } finally {
        @unlink($path);
    }
});

it('counts a quiz whose questionnaire belongs to another brand than the course grant', function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    $courses = Brand::create(['handle' => 'akademie', 'name' => 'Akademie']);
    $quizzes = Brand::create(['handle' => 'tests', 'name' => 'Tests']);

    $id = $this->makeCourse('branded');
    $this->makeLesson($id, 'q', ['item_type' => 'quiz', 'assessment' => 'stimm-check']);
    app('brand-context')->runFor($courses, fn () => Entitlements::grant(new SubjectReference('user', 'learner'), 'branded', 'test'));
    $this->actingAs($this->user);

    // The questionnaire's page runs in its own brand.
    app('brand-context')->runFor($quizzes, fn () => event(submitted(10)));

    expect(app('brand-context')->runFor($courses, fn () => Courses::lesson('learner', 'branded', 'q')['is_completed']))->toBeTrue();
});

it('still refuses to tick a quiz off by hand', function () {
    expect(Courses::setLessonCompletion('learner', 'cvt', 'check', true))->toBeNull();
});
