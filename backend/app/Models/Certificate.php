<?php

namespace App\Models;

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
    ];

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            if (!$certificate->public_id) {
                $certificate->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (Certificate $certificate): void {
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
            );
    }

}
