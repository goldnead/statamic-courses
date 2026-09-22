# Extraction from adriangoldner.com

Source: `~/projects/adriangoldner.com`, branch `staging`, read on 2026-09-22. Nothing there was
changed. Model for the package layout: `statamic-entitlements`, pulled out of the same site.

## What came from where

| Here | Source in adriangoldner.com | Change |
|---|---|---|
| `database/migrations/…_create_course_lesson_states_table.php` | `2026_03_07_105424_create_course_lesson_states_table.php` + `2026_07_20_150000_add_payload_to_course_lesson_states.php` | `user_id` is a string without foreign key (Statamic users may be flat files). `resource_id` / `resource_slug` dropped, they pointed at the site's `member_products`. String lengths set for the MySQL key limit. |
| `database/migrations/…_create_course_lesson_events_table.php` | `2026_03_07_105430_create_course_lesson_events_table.php` | Same changes; `payload_json` → `payload`. |
| `database/migrations/…_create_course_enrollments_table.php` | `2026_07_19_140000_create_plan_enrollments_table.php` | `plan_slug` → `course_entry_id`. `current_week`, `week_states`, `started_at`, `completed_at` kept. |
| `src/Models/LessonState.php`, `LessonEvent.php`, `Enrollment.php` | `app/Models/CourseLessonState.php`, `CourseLessonEvent.php`, `PlanEnrollment.php` | No `user()` relation, no factories. |
| `src/CourseProgress.php` | `app/Services/Courses/CourseProgressService.php`: `buildCoursePayload`, `buildCourseSummary`, `buildLessonPayload`, `isLessonLocked`, `updateLessonProgress`, `updateLessonCompletion`, `acknowledgeLesson`, `writeItemState`, `sectionsPayload`, `buildCourseSummaryPayload`, `statesForContext`, `progressMap`, `resolveVideoDurationSeconds` | Renamed to `outline` / `summary` / `lesson` / `setLessonCompletion`. Writes to a locked lesson are refused (the source left that to its controllers). |
| `src/Progress/LockResolver.php` | same service: `lessonLockMap`, `baseLockMap`, `applyMilestoneAutoCompletion`, `milestonePrerequisitesMet` | Test-out exemption from phase gating removed (belongs to quizzes). Schedule gate added, see below. |
| `src/Progress/LessonProgress.php` | same service: `defaultLessonProgress`, `lessonProgressPayload`, `calculateCompletionPercent`, `deriveStatus`, `hasStarted`, `normalizeManualCompletionState`; threshold 90 | Threshold moved to config. |
| `src/Support/EventRecorder.php` | `app/Services/Courses/CourseAnalyticsService.php`: `recordProgressUpdate`, `recordManualCompletion`, `recordEvent` | `firstOrCreate` → `createOrFirst` (no race between select and insert). Switchable by config. |
| `src/Support/CourseRepository.php` | `app/Services/Content/StatamicMemberContentService.php`: `getCourseByResourceSlug`, `getCourseLessonsByResourceSlug`, `normalizeLessonItemFields` (generic part), `parseDurationSeconds` | Lesson → course via an `entries` field `course` instead of the text join on `resource_slug`. Prerequisite ids resolved within the course instead of one `Entry::find` per id. |
| `resources/blueprints/course.yaml`, `course_lesson.yaml` | `resources/blueprints/collections/courses/course.yaml`, `course_lessons/course_lesson.yaml` | Generic fields only (list below). Added `product`, `drip_mode`, `course`. |
| `tests/Feature/CourseProgressTest.php` | `tests/Unit/CourseProgressServiceTest.php` (both tests) | Circle import fixture replaced by the same course shape built by hand. |
| `tests/Unit/WeekAndDurationTest.php` | `tests/Unit/Training/PlanProgressServiceTest.php` (week semantics) | Ported as `openWeek()`; `locked` status belongs to access, not here. |

## New, not extracted

- **Drip by schedule.** adg has no time-based unlock. The ticket asks for one. It is built only
  from pieces that exist there: the lesson field `week` and the enrollment's `started_at` /
  `current_week`. Week *n* opens `(n − 1) × 7` days after enrollment, or earlier if moved on by hand.
  Off by default (`drip_mode: none`).
- **`CourseAccess` seam** to statamic-entitlements, fail-closed without it.
- **`LessonCompleted` / `CourseCompleted` events.** Stand in for the direct calls into the
  community service (milestone posts) and analytics.
- **`courses:install`** command.

## Left out, and why

Adrian-specific or belonging to another planned addon:

- `ProgressAggregator.php`: not course progress at all. Streak, weekly goal and heatmap over
  `PracticeSession` (VocalFlow practice). Stays in adg.
- Quiz, test-out, reflection, assignment (`submitQuiz`, `submitReflection`, `submitAssignment`,
  `assignmentFile`, `reviewAssignment`, `skipCompleteEarlierPhases`, `AssignmentUploadService`,
  `sanitizeLessonForClient`): ticket `backlog-statamic-assessments`. `item_payload` is kept so it can
  plug in.
- Exercise item type (`recordExercisePlay`, `library_items` fields): VocalFlow exercise library.
- Coaching item type (`linkCoachingSession`, `completeCoachingSession`, `CoachingLinkConflict`,
  `session_type_id`, `booking_url`): VocalFlow booking, ticket `backlog-statamic-coaching`.
- Community cross-link (`resolveCourseCommunitySpace`, `emitMilestoneActivities`,
  `source_space_slug`): ticket `backlog-statamic-community`; hook is `LessonCompleted`.
- `LearningPathBuilder.php` (phases → weeks → items DTO, German status texts, live events):
  presentation for adg's Vue player. Phase 2 at the earliest, as tags.
- `CourseAnalyticsService` reporting half (`overview`, `courseDetail`, roster): reads
  `member_products`, and is CP UI. Phase 2.
- Media: `video_asset_key` (Hetzner `videos` container), transcript, attachments, media route:
  ticket `backlog-statamic-private-media`.
- Circle import fields (`source_space_id`, `source_lesson_id`, `source_updated_at`,
  `is_incomplete`, `section_index`), `course_kind`, `live_events`, `resource_slug`.
- `PlanProgressService` as its own thing: plans are courses with weeks here.

## Open questions for Adrian

1. **Schedule drip** as built (weekly, from enrollment, manual push forward) — right model, or do
   you want per-lesson "open after N days" or fixed dates?
2. **Subject type** for entitlements: adg grants under which morph type? Default here is `user`;
   must match, or every course stays closed after the switch.
3. **Switching adg over** (phase 3+): lesson → course link changed from `resource_slug` text to an
   `entries` field, and `user_id` / table columns differ. Needs a content and data migration. OK?
4. **Editions**: single edition, proprietary, like products? addon-lint asks.
5. **Settings**: nothing user-facing yet (threshold, event log are config). Move to brand-context
   settings in phase 2, or leave as config?
