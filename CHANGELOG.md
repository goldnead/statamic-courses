# Changelog

## Unreleased

### Added
- Lesson blocks: text, callout, columns, FAQ, video, download and button, rendered by
  `{{ courses:blocks }}` with overridable partials. The markdown `content` stays and renders
  first. Private downloads have their own field on statamic-private-media's container and are
  signed for `course:<slug>`; a MediaAccess from courses answers that resource with
  `canAccess()`, so team members and bundle holders get the file. YouTube and Vimeo wait behind
  statamic-consent's gate when it is installed.
- `courses:install --merge` (with `--dry-run`): adds missing fields and select options to
  existing blueprints and changes nothing else; translates collection titles still in English.
- Drip modes `days`, `date`, `day_of_month`, `payments` and `after_trial` (lesson fields
  `drip_after`, `drip_date`; course field `drip_day_of_month`), `Courses::recordBilling()`.
- Lessons and sections limited to entitlements, user groups, LeadHub tags or LeadHub segments.
- Quiz lessons from statamic-assessments (`assessment`, `assessment_min_score`,
  `assessment_pass_levels`): a pass completes the lesson, a fail records the attempt; applied
  brand-neutrally. `{{ courses:quiz }}`.
- `on_payment_failure` per course (`keep`, `pause_drip`, `revoke`), applied from
  statamic-payments' subscription events. A hold remembers its subscription and only takes away
  what that one paid for; a new purchase lifts it. Payment holds on the Course Progress screen,
  lifted there with the permission `manage course holds`.
- Bundles on the course (`bundles`) and team seats per purchase (`team_seats`,
  `courses_team_members`, `POST /!/courses/team`, `{{ courses:team }}`,
  `{{ courses:team_form }}`); a bundle's team covers all its courses.
- Calendar drip days are counted in `statamic.system.display_timezone`.
- Events `LearnerEnrolled`, `LessonUnlocked`, `QuizPassed`, `QuizFailed`, `DripPaused`,
  `DripResumed`, `CourseAccessSuspended`, `CourseAccessRestored`, `TeamMemberAdded`,
  `TeamMemberRemoved`.
- Config `downloads.container`.

### Changed
- Three migrations: billing, hold and hold-source columns on `courses_enrollments`, table
  `courses_team_members`. Run `php artisan migrate`, then `courses:install --merge`.
- `courses:install` writes an asset container into the download field.
- A lesson locked by the drip reports its drip reason first, also before the learner enrolled.

## 0.1.2 (2026-09-23)

### Fixed
- CourseCompleted fires once under concurrent completion.

## 0.1.1 (2026-09-22)

### Changed
- The help link on the Course Progress screen points to https://docs.adriangoldner.dev/courses/.
- Icon, cover and Marketplace art.

## 0.1.0 (2026-09-22)

First release, extracted from adriangoldner.com.

### Added
- Course and lesson collections with blueprints and routes, installed by `courses:install`.
- Lesson state, lesson event log and enrollment tables, all prefixed `courses_`.
- Sequencing (none, section, lesson), prerequisites, phase gating with test-out lessons,
  milestone auto-completion.
- Drip by schedule: lessons open by week, counted from enrollment.
- Lesson types kept as authored; quiz and assignment complete only through `completeLesson()`.
- `updateLessonItem()` for unfinished work (attempts, drafts).
- Progress rollup per course with continue target and lock reasons.
- `CourseAccess` seam, answered by statamic-entitlements (1.3+) when installed, closed otherwise.
  Eloquent users and Statamic's eloquent user wrapper resolve to their model.
- `LessonCompleted` and `CourseCompleted` events.
- Antlers tags `courses`, `courses:progress`, `courses:lessons`, `courses:continue`, `courses:form`.
- `POST /!/courses/progress`, switchable with `COURSES_ROUTES_ENABLED`.
- Control Panel screen "Course Progress" with permission `view course progress`.
