# Changelog

## Unreleased (0.3.0)

### Added
- Webhook Manager triggers: with goldnead/statamic-webhook-manager installed, all twelve course
  events are triggers an outbound webhook can listen to (`courses.learner_enrolled` …
  `courses.team_member_removed`, source type `courses`), labelled in German and English. The
  payload is chosen field by field (learner looked up as `{id, email, name}`, course
  `{id, slug, title}`, brand `{id, handle}`, `occurred_at` in ISO 8601), documented in the README.
  A hook fires in the course's brand, also when no brand is current. Optional: nothing of the
  webhook manager is loaded without it, and a boot test in its own process proves that.
- Config `courses.webhook_manager.enabled` (env `COURSES_WEBHOOK_MANAGER`, default `true`).

## 0.2.1 (2026-09-23)

### Fixed
- Sites without statamic-private-media crashed at boot ("Interface
  Goldnead\PrivateMedia\Contracts\MediaAccess not found"). The service provider now checks for the
  interface by name before it touches the class that implements it, and the lesson blocks tag no
  longer loads that class either. A boot test runs in its own process without the test fakes.

## 0.2.0 (2026-09-23)

### Upgrading
- Run `php artisan migrate`: three migrations (billing, hold and hold-source columns on
  `courses_enrollments`, table `courses_team_members`).
- Then `php artisan courses:install --merge` (try `--dry-run` first). It adds the new fields and
  select options to your existing course and lesson blueprints and changes nothing else, so labels
  and instructions you or an earlier version wrote stay as they are.
- If the site caches routes (`php artisan optimize`), rebuild the cache: `POST /!/courses/team`
  and the Control Panel route for lifting a payment hold are new.
- Optional: `downloads.container` (`COURSES_DOWNLOADS_CONTAINER`) names the asset container the
  download block picks files from; without it the site's first container other than the private
  one is used. Set it before running `--merge`, which writes it into the lesson blueprint.
- New permission `manage course holds` for lifting payment holds on the Course Progress screen;
  give it to the roles that should.

### Added
- Lesson blocks: text, callout, columns, FAQ, video, download and button, rendered by
  `{{ courses:blocks }}` with overridable partials. The markdown `content` stays and renders
  first. Private downloads have their own field on statamic-private-media's container and are
  signed for `course:<slug>`; a MediaAccess from courses answers that resource with
  `canAccess()`, so team members and bundle holders get the file. YouTube and Vimeo wait behind
  statamic-consent's gate when it is installed.
- `courses:install --merge` (with `--dry-run`): adds missing fields and select options to
  existing blueprints and changes nothing else. `--dry-run` names every file a real run would
  write.
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
- Access is also looked up under the learner's email address (subject type `email`), where
  statamic-payments grants on a site without a SubjectResolver.
- A hold set by hand is absolute: no grant, team seat, renewal or purchase gets past it; only
  `restoreAccess()` or the Control Panel lifts it.
- `courses:install --dry-run` saves nothing, also on a fresh site ("would create").
- Every event carries `?int $brandId` (last, optional constructor parameter): the course's brand
  (its `brand` field, else the brand of its site), else the brand current when it fires. The
  payments bridge fires in the subscription's brand. `Courses::courses($brandId)` lists one
  brand's courses and those without a brand.
- Events `LearnerEnrolled`, `LessonUnlocked`, `QuizPassed`, `QuizFailed`, `DripPaused`,
  `DripResumed`, `CourseAccessSuspended`, `CourseAccessRestored`, `TeamMemberAdded`,
  `TeamMemberRemoved`.
- Config `downloads.container`.

### Changed
- `courses:install` writes an asset container into the download field.
- A lesson locked by the drip reports its drip reason first, also before the learner enrolled.

### Fixed
- `courses:install --merge` no longer saves existing collections. It used to translate an
  English collection title, and saving rewrote the whole collection YAML, dropping settings the
  site had written out explicitly. Existing collections are now left untouched.
- `courses:install --dry-run` names every file a real run would write.

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
