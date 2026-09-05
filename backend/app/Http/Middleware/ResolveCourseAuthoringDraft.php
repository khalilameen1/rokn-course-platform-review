<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Services\CourseStagedAuthoringService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveCourseAuthoringDraft
{
    public function __construct(private readonly CourseStagedAuthoringService $authoring) {}

    public function handle(Request $request, Closure $next)
    {
        $course = $request->route('course');
        if ($course instanceof Course) {
            $routeRevision = CourseAuthoringRevision::query()
                ->where('revision_course_id', $course->id)
                ->latest('id')
                ->first(['status']);
            if ($routeRevision?->status === CourseAuthoringRevision::ARCHIVED
                && !in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
                // A form left open across publish still names the old working
                // course. Do not let its first POST create a fresh draft as a
                // side effect and then apply/adopt old section IDs. A clean GET
                // is the only operation allowed to resolve that URL forward.
                throw ValidationException::withMessages([
                    'authoring_version' => [
                        "نُشرت هذه المسودة بالفعل\nأعد فتح استوديو الكورس قبل الحفظ",
                    ],
                ])->status(409);
            }
            $canonical = $this->authoring->canonicalFor($course);
            $draft = $this->authoring->draftFor($canonical);
            $revision = CourseAuthoringRevision::query()
                ->where('canonical_course_id', $canonical->id)
                ->where('revision_course_id', $draft->id)
                ->where('status', CourseAuthoringRevision::DRAFT)
                ->first();
            if ($revision) {
                foreach ($request->route()->parameters() as $name => $parameter) {
                    if (!$parameter instanceof Model || $parameter instanceof Course) continue;
                    $parameterId = (int) $parameter->getKey();
                    $alreadyInDraft = DB::table('course_authoring_revision_entities')
                        ->where('course_authoring_revision_id', $revision->id)
                        ->where('entity_type', $parameter::class)
                        ->where('revision_entity_id', $parameterId)
                        ->exists();
                    if ($alreadyInDraft) continue;

                    $currentId = $this->authoring->currentEntityId($parameter::class, $parameterId)
                        ?? $parameterId;
                    $mappedId = DB::table('course_authoring_revision_entities')
                        ->where('course_authoring_revision_id', $revision->id)
                        ->where('entity_type', $parameter::class)
                        ->where('source_entity_id', $currentId)
                        ->value('revision_entity_id');
                    if ($mappedId) {
                        $request->route()->setParameter(
                            $name,
                            $parameter->newQuery()->findOrFail($mappedId)
                        );
                        continue;
                    }

                    $isHistorical = DB::table('course_authoring_revision_entities as entities')
                        ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
                        ->where('entities.entity_type', $parameter::class)
                        ->where('revisions.canonical_course_id', $canonical->id)
                        ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
                        ->where(function ($ids) use ($parameterId): void {
                            $ids->where('entities.source_entity_id', $parameterId)
                                ->orWhere('entities.revision_entity_id', $parameterId);
                        })
                        ->exists();
                    if ($isHistorical) {
                        throw ValidationException::withMessages([
                            'authoring_version' => [
                                "هذا التبويب يعرض نسخة قديمة من الكورس\nأعد فتح الاستوديو قبل الحفظ",
                            ],
                        ])->status(409);
                    }
                }
            }
            $request->attributes->set('canonical_course_id', (int) $canonical->id);
            $request->route()->setParameter('course', $draft);
        }

        return $next($request);
    }
}
