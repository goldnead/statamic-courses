# Changelog

## Unreleased

### Added
- Course and lesson collections with blueprints, installed by `courses:install`.
- Lesson state, lesson event log and enrollment tables.
- Sequencing (none, section, lesson), prerequisites, phase gating, milestone auto-completion.
- Drip by schedule: lessons open by week, counted from enrollment.
- Progress rollup per course with continue target.
- `CourseAccess` seam, answered by statamic-entitlements when installed, closed otherwise.
- `LessonCompleted` and `CourseCompleted` events.
