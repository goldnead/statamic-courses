# Extraction from adriangoldner.com

Source: `~/projects/adriangoldner.com`, branch `staging`, read on 2026-09-22. Nothing there was
changed. Model for the package layout: `statamic-entitlements`, pulled out of the same site.

## What came from where

| Here | Source in adriangoldner.com | Change |
|---|---|---|
| `database/migrations/…_create_courses_lesson_states_table.php` | `2026_03_07_105424_create_course_lesson_states_table.php` + `2026_07_20_150000_add_payload_to_course_lesson_states.php` | Table `courses_lesson_states`: prefixed, because adg's own `course_lesson_states` has a different schema and stays. `user_id` is a string without foreign key (Statamic users may be flat files). `resource_id` / `resource_slug` dropped, they pointed at the site's `member_products`. String lengths set for the MySQL key limit. |
| `database/migrations/…_create_courses_lesson_events_table.php` | `2026_03_07_105430_create_course_lesson_events_table.php` | `courses_lesson_events`; same changes; `payload_json` → `payload`. |
| `database/migrations/…_create_courses_enrollments_table.php` | `2026_07_19_140000_create_plan_enrollments_table.php` | `courses_enrollments`; `plan_slug` → `course_entry_id`. `current_week`, `week_states`, `started_at`, `completed_at` kept. |
| `src/Models/LessonState.php`, `LessonEvent.php`, `Enrollment.php` | `app/Models/CourseLessonState.php`, `CourseLessonEvent.php`, `PlanEnrollment.php` | No `user()` relation, no factories. |
| `src/CourseProgress.php` | `app/Services/Courses/CourseProgressService.php`: `buildCoursePayload`, `buildCourseSummary`, `buildLessonPayload`, `isLessonLocked`, `updateLessonProgress`, `updateLessonCompletion`, `acknowledgeLesson` (same four ackable types), `writeItemState` (incl. markInProgress), `skipCompleteEarlierPhases`, `sectionsPayload`, `buildCourseSummaryPayload`, `statesForContext`, `progressMap`, `resolveVideoDurationSeconds` | Renamed to `outline` / `summary` / `lesson` / `setLessonCompletion`. Writes to a locked lesson are refused (the source left that to its controllers). `completeLesson()` / `updateLessonItem()` are the public face of `writeItemState` for the host's quiz, assignment and exercise endpoints. State rows via `createOrFirst`. |
| `src/Progress/LockResolver.php` | same service: `lessonLockMap` (incl. `is_test_out` exemption), `baseLockMap`, `applyMilestoneAutoCompletion`, `milestonePrerequisitesMet` | Schedule gate and `reason()` added, see below. |
| `src/Progress/LessonProgress.php` | same service: `defaultLessonProgress`, `lessonProgressPayload`, `calculateCompletionPercent`, `deriveStatus`, `hasStarted`, `normalizeManualCompletionState`; threshold 90 | Threshold moved to config. |
| `src/Support/EventRecorder.php` | `app/Services/Courses/CourseAnalyticsService.php`: `recordProgressUpdate`, `recordManualCompletion`, `recordEvent` | `firstOrCreate` → `createOrFirst`. Switchable by config. |
| `src/Support/ProgressReport.php` | same class: `courseOverviewRow` | Learners are those who enrolled or touched a lesson, not those entitled (no generic "all holders of product X" query). `stuck` added. |
| `src/Support/CourseRepository.php` | `app/Services/Content/StatamicMemberContentService.php`: `getCourseByResourceSlug`, `getCourseLessonsByResourceSlug`, `normalizeLessonItemFields` (generic part), `parseDurationSeconds` | Lesson → course via an `entries` field `course` instead of the text join on `resource_slug`. `item_type` kept as authored, only empty reads as `video` (34 of 48 lessons on staging). Prerequisite ids resolved within the course. |
| `resources/blueprints/course.yaml`, `course_lesson.yaml` | `resources/blueprints/collections/courses/course.yaml`, `course_lessons/course_lesson.yaml` | Generic fields plus all eight item types and `is_test_out`. Added `product`, `drip_mode`, `course`. |
| `tests/Feature/CourseProgressTest.php` | `tests/Unit/CourseProgressServiceTest.php` (both tests) | Circle import fixture replaced by the same course shape built by hand. |
| `tests/Unit/WeekAndDurationTest.php` | `tests/Unit/Training/PlanProgressServiceTest.php` (week semantics) | Ported as `openWeek()`. |

## New, not extracted

- **Drip by schedule.** adg has no time-based unlock. Built only from pieces that exist there: the
  lesson field `week` and the enrollment's `started_at` / `current_week`. Week *n* opens
  `(n − 1) × 7` days after enrollment, or earlier if moved on by hand. Off by default.
- **`CourseAccess` seam** to statamic-entitlements (1.3+, adg locks 1.3.0), fail-closed without it.
  Eloquent models pass through, Statamic's eloquent user wrapper is unwrapped, bare ids use the auth
  model's morph class (adg: `App\Models\User`).
- **Proof-required types** (`quiz`, `assignment`): no manual tick, only `completeLesson()`.
- **Lock reasons** (`schedule`, `prerequisite`, `phase`, `sequence`).
- **`LessonCompleted` / `CourseCompleted` events.** Stand in for the direct calls into the
  community service (milestone posts) and analytics.
- **`courses:install`** with routes; `course_slug` computed value for the lesson route.
- **Antlers tags** and **`POST /!/courses/progress`** (switchable, `COURSES_ROUTES_ENABLED`). adg
  keeps its own Inertia player and API and should run with the route off.
- **CP screen "Course Progress"**.

## Left out, and why

Adrian-specific or belonging to another planned addon:

- `ProgressAggregator.php`: not course progress at all. Streak, weekly goal and heatmap over
  `PracticeSession` (VocalFlow practice). Stays in adg.
- Quiz scoring, reflection, assignment upload/review (`submitQuiz`, `submitReflection`,
  `submitAssignment`, `assignmentFile`, `reviewAssignment`, `AssignmentUploadService`,
  `sanitizeLessonForClient`): ticket `backlog-statamic-assessments`. They plug in through
  `updateLessonItem()` / `completeLesson()`.
- Exercise play counting (`recordExercisePlay`, `library_items` fields): VocalFlow exercise library.
- Coaching session linking (`linkCoachingSession`, `completeCoachingSession`,
  `CoachingLinkConflict`, `session_type_id`, `booking_url`): VocalFlow booking, ticket
  `backlog-statamic-coaching`.
- Community cross-link (`resolveCourseCommunitySpace`, `emitMilestoneActivities`,
  `source_space_slug`): ticket `backlog-statamic-community`; hook is `LessonCompleted`.
- `LearningPathBuilder.php` (phases → weeks → items DTO, German status texts, live events):
  presentation for adg's player.
- `CourseAnalyticsService` detail and roster (`courseDetail`, per-lesson drop-off, learner names):
  a second CP screen, not built yet.
- Media: `video_asset_key` (Hetzner `videos` container), transcript, attachments, media route:
  ticket `backlog-statamic-private-media`.
- Circle import fields (`source_space_id`, `source_lesson_id`, `source_updated_at`,
  `is_incomplete`, `section_index`), `course_kind`, `live_events`, `resource_slug`.

## Open questions for Adrian

1. **Schedule drip** as built (weekly, from enrollment, manual push forward): right model, or
   per-lesson "open after N days" or fixed dates?
2. **Switching adg over:** lesson → course link changed from `resource_slug` text to an `entries`
   field, tables are new. Needs a content and data migration (plan in
   `GoldnerOS/TASKS/courses-addon-adg-umstieg-2026-09-22.md`).
3. **Completion rate** on the CP screen counts learners who started, not buyers. Enough, or should
   it ask entitlements for all holders of the product?
4. **Settings**: threshold, stuck days, route switch are config. Move to brand-context settings, or
   leave as config?
