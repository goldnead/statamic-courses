<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\StatamicOffers\Offers;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\User;

beforeEach(fn () => Offers::reset());

/** A box as statamic-offers resolves it. */
function box(string $id, string $headline = 'Vokale & Aussprache'): array
{
    return [
        'id' => $id, 'handle' => $id, 'kicker' => 'Wie es weitergehen kann', 'headline' => $headline,
        'body' => 'Ein Kurs.', 'button_label' => 'Zum Kurs', 'url' => 'https://example.com/kurs', 'offer' => null,
    ];
}

it('ships the placement fields on the lesson and the course', function () {
    $lesson = Blueprint::find('collections.course_lessons.course_lesson');
    $course = Blueprint::find('collections.courses.course');

    expect($lesson->hasField('lesson_boxes'))->toBeTrue()
        ->and($lesson->hasField('video_hints'))->toBeTrue()
        ->and($lesson->field('lesson_boxes')->config()['fields'][0]['field']['collections'] ?? null)->toBe(['content_boxes'])
        ->and($course->hasField('course_boxes'))->toBeTrue();
});

it('hands the boxes of a lesson over by place, the course ones first', function () {
    $course = $this->makeCourse('cvt', ['course_boxes' => [['box' => ['b-kurs'], 'place' => 'end']]]);
    $this->makeLesson($course, 'letzte', [
        'lesson_boxes' => [
            ['box' => 'b-warteliste', 'place' => 'below_video'],
            ['box' => ['b-vokale'], 'place' => 'end'],
        ],
    ]);
    Offers::$boxes = ['b-kurs' => box('b-kurs', 'Kursweit'), 'b-warteliste' => box('b-warteliste', 'Warteliste'), 'b-vokale' => box('b-vokale')];

    $boxes = Courses::lessonBoxes(null, 'cvt', 'letzte');

    expect(array_column($boxes['below_video'], 'headline'))->toBe(['Warteliste'])
        ->and(array_column($boxes['end'], 'headline'))->toBe(['Kursweit', 'Vokale & Aussprache'])
        ->and($boxes['hints'])->toBe([]);
});

it('leaves out a box that statamic-offers does not show', function () {
    $course = $this->makeCourse('cvt');
    $this->makeLesson($course, 'l1', ['lesson_boxes' => [['box' => 'weg', 'place' => 'end'], ['box' => 'da', 'place' => 'end']]]);
    Offers::$boxes = ['da' => box('da')];

    expect(array_column(Courses::lessonBoxes(null, 'cvt', 'l1')['end'], 'handle'))->toBe(['da']);
});

it('turns the video hints into seconds and takes the text of the box when the row has none', function () {
    $course = $this->makeCourse('cvt');
    $this->makeLesson($course, 'video', ['video_hints' => [
        ['box' => 'b-wl', 'from' => '43:54', 'to' => null, 'text' => 'Du leitest einen Chor?'],
        ['box' => ['b-wl'], 'from' => '1:02:03', 'to' => '1:03:00', 'text' => ''],
        ['box' => 'b-wl', 'from' => '', 'text' => 'ohne Zeit'],
        ['box' => 'weg', 'from' => '10', 'text' => 'ohne Kasten'],
    ]]);
    Offers::$boxes = ['b-wl' => box('b-wl', 'Warteliste Ausbildung')];

    $hints = Courses::lessonBoxes(null, 'cvt', 'video')['hints'];

    expect($hints)->toHaveCount(2)
        ->and($hints[0])->toMatchArray(['from' => 2634, 'to' => null, 'text' => 'Du leitest einen Chor?', 'label' => 'Zum Kurs', 'url' => 'https://example.com/kurs'])
        ->and($hints[1])->toMatchArray(['from' => 3723, 'to' => 3780, 'text' => 'Warteliste Ausbildung']);
});

it('asks statamic-offers for the learner, so the audience can decide', function () {
    $course = $this->makeCourse('cvt');
    $this->makeLesson($course, 'l1', ['lesson_boxes' => [['box' => 'da', 'place' => 'end']]]);
    Offers::$boxes = ['da' => box('da')];
    $user = tap(User::make()->id('u1')->email('u1@example.test'))->save();

    Courses::lessonBoxes($user, 'cvt', 'l1');

    expect(Offers::$viewers)->not->toBeEmpty()
        ->and(Offers::$viewers[0]?->id())->toBe($user->id());
});

it('is empty for a lesson without boxes, an unknown lesson and an unknown course', function () {
    $course = $this->makeCourse('cvt');
    $this->makeLesson($course, 'l1');
    $leer = ['below_video' => [], 'end' => [], 'hints' => []];

    expect(Courses::lessonBoxes(null, 'cvt', 'l1'))->toBe($leer)
        ->and(Courses::lessonBoxes(null, 'cvt', 'gibt-es-nicht'))->toBe($leer)
        ->and(Courses::lessonBoxes(null, 'gibt-es-nicht', 'l1'))->toBe($leer);
});

it('can be added to a site whose blueprints predate it with --merge', function () {
    $lesson = Blueprint::find('collections.course_lessons.course_lesson');
    $contents = $lesson->contents();
    foreach ($contents['tabs'] as &$tab) {
        foreach ($tab['sections'] as &$section) {
            $section['fields'] = array_values(array_filter($section['fields'], fn ($f) => ! in_array($f['handle'] ?? '', ['lesson_boxes', 'video_hints'], true)));
        }
    }
    unset($tab, $section);
    $lesson->setContents($contents)->save();
    expect(Blueprint::find('collections.course_lessons.course_lesson')->hasField('video_hints'))->toBeFalse();

    $this->artisan('courses:install', ['--merge' => true])->assertSuccessful();

    expect(Blueprint::find('collections.course_lessons.course_lesson')->hasField('video_hints'))->toBeTrue()
        ->and(Blueprint::find('collections.course_lessons.course_lesson')->hasField('lesson_boxes'))->toBeTrue();
});

it('refuses a hint time the parser could not read, in front of the editor', function () {
    // Only what is said about the hints counts: the lesson has other required fields.
    $fails = function (array $row): bool {
        try {
            Blueprint::find('collections.course_lessons.course_lesson')
                ->fields()
                ->addValues(['title' => 'L', 'video_hints' => [$row]])
                ->validator()
                ->validate();

            return false;
        } catch (ValidationException $e) {
            return collect(array_keys($e->errors()))->contains(fn (string $key): bool => str_starts_with($key, 'video_hints'));
        }
    };

    expect($fails(['box' => ['b'], 'from' => '43:54', 'to' => null, 'text' => '']))->toBeFalse()
        ->and($fails(['box' => ['b'], 'from' => '1:02:03', 'to' => '1:03:00', 'text' => '']))->toBeFalse()
        ->and($fails(['box' => ['b'], 'from' => '2634', 'to' => '', 'text' => '']))->toBeFalse()
        ->and($fails(['box' => ['b'], 'from' => '43.54', 'to' => null, 'text' => '']))->toBeTrue()
        ->and($fails(['box' => ['b'], 'from' => '43:54', 'to' => 'bis Ende', 'text' => '']))->toBeTrue()
        ->and($fails(['box' => ['b'], 'from' => '', 'to' => null, 'text' => '']))->toBeTrue();
});
