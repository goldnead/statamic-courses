<?php

namespace Goldnead\Courses\Progress;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Goldnead\Courses\Models\Enrollment;

/**
 * When a lesson opens under the course's drip mode.
 *
 * Pure, like the lock resolver it serves. One answer per lesson:
 * `locked`, `opens_at` (null when no date can be promised) and `reason`
 * (`schedule`, `payment` or `paused`).
 *
 * The modes, and what the lesson fields mean under each:
 *
 * | mode           | lesson field  | opens                                                   |
 * |----------------|---------------|---------------------------------------------------------|
 * | `schedule`     | `week`        | week N after enrollment (7 days per week), or earlier by hand |
 * | `days`         | `drip_after`  | N days after enrollment                                 |
 * | `date`         | `drip_date`   | on that calendar day, display timezone                  |
 * | `day_of_month` | `drip_after`  | the Nth time the course's day of the month comes round, the enrollment day included |
 * | `payments`     | `drip_after`  | once N payments have gone through                       |
 * | `after_trial`  | `drip_after` ≥ 1 | once the first charge after a trial has gone through |
 *
 * A lesson without the field (or with 0) opens at once in every mode.
 *
 * **Paused drip.** While `drip_paused_at` is set the clock stands at that
 * moment: nothing opens that was not open then. After the pause the relative
 * modes count from an enrollment date moved on by `drip_paused_seconds`, so a
 * learner who could not pay for ten days gets their lessons ten days later,
 * not all at once. A fixed date does not move.
 */
class DripSchedule
{
    public const MODES = ['none', 'schedule', 'days', 'date', 'day_of_month', 'payments', 'after_trial'];

    /**
     * @param  array<string, mixed>  $course
     * @param  array<string, mixed>  $lesson
     * @return array{locked: bool, opens_at: CarbonInterface|null, reason: string|null}
     */
    public function gate(array $course, array $lesson, ?Enrollment $enrollment, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $mode = (string) ($course['drip_mode'] ?? 'none');

        if (in_array($mode, ['payments', 'after_trial'], true)) {
            return $this->byPayments($mode, $lesson, $enrollment);
        }

        $opensAt = $this->opensAt($mode, $course, $lesson, $enrollment);

        if ($opensAt === false) {
            return self::open();
        }

        // Relative modes without an enrollment have nothing to count from.
        if ($opensAt === null) {
            return ['locked' => true, 'opens_at' => null, 'reason' => 'schedule'];
        }

        $pausedAt = $enrollment?->drip_paused_at;
        $clock = $pausedAt !== null ? CarbonImmutable::instance($pausedAt) : $now;

        if ($clock->greaterThanOrEqualTo($opensAt)) {
            return self::open();
        }

        if ($pausedAt !== null) {
            // Would be open by now, if the clock were running.
            $running = $now->greaterThanOrEqualTo($opensAt);

            return ['locked' => true, 'opens_at' => null, 'reason' => $running ? 'paused' : 'schedule'];
        }

        return ['locked' => true, 'opens_at' => $opensAt, 'reason' => 'schedule'];
    }

    /**
     * The highest week open under the schedule drip. See LockResolver::openWeek().
     */
    public function openWeek(?Enrollment $enrollment, ?CarbonInterface $now = null): int
    {
        if (! $enrollment instanceof Enrollment || $enrollment->started_at === null) {
            return max(1, (int) ($enrollment->current_week ?? 1));
        }

        $now = CarbonImmutable::instance($now ?? now());
        $clock = $enrollment->drip_paused_at !== null ? CarbonImmutable::instance($enrollment->drip_paused_at) : $now;
        $elapsedDays = (int) floor($this->anchor($enrollment)->diffInDays($clock, false));
        $byCalendar = $elapsedDays < 0 ? 1 : intdiv($elapsedDays, 7) + 1;

        return max(1, $byCalendar, (int) $enrollment->current_week);
    }

    /**
     * False: nothing to wait for. Null: waits, but for an enrollment that
     * does not exist yet.
     *
     * @param  array<string, mixed>  $course
     * @param  array<string, mixed>  $lesson
     */
    protected function opensAt(string $mode, array $course, array $lesson, ?Enrollment $enrollment): CarbonImmutable|false|null
    {
        $after = (int) ($lesson['drip_after'] ?? 0);

        switch ($mode) {
            case 'schedule':
                $week = $lesson['week'] ?? null;

                if ($week === null || $week <= 1) {
                    return false;
                }

                // Moved on by hand past this week: open whatever the calendar says.
                if ($enrollment !== null && (int) $enrollment->current_week >= $week) {
                    return false;
                }

                return $this->started($enrollment)?->addDays(($week - 1) * 7);

            case 'days':
                return $after <= 0 ? false : $this->started($enrollment)?->addDays($after);

            case 'date':
                $date = $lesson['drip_date'] ?? null;

                return is_string($date) && $date !== ''
                    ? CarbonImmutable::parse($date, self::calendarTimezone())->startOfDay()
                    : false;

            case 'day_of_month':
                if ($after <= 0) {
                    return false;
                }

                $start = $this->started($enrollment);

                return $start === null ? null : $this->nthDayOfMonth($start, (int) ($course['drip_day_of_month'] ?? 1), $after);

            default:
                return false;
        }
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @return array{locked: bool, opens_at: CarbonInterface|null, reason: string|null}
     */
    protected function byPayments(string $mode, array $lesson, ?Enrollment $enrollment): array
    {
        $after = (int) ($lesson['drip_after'] ?? 0);

        if ($after <= 0) {
            return self::open();
        }

        $payments = (int) ($enrollment->payments_count ?? 0);

        $open = $mode === 'payments'
            ? $payments >= $after
            // The trial's own payment is the first; the first charge after it
            // is the second. A learner who bought without a trial has none.
            : ($enrollment?->trial_until === null || $payments >= 2);

        return $open ? self::open() : ['locked' => true, 'opens_at' => null, 'reason' => 'payment'];
    }

    /**
     * The enrollment date as the drip counts from it: moved on by every
     * second the drip stood paused.
     */
    protected function started(?Enrollment $enrollment): ?CarbonImmutable
    {
        if ($enrollment === null || $enrollment->started_at === null) {
            return null;
        }

        return $this->anchor($enrollment);
    }

    protected function anchor(Enrollment $enrollment): CarbonImmutable
    {
        return CarbonImmutable::instance($enrollment->started_at)->addSeconds((int) $enrollment->drip_paused_seconds);
    }

    /**
     * The Nth occurrence of `$day` on or after `$start`'s calendar day, at
     * midnight in the display timezone. A day beyond a month's end falls on its
     * last day (31 in February is the 28th or 29th).
     */
    protected function nthDayOfMonth(CarbonImmutable $start, int $day, int $n): CarbonImmutable
    {
        $day = max(1, min(31, $day));
        $local = $start->setTimezone(self::calendarTimezone())->startOfDay();
        $month = $local->startOfMonth();

        $candidate = $month->setDay(min($day, $month->daysInMonth));

        if ($candidate->lessThan($local)) {
            $month = $month->addMonthNoOverflow();
            $candidate = $month->setDay(min($day, $month->daysInMonth));
        }

        $target = $candidate->startOfMonth()->addMonthsNoOverflow($n - 1);

        return $target->setDay(min($day, $target->daysInMonth));
    }

    /**
     * The timezone a calendar day is counted in: Statamic's display timezone,
     * the one the Control Panel shows dates in. app.timezone stays UTC on a
     * live site and must not be turned to get local days.
     */
    public static function calendarTimezone(): string
    {
        $display = config('statamic.system.display_timezone');

        return is_string($display) && $display !== '' ? $display : (string) config('app.timezone', 'UTC');
    }

    /**
     * @return array{locked: false, opens_at: null, reason: null}
     */
    protected static function open(): array
    {
        return ['locked' => false, 'opens_at' => null, 'reason' => null];
    }
}
