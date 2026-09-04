<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class AdminProjectSubmissionReviewReadService
{
    public function __construct(private ProjectSubmissionPresenter $presenter)
    {
    }

    /**
     * @param array{status?:string|null,search?:string|null} $filters
     * @return array{submissions:LengthAwarePaginator,statusCounts:Collection}
     */
    public function index(array $filters, bool $administrator): array
    {
        $query = ProjectSubmission::query()
            ->with([
                'project.section.course',
                'reviewer:id,name_ar,name_en',
                'user' => fn ($userQuery) => $administrator
                    ? $userQuery->select(['id', 'name_ar', 'name_en', 'email', 'phone'])
                    : $userQuery->select(['id']),
            ])
            ->orderByRaw("CASE review_status WHEN 'pending' THEN 0 WHEN 'needs_resubmission' THEN 1 ELSE 2 END")
            ->latest('submitted_at')
            ->latest('id');

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('review_status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $submissionQuery) use ($search, $administrator): void {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $submissionQuery
                    ->where('public_id', 'like', $like)
                    ->orWhereHas('project.section', function (Builder $sectionQuery) use ($like): void {
                        $sectionQuery->where(function (Builder $titles) use ($like): void {
                            $titles->where('title', 'like', $like)
                                ->orWhere('title_ar', 'like', $like)
                                ->orWhere('title_en', 'like', $like);
                        });
                    })
                    ->orWhereHas('project.section.course', function (Builder $courseQuery) use ($like): void {
                        $courseQuery->where(function (Builder $names) use ($like): void {
                            $names->where('name_ar', 'like', $like)
                                ->orWhere('name_en', 'like', $like);
                        });
                    });

                if ($administrator) {
                    $submissionQuery->orWhereHas('user', function (Builder $userQuery) use ($like): void {
                        $userQuery->where(function (Builder $identity) use ($like): void {
                            $identity->where('name_ar', 'like', $like)
                                ->orWhere('name_en', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like);
                        });
                    });
                }
            });
        }

        return [
            'submissions' => $query->paginate(25)->withQueryString(),
            'statusCounts' => ProjectSubmission::query()
                ->selectRaw('review_status, COUNT(*) as aggregate')
                ->groupBy('review_status')
                ->pluck('aggregate', 'review_status'),
        ];
    }

    /**
     * @return array{
     *   submission:ProjectSubmission,
     *   submissionState:array<string,mixed>,
     *   threadMessages:Collection,
     *   isLatestAttempt:bool
     * }
     */
    public function show(ProjectSubmission $submission, bool $administrator): array
    {
        $submission->unsetRelation('user');
        $submission->load([
            'user' => fn ($userQuery) => $administrator
                ? $userQuery->select(['id', 'name_ar', 'name_en', 'email', 'phone'])
                : $userQuery->select(['id']),
            'project.section.course',
            'reviewer:id,name_ar,name_en',
            'aiInputAttachments',
            'feedbackThread.enrollment',
        ]);

        $threadMessages = collect();
        if ($submission->feedbackThread) {
            $threadMessages = ProjectFeedbackMessage::query()
                ->where('thread_id', $submission->feedbackThread->id)
                ->latest('id')
                ->limit(60)
                ->get()
                ->reverse()
                ->values();
            $initial = ProjectFeedbackMessage::query()
                ->where('thread_id', $submission->feedbackThread->id)
                ->where('client_request_id', 'report:' . $submission->public_id)
                ->first();
            if ($initial && !$threadMessages->contains('id', $initial->id)) {
                $threadMessages->prepend($initial);
            }

            if ($administrator) {
                $threadMessages->load('usageEvent');
            }

            $attachments = AiInputAttachment::query()
                ->where('owner_type', AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE)
                ->whereIn('owner_id', $threadMessages->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('owner_id');
            $threadMessages->each(function (ProjectFeedbackMessage $message) use ($attachments): void {
                $message->setRelation('inputAttachments', $attachments->get($message->id, collect()));
            });
        }

        return [
            'submission' => $submission,
            'submissionState' => $this->presenter->present($submission, false),
            'threadMessages' => $threadMessages,
            'isLatestAttempt' => !ProjectSubmission::query()
                ->where('user_id', $submission->user_id)
                ->where('project_id', $submission->project_id)
                ->where('id', '>', $submission->id)
                ->exists(),
        ];
    }
}
