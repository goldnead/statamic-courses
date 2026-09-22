<?php

namespace Goldnead\Courses\Enums;

enum LessonStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
