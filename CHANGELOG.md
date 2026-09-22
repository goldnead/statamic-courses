# Changelog

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
