# Statamic Courses

Courses for Statamic 6: modules and lessons as entries, a state per learner and lesson, sequencing,
drip by schedule or by progress, and a progress rollup. Access to a course is asked of
[statamic-entitlements](https://github.com/goldnead/statamic-entitlements).

> Phase 1: the domain layer. No Control Panel screens, no Antlers tags, no templates yet.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- Optional: goldnead/statamic-entitlements 1.4+ (without it every course is closed)

## Install

```bash
composer require goldnead/statamic-courses
php artisan migrate
php artisan courses:install   # creates the `courses` and `course_lessons` collections with blueprints
```

Collection handles are configurable in `config/courses.php` before installing.

## Structure

A **course** entry carries `sequencing_mode` (`none`, `section`, `lesson`), `drip_mode` (`none`,
`schedule`) and `product`, the entitlements product that opens it (defaults to the course slug).

A **lesson** entry points at its course (`course`) and orders itself with `section_key`,
`section_order` and `sort_order`. Optional: `phase_key` / `phase_order` (later phases wait for
earlier ones), `week` (with schedule drip), `prerequisite_lessons`, `item_type` (`video`, `text`,
`milestone`) and `video_duration` (`mm:ss`).

## What locks a lesson

1. The course's `sequencing_mode`.
2. Its prerequisites, until each is completed.
3. Its phase, until every earlier phase is completed.
4. With `drip_mode: schedule`, its week: week *n* opens `(n − 1) × 7` days after enrollment, or
   earlier if the learner was moved on with `advanceToWeek()`.

A completed lesson is never locked. A milestone completes itself once its prerequisites, or all
other lessons of its phase, are completed. Writes to a locked lesson are refused.

## Usage

```php
use Goldnead\Courses\Facades\Courses;

Courses::canAccess($user, 'cvt-101');              // entitlements decides
Courses::enroll($user, 'cvt-101');                 // starts the drip clock
Courses::outline($user, 'cvt-101');                // sections, lessons, progress, locks, rollup
Courses::summary($user, 'cvt-101');                // status, percent, continue_lesson
Courses::lesson($user, 'cvt-101', 'intro');
Courses::updateLessonProgress($user, 'cvt-101', 'intro', ['watched_seconds' => 540, 'resume_seconds' => 540]);
Courses::setLessonCompletion($user, 'cvt-101', 'intro', true);
Courses::acknowledgeLesson($user, 'cvt-101', 'reading');
```

`$user` is a Statamic user, any `Authenticatable`, or an id. Reads never check access; ask
`canAccess()` first.

Events: `LessonCompleted` on every transition to completed, `CourseCompleted` once per learner and
course. Every start, quarter mark and completion is logged to `courses_lesson_events`. All tables carry the `courses_` prefix.

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
DB_DRIVER=mysql composer test:mysql
```
