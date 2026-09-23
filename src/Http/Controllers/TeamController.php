<?php

namespace Goldnead\Courses\Http\Controllers;

use Goldnead\Courses\CourseProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;

/**
 * POST /!/courses/team: a buyer adds or removes a team member by email (K6).
 *
 * Only the buyer: CourseProgress refuses somebody who holds the course
 * through a team, or not at all. Answers JSON when asked, otherwise redirects
 * back (or to a local `_redirect`) with `courses.status` or `courses.error`.
 */
class TeamController extends ProgressController
{
    public function __invoke(Request $request, CourseProgress $courses): JsonResponse|RedirectResponse
    {
        abort_unless(config('courses.routes.enabled', true), 404);

        $user = User::current();

        if ($user === null) {
            return $this->fail($request, 401, 'unauthenticated');
        }

        $data = $request->validate([
            'course' => ['required', 'string', 'max:191'],
            'action' => ['required', 'in:add,remove'],
            'email' => ['required', 'email', 'max:191'],
        ]);

        if ($courses->course($data['course']) === null) {
            return $this->fail($request, 404, 'unknown_course');
        }

        if ($courses->team($user, $data['course']) === null) {
            return $this->fail($request, 403, 'not_owner');
        }

        if ($data['action'] === 'remove') {
            $courses->removeTeamMember($user, $data['course'], $data['email']);
        } elseif ($courses->addTeamMember($user, $data['course'], $data['email']) === null) {
            return $this->fail($request, 422, 'no_seat');
        }

        if ($request->expectsJson()) {
            return response()->json(['team' => $courses->team($user, $data['course'])]);
        }

        return $this->back($request)->with('courses.status', 'saved');
    }
}
