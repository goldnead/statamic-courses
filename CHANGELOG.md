# Changelog

## Unreleased

## 0.8.2 — 2026-10-28

### Fixed

- A bare file path in a lesson block (a block stores no container handle) is now looked up only in the allowed
  containers: `courses.downloads.containers` (env `COURSES_DOWNLOADS_CONTAINERS`, comma-separated) plus
  `courses.downloads.container`. Before, every asset container was searched, so a lesson could resolve a file
  from a container that was never meant for courses. `coaching_recordings` is never searched, whatever the
  config says. With neither setting present every container is searched as before, except `coaching_recordings`.
## 0.8.1 — 2026-10-21

### Added
- Funnels map dry run of a course (statamic-brand-context 1.24): the course is unlocked, and each lesson shows when it
  opens for a learner, by the course's drip schedule (days, schedule, date, day of month). Lessons after a payment or
  with no known date are marked unknown. Read only: nothing is enrolled, opened, written or sent. Forty lessons at most,
  the rest counted in one line.

## 0.8.0 — 2026-10-21

### Added
- Funnels map detail panel: a course card now opens with the number of lessons and the lessons themselves (title,
  section as the small line), twelve at most, the rest counted. Read only, one lesson query. Needs
  statamic-brand-context 1.19 for the panel; older versions simply do not ask.
- Funnels map detail panel: shown only to users who may view the courses collection's entries
  (`detailsAuthorized`, closed without a user). Needs statamic-brand-context 1.20; on 1.19 the panel stays empty.
  Lessons in draft are neither listed nor counted.

## 0.7.1 — 2026-10-19

### Fixed
- Funnels map: a course without a `product` field no longer draws an edge from a product that does not exist. The
  slug fallback (0.7.0) only draws the edge when the map knows a product with that handle; otherwise the map showed a
  red "broken reference" card for a course that is opened some other way (a lead magnet's access, say). A product
  named in the field stays an edge even when it is missing, because that one is a real broken reference. Without
  the products addon on the map, the fallback draws nothing.

### Upgrading
- No migration.

## 0.7.0 — 2026-10-19

### Added
- Funnels map: with statamic-brand-context 1.18 or later, every course draws an edge from the product that opens it
  (`product` -> `unlocks` -> course). The product is the course's `product` field, or the course slug when the field
  is empty. A course without a product slug has no edge. The edge carries no number.

### Upgrading
- No migration. The edges need statamic-brand-context 1.18 or later.

## 0.6.0 — 2026-10-18

### Added
- Funnels map: with statamic-brand-context 1.17 or later, every course is an object the map can point at
  (`type` `course`, id = the course slug). The map shows the title, a link to the entry in the Control Panel, whether
  it is published, and one number: learners who started the course in the period (enrollments created in it). A
  course that does not exist has no number. Without brand-context nothing is registered.

## 0.5.1 (2026-10-18)

### Fixed
- Video hints: "From" and "To" are validated in the lesson form (`mm:ss`, `h:mm:ss` or seconds; "From" is required).
  A time the parser could not read made the hint button silently never appear. Sites that copied the blueprint add
  the same rules by hand (`--merge` does not change an existing field).

## 0.5.0 (2026-10-18)

### Added
- Content boxes on lessons (needs statamic-offers 1.17 or later; without it nothing is shown). The lesson and the
  course get a "Boxes" section: `lesson_boxes` / `course_boxes` (which box stands under the video or at the end of the
  lesson) and, on the lesson, `video_hints` (a hint button over the video between two times, `mm:ss`, with an
  optional short text). The words, the link and the audience live in the box.
- `Courses::lessonBoxes($user, $courseSlug, $lessonSlug)` returns `below_video`, `end` and `hints` for one learner,
  asking `Offers::contentBoxes()` with the learner as viewer, so the box's audience (LeadHub tags, groups) decides.
  Boxes of the course come before those of the lesson. A hint carries `from`, `to` (null: until the end), `text`,
  `label`, `url`.

### Upgrading
- No migration. Run `php artisan courses:install --merge` to add the fields to the lesson and course blueprints
  (see first with `--dry-run`), and `php artisan offers:install-boxes` (statamic-offers) for the box collection.

## 0.4.0 (2026-10-04)

### Upgrading
- No migration, no new permission. Run `php artisan courses:install --merge` to add the fields
  `kind`, `body` and `downloads` to the course blueprint (see first with `--dry-run`).
- Existing courses change nothing: a course without `kind` is a course.
- `--merge` adds `unless: kind: equals material` (or `kind: not material` to an existing `if`) to
  product, bundles, sequencing, drip, payment failure, team seats and section rules. It renames
  nothing: collection and section titles a site already has stay as they are.

### Added
- Material: a course entry of kind `material` is a page with text (Bard, images from the public
  container) and downloads, opened like a course through entitlements, without progress,
  sequencing or drip. Lessons of a material are further pages, never locked.
- Material downloads (file, label, group, format) come only from statamic-private-media's
  container and are signed for `course:<slug>`, which courses already answers with
  `Courses::canAccess()`. Without private-media the download list is not installed.
- `Courses::material($user, $slug)` and `{{ courses:material }}`: `body`, `downloads`,
  `download_groups` (in the order a group first appears), `pages`. Links are signed only for
  somebody with access.
- `{{ courses kind="material" }}` / `kind="course"`, and `kind` / `is_material` on every course.
- Images in a material's text are a Bard set "Image" (file from private-media's container, alt
  text, caption) with a preview in the Control Panel, rendered as a signed figure for somebody
  with access and left out, caption included, for everybody else. Without private-media the set
  picks from the public container and says so. Image nodes from Bard's image button are still
  read and protected.
- `brand`: a choice of the brands by name on a multi-brand site; not installed on a single-brand
  site, where `--merge` hides an older brand text field.
- Downloads are entered as groups (a voicing, a part) with their files underneath; the format is
  taken from the file. Rows saved one file per row are still read.
- `cover` (public image for a card, `cover_url` on every course).
- Lesson block `image`: from private-media's container, signed for the course, or left out.
- `Courses::kind($slugOrEntryId)` for other addons that list course entries.
- The kind is a column in the course entries listing.
- `courses:install --merge` gives existing fields the Material visibility condition and places a
  new section right behind the one holding the field before it (Material behind the main section).

### Changed
- For a material: `enroll()`, `recordBilling()`, `pauseDrip()`, `resumeDrip()`, `advanceToWeek()`,
  `summary()` and `outline()` answer null, every lesson write is refused (reason `material`, also
  on `POST /!/courses/progress`), and the Course Progress screen leaves it out. A hold
  (`suspendAccess()`, `on_payment_failure: revoke`) still closes it; `pause_drip` reads as `keep`.

## 0.3.0 (2026-09-24)

### Upgrading
- No migration, no new permission.
- With statamic-webhook-manager 2.10 the twelve course events appear there as triggers. Nothing to
  do if you want that. To switch it off, set `courses.webhook_manager.enabled` to `false` (env
  `COURSES_WEBHOOK_MANAGER`). Without the webhook manager nothing changes.

### Added
- Webhook Manager triggers: with goldnead/statamic-webhook-manager installed, all twelve course
  events are triggers an outbound webhook can listen to (`courses.learner_enrolled` …
  `courses.team_member_removed`, source type `courses`), labelled in German and English. The
  payload is chosen field by field (learner looked up as `{id, email, name}`, course
  `{id, slug, title}`, brand `{id, handle}`, `occurred_at` in ISO 8601), documented in the README.
  A hook fires in the course's brand, also when no brand is current. Optional: nothing of the
  webhook manager is loaded without it, and a boot test in its own process proves that.
- Every webhook payload carries a stable `event_id` (`sha1(handle|<type>:<id>|<row time>)`, as in every suite addon), and
  `occurred_at` is the time of the moment. Sent after the transaction commits, never after a
  rollback; a course whose brand does not exist sends nothing instead of the current brand's hooks.
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
