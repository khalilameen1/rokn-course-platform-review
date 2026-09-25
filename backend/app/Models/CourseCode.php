<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseCode extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (CourseCode $code): void {
            $hasClaims = (int) $code->getOriginal('used_count') > 0
                || $code->usages()->exists();
            if ($hasClaims && $code->isDirty([
                'type',
                'course_id',
                'lesson_id',
                'lesson_ids',
                'is_grant',
                'allowed_email_domains',
                'start_date',
            ])) {
                throw new \DomainException(
                    'بدأ استخدام هذا الكود. أوقفه وأنشئ كودًا جديدًا لتغيير عقد الإتاحة.'
                );
            }
            if (
                $code->isDirty('max_uses')
                && (int) $code->max_uses < (int) $code->used_count
            ) {
                throw new \DomainException('الحد الأقصى لا يمكن أن يقل عن الاستخدام الفعلي.');
            }
        });
    }

    protected $fillable = [
        'code',
        'name',
        'type',
        'course_id',
        'lesson_ids',
        'lesson_id',
        'start_date',
        'expiry_date',
        'max_uses',
        'used_count',
        'is_active',
        'is_grant',
        'description',
        'allowed_email_domains',
    ];

    protected $casts = [
        'lesson_ids' => 'array',
        'start_date' => 'datetime',
        'expiry_date' => 'datetime',
        'is_active' => 'boolean',
        'is_grant' => 'boolean',
        'allowed_email_domains' => 'array',
    ];

    /**
     * Generate a unique code
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Check if code is valid (not expired, not exceeded max uses, is active)
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->used_count >= $this->max_uses) {
            return false;
        }

        $now = now();

        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->expiry_date && $now->gt($this->expiry_date)) {
            return false;
        }

        return true;
    }

    /**
     * College/institution codes are full learning grants. They intentionally
     * exclude variable-cost services (Rokn AI and certificate rendering) until
     * the learner upgrades this one course to a paid support plan.
     */
    public function isInstitutionalGrant(): bool
    {
        return (bool) $this->is_grant || collect($this->allowed_email_domains ?? [])
            ->map(fn ($domain) => trim((string) $domain))
            ->filter()
            ->isNotEmpty();
    }

    /**
     * Resolve the target for current codes and read-only historical lesson codes
     *
     * @return int|null The course ID for enrollment, or null if no course is associated
     */
    public function targetCourseId(): ?int
    {
        switch ($this->type) {
            case 'course':
                return $this->course_id;

            case 'multiple_lessons':
                return $this->course_id;

            case 'lesson':
                if ($this->lesson && $this->lesson->course) {
                    return $this->lesson->course->id;
                }
                \Log::warning('Lesson type but no lesson or course found', [
                    'lesson_id' => $this->lesson_id,
                    'has_lesson' => $this->lesson ? 'yes' : 'no',
                    'has_course' => $this->lesson && $this->lesson->course ? 'yes' : 'no'
                ]);
                return null;

            default:
                \Log::warning('Unknown code type', ['type' => $this->type]);
                return null;
        }
    }

    /**
     * Get the course associated with this code
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the lesson associated with this code
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Get all usages of this code
     */
    public function usages()
    {
        return $this->hasMany(CourseCodeUsage::class);
    }

    /**
     * Orders that preserve this code as part of their financial provenance.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get users who have used this code
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'course_code_usages', 'course_code_id', 'user_id')
                    ->withPivot('used_at', 'ip_address')
                    ->withTimestamps();
    }

    /**
     * Scope for active codes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for valid codes (not expired, not exceeded max uses)
     */
    public function scopeValid($query)
    {
        return $query->where('is_active', true)
                    ->where('used_count', '<', DB::raw('max_uses'))
                    ->where(function($q) {
                        $q->whereNull('start_date')
                          ->orWhere('start_date', '<=', now());
                    })
                    ->where(function($q) {
                        $q->whereNull('expiry_date')
                          ->orWhere('expiry_date', '>=', now());
                    });
    }

    /**
     * Get the target content name
     */
    public function getTargetContentNameAttribute()
    {
        switch ($this->type) {
            case 'course':
                return $this->course ? $this->course->name_ar : 'غير محدد';
            case 'lesson':
                return $this->lesson ? $this->lesson->title : 'غير محدد';
            case 'multiple_lessons':
                $lessons = Lesson::query()->whereIn('id', (array) $this->lesson_ids)->get();
                $lessonNames = $lessons->pluck('title')->toArray();
                return implode(', ', $lessonNames) ?: 'غير محدد';
            default:
                return 'غير محدد';
        }
    }

    /**
     * Get remaining uses
     */
    public function getRemainingUsesAttribute()
    {
        return max(0, $this->max_uses - $this->used_count);
    }

    /**
     * Check if code is expired
     */
    public function getIsExpiredAttribute()
    {
        return $this->expiry_date && now()->gt($this->expiry_date);
    }

    /**
     * Check if code is not yet active
     */
    public function getIsNotYetActiveAttribute()
    {
        return $this->start_date && now()->lt($this->start_date);
    }

}

