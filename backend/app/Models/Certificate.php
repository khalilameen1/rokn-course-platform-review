<?php

namespace App\Models;

use App\Services\CertificateArtworkRenderer;
use App\Services\CertificateTextTemplateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    protected $fillable = [
        'user_id',
        'public_id',
        'course_id',
        'holder_name',
        'course_name',
        'certificate_text_template_key',
        'certificate_text',
        'certificate_design_version',
        'certificate_completion_text',
        'certificate_curriculum_revision',
        'certificate_project_evidence',
        'certificate_qr_snapshot',
        'image_path',
        'generation_lease_id',
        'generated_at',
        'status',
        'verification_level',
        'revoked_at',
        'recovery_attempts',
        'recovery_next_attempt_at',
        'recovery_failed_at',
        'recovery_failure_code',
        'artifact_checked_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'recovery_attempts' => 'integer',
        'recovery_next_attempt_at' => 'datetime',
        'recovery_failed_at' => 'datetime',
        'artifact_checked_at' => 'datetime',
        'certificate_curriculum_revision' => 'integer',
        'certificate_project_evidence' => 'array',
        'certificate_qr_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            if (!$certificate->public_id) {
                $certificate->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (Certificate $certificate): void {
            // Even an empty suffix or an absent legacy snapshot is final.
            // Issuance fills these once on create; recovery cannot add claims.
            foreach ([
                'certificate_design_version',
                'certificate_completion_text',
                'certificate_curriculum_revision',
                'certificate_project_evidence',
                'certificate_qr_snapshot',
            ] as $attribute) {
                if ($certificate->isDirty($attribute)) {
                    $certificate->setAttribute($attribute, $certificate->getOriginal($attribute));
                }
            }
            foreach ([
                'public_id',
                'holder_name',
                'course_name',
                'certificate_text_template_key',
                'certificate_text',
                'generated_at',
                'verification_level',
            ] as $attribute) {
                $original = $certificate->getRawOriginal($attribute);
                if (
                    $certificate->isDirty($attribute)
                    && $original !== null
                    && trim((string) $original) !== ''
                ) {
                    $certificate->setAttribute($attribute, $original);
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    public function hasStoredArtifact(): bool
    {
        $path = trim((string) $this->image_path);
        if ($path === '' || $path === 'pending' || !$this->isActiveCredential()) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk((string) config('certificate.disk', 'public'))->exists($path);
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
    }

    /**
     * Revocation is terminal if either persisted marker says so. Keeping this
     * rule on the model prevents a partially-written revocation from exposing
     * an artifact through one endpoint while another endpoint rejects it.
     */
    public function isRevokedCredential(): bool
    {
        return $this->status === 'revoked' || $this->revoked_at !== null;
    }

    public function isActiveCredential(): bool
    {
        return $this->status === 'active' && !$this->isRevokedCredential();
    }

    public function hasCompleteCredentialSnapshot(): bool
    {
        return Str::isUuid((string) $this->public_id)
            && (int) $this->course_id > 0
            && $this->generated_at instanceof \DateTimeInterface
            && trim((string) $this->holder_name) !== ''
            && trim((string) $this->course_name) !== ''
            && trim((string) $this->certificate_text_template_key) !== ''
            && trim((string) $this->certificate_text) !== ''
            && in_array(
                (string) $this->verification_level,
                ['completion', 'reviewed_project'],
                true
            )
            && $this->hasCompleteDesignSnapshot();
    }

    private function hasCompleteDesignSnapshot(): bool
    {
        if ($this->certificate_design_version === null) {
            return true;
        }
        if (!in_array($this->certificate_design_version, CertificateArtworkRenderer::SUPPORTED_VERSIONS, true)
            || $this->certificate_text !== CertificateTextTemplateService::COMPLETION_PREFIX
            || !in_array($this->certificate_completion_text, [
                '', CertificateTextTemplateService::PROJECTS_COMPLETION,
            ], true)) {
            return false;
        }

        $revision = $this->certificate_curriculum_revision;
        $evidence = $this->certificate_project_evidence;
        if (($revision !== null && (!is_int($revision) || $revision < 1))
            || !is_array($evidence) || !array_is_list($evidence)) {
            return false;
        }
        if ($this->certificate_completion_text === '') {
            if ($evidence !== []) return false;
        } else {
            if ($revision === null || $evidence === []) return false;
            $projectIds = [];
            $submissionIds = [];
            foreach ($evidence as $row) {
                if (!is_array($row)
                    || !is_int($row['project_id'] ?? null) || $row['project_id'] < 1
                    || !is_int($row['submission_id'] ?? null) || $row['submission_id'] < 1
                    || isset($projectIds[$row['project_id']])
                    || isset($submissionIds[$row['submission_id']])) {
                    return false;
                }
                $projectIds[$row['project_id']] = true;
                $submissionIds[$row['submission_id']] = true;
            }
        }

        $qr = $this->certificate_qr_snapshot;
        if (!is_array($qr) || !in_array($qr['type'] ?? null, ['portfolio', 'certificate'], true)) {
            return false;
        }
        foreach (['url', 'title', 'hint'] as $key) {
            if (!is_string($qr[$key] ?? null) || trim($qr[$key]) === '') return false;
        }
        $url = parse_url($qr['url']);
        if (filter_var($qr['url'], FILTER_VALIDATE_URL) === false
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || !isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['port'])
            || isset($url['query']) || isset($url['fragment'])) {
            return false;
        }

        return $qr['type'] === 'certificate'
            ? ($url['path'] ?? null) === '/c/'.rawurlencode((string) $this->public_id)
            : preg_match('/^\/@rokn-(?:[a-z0-9]{24}|[a-f0-9]{32})$/D', (string) ($url['path'] ?? '')) === 1;
    }
}
