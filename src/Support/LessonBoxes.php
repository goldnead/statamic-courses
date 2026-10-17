<?php

namespace Goldnead\Courses\Support;

/**
 * The content boxes of a lesson (statamic-offers), resolved for one learner.
 *
 * Courses only says where a box stands: under the video, at the end of the
 * lesson, or as a hint button over the video between two times. The words, the
 * target and the audience belong to the box and are decided by statamic-offers,
 * which also drops a box that must not be shown (unpublished, offer sold out,
 * learner outside the audience). Without statamic-offers nothing is shown.
 *
 * Boxes set on the course come before those set on the lesson.
 */
class LessonBoxes
{
    public const OFFERS = 'Goldnead\\StatamicOffers\\Offers';

    /**
     * @param  array<string, mixed>  $course  as CourseRepository::normalizeCourse() has it
     * @param  array<string, mixed>  $lesson  as CourseRepository::normalizeLesson() has it
     * @return array{below_video: list<array<string, mixed>>, end: list<array<string, mixed>>, hints: list<array<string, mixed>>}
     */
    public function for(array $course, array $lesson, mixed $learner): array
    {
        $result = ['below_video' => [], 'end' => [], 'hints' => []];

        if (! $this->available()) {
            return $result;
        }

        $viewer = Learner::user($learner) ?? Learner::email($learner);
        $rows = [...($course['boxes'] ?? []), ...($lesson['boxes'] ?? [])];
        $hints = $lesson['video_hints'] ?? [];

        $ids = array_values(array_unique([...array_column($rows, 'box'), ...array_column($hints, 'box')]));

        if ($ids === []) {
            return $result;
        }

        $resolved = [];

        $boxes = call_user_func([self::OFFERS, 'contentBoxes'], $ids, $viewer);

        foreach (is_array($boxes) ? $boxes : [] as $box) {
            if (is_array($box)) {
                $resolved[(string) ($box['id'] ?? '')] = $box;
            }
        }

        foreach ($rows as $row) {
            if (isset($resolved[$row['box']])) {
                $result[$row['place']][] = $resolved[$row['box']];
            }
        }

        foreach ($hints as $hint) {
            $box = $resolved[$hint['box']] ?? null;

            if ($box === null) {
                continue;
            }

            $result['hints'][] = [
                'from' => $hint['from'],
                'to' => $hint['to'],
                'text' => $hint['text'] !== '' ? $hint['text'] : (string) ($box['headline'] ?? ''),
                'label' => (string) ($box['button_label'] ?? ''),
                'url' => (string) ($box['url'] ?? ''),
                'box' => (string) ($box['handle'] ?? ''),
            ];
        }

        return $result;
    }

    protected function available(): bool
    {
        return is_callable([self::OFFERS, 'contentBoxes']);
    }
}
