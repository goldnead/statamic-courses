<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\Leadhub\LeadHubManager;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

beforeEach(function () {
    LeadHubManager::reset();

    $id = $this->makeCourse('choir', ['sequencing_mode' => 'lesson']);
    $this->makeLesson($id, 'intro', ['sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($id, 'altos-only', ['sort_order' => 2, 'item_type' => 'text', 'audience_groups' => ['altos']]);
    $this->makeLesson($id, 'vip', ['sort_order' => 3, 'item_type' => 'text', 'audience_entitlements' => ['vip-pass']]);
    $this->makeLesson($id, 'tagged', ['sort_order' => 4, 'item_type' => 'text', 'audience_tags' => ['Chorleitung']]);
    $this->makeLesson($id, 'segment', ['sort_order' => 5, 'item_type' => 'text', 'audience_segments' => ['warm-leads']]);
    $this->makeLesson($id, 'outro', ['sort_order' => 6, 'item_type' => 'text']);

    UserGroup::make()->handle('altos')->title('Altos')->save();

    $this->alto = tap(User::make()->id('alto')->email('alto@example.test'))->save();
    $this->alto->addToGroup('altos')->save();
    $this->tenor = tap(User::make()->id('tenor')->email('tenor@example.test'))->save();
});

function slugsFor(mixed $user): array
{
    return array_column(Courses::lessons($user, 'choir'), 'slug');
}

it('shows everybody the lessons without a rule, and only those', function () {
    expect(slugsFor($this->tenor))->toBe(['intro', 'outro']);
});

it('shows a lesson to members of a user group', function () {
    expect(slugsFor($this->alto))->toBe(['intro', 'altos-only', 'outro'])
        // By id as well as by object: reports only have the id.
        ->and(slugsFor('alto'))->toBe(['intro', 'altos-only', 'outro']);
});

it('shows a lesson to holders of an entitlement', function () {
    Entitlements::grant(new SubjectReference('user', 'tenor'), 'vip-pass', 'test');

    expect(slugsFor($this->tenor))->toBe(['intro', 'vip', 'outro']);
});

it('shows a lesson to contacts with a LeadHub tag, by name or slug', function () {
    LeadHubManager::$contacts['tenor@example.test'] = ['id' => 7, 'uuid' => 'c-7', 'tags' => ['Chorleitung']];

    expect(slugsFor($this->tenor))->toBe(['intro', 'tagged', 'outro']);
});

it('shows a lesson to members of a LeadHub segment', function () {
    LeadHubManager::$contacts['tenor@example.test'] = ['id' => 7, 'uuid' => 'c-7', 'tags' => []];
    LeadHubManager::$segments['warm-leads'] = ['c-7'];

    expect(slugsFor($this->tenor))->toBe(['intro', 'segment', 'outro']);
});

it('leaves hidden lessons out of the path and the count, so they neither lock nor hold back completion', function () {
    Courses::acknowledgeLesson($this->tenor, 'choir', 'intro');

    // lesson by lesson: outro follows intro directly for this learner.
    expect(Courses::isLessonLocked($this->tenor, 'choir', 'outro'))->toBeFalse();

    Courses::acknowledgeLesson($this->tenor, 'choir', 'outro');

    expect(Courses::summary($this->tenor, 'choir'))
        ->toMatchArray(['status' => 'completed', 'total_lessons' => 2, 'completed_lessons' => 2]);
});

it('refuses writes to a lesson the learner may not see', function () {
    expect(Courses::lesson($this->tenor, 'choir', 'altos-only'))->toBeNull()
        ->and(Courses::acknowledgeLesson($this->tenor, 'choir', 'altos-only'))->toBeNull();
});

it('hides a whole section by a rule on the course', function () {
    $id = $this->makeCourse('sections', ['section_audiences' => [
        ['section_key' => 'altos', 'groups' => ['altos']],
    ]]);
    $this->makeLesson($id, 'shared', ['section_key' => 'all', 'section_order' => 1]);
    $this->makeLesson($id, 'alto-part', ['section_key' => 'altos', 'section_order' => 2]);

    expect(array_column(Courses::lessons($this->tenor, 'sections'), 'slug'))->toBe(['shared'])
        ->and(array_column(Courses::lessons($this->alto, 'sections'), 'slug'))->toBe(['shared', 'alto-part']);
});
