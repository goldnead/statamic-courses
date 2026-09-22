<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Tests\Fixtures\Learner;
use Goldnead\Entitlements\Facades\Entitlements;
use Statamic\Auth\Eloquent\User as EloquentUser;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Through a real Antlers view, the way a site renders it. `Antlers::parse()`
 * on a bare string skips tag pairs in this bed, which would make every pair
 * test pass or fail for the wrong reason.
 */
function render(string $template): string
{
    $path = sys_get_temp_dir().'/courses-tag-'.bin2hex(random_bytes(6)).'.antlers.html';
    file_put_contents($path, $template);

    try {
        return trim(view()->file($path)->render());
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    $this->learner = Learner::query()->create(['email' => 'a@example.test']);

    $course = $this->makeCourse('cvt-101', ['title' => 'CVT 101', 'sequencing_mode' => 'lesson']);
    $basics = $this->makeLesson($course, 'basics', ['title' => 'Basics', 'sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($course, 'modes', ['title' => 'Modes', 'sort_order' => 2, 'item_type' => 'text']);
    $this->makeLesson($course, 'effects', ['title' => 'Effects', 'sort_order' => 3, 'prerequisite_lessons' => [$basics]]);
    $this->makeCourse('choir', ['title' => 'Choir']);

    Entitlements::grant($this->learner, 'cvt-101', 'test');
});

function signIn(Learner $learner): void
{
    // What User::current() returns on an eloquent install.
    $user = (new EloquentUser)->model($learner);
    User::shouldReceive('current')->andReturn($user);
}

it('lists every course with access and progress for the learner', function () {
    signIn($this->learner);
    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');

    $out = render('{{ courses }}{{ slug }}:{{ has_access ? "yes" : "no" }}:{{ progress:percent }};{{ /courses }}');

    expect($out)->toBe('choir:no:;cvt-101:yes:33;');
});

it('filters to the courses the learner may open', function () {
    signIn($this->learner);

    expect(render('{{ courses only="accessible" }}{{ slug }};{{ /courses }}'))->toBe('cvt-101;');
});

it('renders the progress of one course', function () {
    signIn($this->learner);
    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');

    expect(render('{{ courses:progress course="cvt-101" }}{{ status }} {{ completed_lessons }}/{{ total_lessons }}{{ /courses:progress }}'))
        ->toBe('in_progress 1/3');
});

it('lists lessons with state and the reason a lesson is locked', function () {
    signIn($this->learner);
    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');

    $out = render('{{ courses:lessons course="cvt-101" }}{{ slug }}={{ is_completed ? "done" : (is_locked ? lock_reason : "open") }};{{ /courses:lessons }}');

    expect($out)->toBe('basics=done;modes=open;effects=sequence;');
});

it('names the prerequisite as the reason when that is what holds', function () {
    signIn($this->learner);
    Entry::query()->where('collection', 'courses')->where('slug', 'cvt-101')->first()
        ->set('sequencing_mode', 'none')->save();

    expect(render('{{ courses:lessons course="cvt-101" }}{{ if is_locked }}{{ slug }}={{ lock_reason }}{{ /if }}{{ /courses:lessons }}'))
        ->toBe('effects=prerequisite');
});

it('points at the lesson to continue with', function () {
    signIn($this->learner);
    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');

    expect(render('{{ courses:continue course="cvt-101" }}{{ title }} {{ url }}{{ /courses:continue }}'))->toBe('Modes /courses/cvt-101/modes');
});

it('shows a learner without access no outline and no progress', function () {
    $stranger = Learner::query()->create(['email' => 'b@example.test']);
    signIn($stranger);

    expect(render('{{ courses:lessons course="cvt-101" }}x{{ /courses:lessons }}'))->toBe('')
        ->and(render('{{ courses:progress course="cvt-101" }}{{ percent }}{{ /courses:progress }}'))->toBe('')
        ->and(render('{{ courses:continue course="cvt-101" }}{{ slug }}{{ /courses:continue }}'))->toBe('');
});

it('shows a guest the catalogue without progress', function () {
    User::shouldReceive('current')->andReturn(null);

    expect(render('{{ courses }}{{ slug }}:{{ has_access ? "yes" : "no" }};{{ /courses }}'))->toBe('choir:no;cvt-101:no;')
        ->and(render('{{ courses:lessons course="cvt-101" }}x{{ /courses:lessons }}'))->toBe('');
});

it('renders a form with CSRF token and the lesson fields', function () {
    signIn($this->learner);

    $out = render('{{ courses:form course="cvt-101" lesson="basics" do="acknowledge" redirect="/done" }}<button>Done</button>{{ /courses:form }}');

    expect($out)->toContain('action="http://localhost/!/courses/progress"')
        ->toContain('name="_token"')
        ->toContain('name="course" value="cvt-101"')
        ->toContain('name="lesson" value="basics"')
        ->toContain('name="action" value="acknowledge"')
        ->toContain('name="_redirect" value="/done"')
        ->toContain('<button>Done</button>');
});

it('renders no form when the route is switched off', function () {
    signIn($this->learner);
    config()->set('courses.routes.enabled', false);

    expect(render('{{ courses:form course="cvt-101" lesson="basics" }}<button>Done</button>{{ /courses:form }}'))->toBe('');
});
