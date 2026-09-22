# Statamic Courses

Courses for Statamic 6: modules and lessons as entries, a state per learner and lesson, sequencing,
drip by schedule or by progress, and a progress rollup. Access to a course is asked of
[statamic-entitlements](https://github.com/goldnead/statamic-entitlements). Antlers tags and a
form route for the front end, and a "Course Progress" screen in the Control Panel.

Commercial, single edition (`pro`).

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- Optional: goldnead/statamic-entitlements 1.3+ (without it, or your own `CourseAccess`, every course is closed)

## Install

```bash
composer require goldnead/statamic-courses
php artisan migrate
php artisan courses:install   # collections `courses` and `course_lessons`, blueprints, routes
```

Collection handles are configurable in `config/courses.php` before installing. The install routes
courses at `/courses/{slug}` and lessons at `/courses/{course_slug}/{slug}`; change or clear the
routes as you like, `url` is then null and nothing breaks.

## Structure

A **course** entry carries `sequencing_mode` (`none`, `section`, `lesson`), `drip_mode` (`none`,
`schedule`) and `product`, the entitlements product that opens it (defaults to the course slug).

A **lesson** entry points at its course (`course`) and orders itself with `section_key`,
`section_order` and `sort_order`. Optional: `phase_key` / `phase_order` (later phases wait for
earlier ones), `is_test_out`, `week` (with schedule drip), `prerequisite_lessons`, `item_type` and
`video_duration` (`mm:ss`).

`item_type` decides how a lesson completes:

| Type | Completes by |
|---|---|
| `video` (default) | watching past `auto_completion_threshold` (90 %), or the learner's own tick |
| `text`, `milestone`, `coaching`, `exercise` | `acknowledgeLesson()`; a milestone also by itself once its phase is done |
| `quiz`, `assignment` (`proof_required_types`) | only `completeLesson()`, called by the code that checked the proof |
| anything else | the learner's tick or `completeLesson()` |

## What locks a lesson

1. The course's `sequencing_mode`.
2. Its prerequisites, until each is completed.
3. Its phase, until every earlier phase is completed. A test-out lesson stays open; completing it
   completes the earlier phases as `skipped`.
4. With `drip_mode: schedule`, its week: week *n* opens `(n − 1) × 7` days after enrollment, or
   earlier if the learner was moved on with `advanceToWeek()`.

A completed lesson is never locked. Writes to a locked lesson are refused. Each locked lesson
carries a `lock_reason`: `schedule`, `prerequisite`, `phase` or `sequence`.

## Usage

```php
use Goldnead\Courses\Facades\Courses;

Courses::canAccess($user, 'cvt-101');              // entitlements decides
Courses::enroll($user, 'cvt-101');                 // starts the drip clock
Courses::outline($user, 'cvt-101');                // sections, lessons, progress, locks, rollup
Courses::summary($user, 'cvt-101');                // status, percent, continue_lesson
Courses::lessons($user, 'cvt-101');                // flat, in reading order
Courses::lesson($user, 'cvt-101', 'intro');
Courses::updateLessonProgress($user, 'cvt-101', 'intro', ['watched_seconds' => 540, 'resume_seconds' => 540]);
Courses::setLessonCompletion($user, 'cvt-101', 'intro', true);
Courses::acknowledgeLesson($user, 'cvt-101', 'reading');
Courses::updateLessonItem($user, 'cvt-101', 'quiz-1', ['attempts' => 1, 'best_score' => 40]);
Courses::completeLesson($user, 'cvt-101', 'quiz-1', 'quiz', ['best_score' => 90]);
```

`$user` is a Statamic user, any `Authenticatable`, or an id. Reads never check access; ask
`canAccess()` first.

Events: `LessonCompleted` on every transition to completed (with its source), `CourseCompleted` once
per learner and course. Every start, quarter mark and completion is logged to
`courses_lesson_events`. All tables carry the `courses_` prefix.

## Antlers

All tags work for the signed-in learner. Without access to the course they render nothing.

```antlers
{{ courses only="accessible" }}
    <a href="{{ url }}">{{ title }}</a> {{ progress:percent }} %
{{ /courses }}

{{ courses:progress course="cvt-101" }}{{ completed_lessons }}/{{ total_lessons }}{{ /courses:progress }}

{{ courses:lessons course="cvt-101" }}
    {{ title }}: {{ if is_completed }}done{{ elseif is_locked }}{{ lock_reason }}{{ else }}open{{ /if }}
{{ /courses:lessons }}

{{ courses:continue course="cvt-101" }}<a href="{{ url }}">Continue with {{ title }}</a>{{ /courses:continue }}

{{ courses:form course="cvt-101" lesson="reading" do="acknowledge" redirect="/courses/cvt-101" }}
    <button>Mark as read</button>
{{ /courses:form }}
```

`courses:form` posts to `POST /!/courses/progress` (fields `course`, `lesson`, `action` =
`complete`, `incomplete`, `acknowledge` or `progress`, plus `watched_seconds` / `resume_seconds`).
The route needs a signed-in user with access to the course, carries CSRF, answers JSON when asked
and otherwise redirects back or to a local `_redirect`. A locked lesson or a quiz ticked by hand is
refused with 422. Switch it off with `COURSES_ROUTES_ENABLED=false`; the form tag then renders
nothing.

## Control Panel

**Course Progress** (permission `view course progress`): per course the learners, how many are in
progress or completed, the completion rate among those who started, how many are stuck (no
activity for `cp.stuck_after_days`, default 14) and the last activity. It sits in the suite's shared
nav section when statamic-payments provides one, under Content otherwise.

## Access

`Goldnead\Courses\Contracts\CourseAccess` is the seam. With statamic-entitlements installed it asks
`Entitlements::allows()`; without it every course is closed.

How a learner reaches entitlements: an Eloquent model passes through; a Statamic eloquent user
(`User::current()` on an eloquent install) is unwrapped to its model; anything else is looked up as
subject type `courses.entitlements.subject_type`. Unset, that is the auth model's morph class on an
eloquent install and `user` on a flat-file one.

A host with its own access rules binds its own implementation, and it wins over the default:

```php
$this->app->bind(\Goldnead\Courses\Contracts\CourseAccess::class, MyCourseAccess::class);
```

## Tests

```bash
composer test
DB_DRIVER=mysql DB_PORT=3306 composer test:mysql
```
