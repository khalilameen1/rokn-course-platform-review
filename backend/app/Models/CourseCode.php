<?php

namespace App\Models;

use App\Support\PrivacyFingerprint;
use App\Services\StudentNotificationService;
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
     * Check if code can be used by a specific user
     */
    public function canBeUsedByUser(int $userId): bool
    {
        if (
            $this->type !== 'course'
            ||
            !$this->isValid()
            || !$this->isEligibleForUser($userId)
            || $this->hasReachedInstitutionalGrantLimit($userId)
            || !$this->targetsPublishedCourse()
        ) {
            return false;
        }

        // Check if user has already used this code
        return !$this->usages()->where('user_id', $userId)->exists();
    }

    public function isEligibleForUser(int $userId): bool
    {
        $domains = collect($this->allowed_email_domains ?? [])
            ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
            ->filter()
            ->values();
        if ($domains->isEmpty()) {
            return true;
        }

        $user = User::query()->select(['email', 'email_verified_at'])->find($userId);
        if (!$user || !$user->email_verified_at) {
            return false;
        }

        $email = mb_strtolower((string) $user->email);
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';
        return $domain !== '' && $domains->contains($domain);
    }

    /**
     * Institution-restricted codes are grants, not general promo codes. A
     * verified learner may consume one such course grant in total so a grant
     * campaign does not erase their ability to become a paying learner later.
     */
    public function hasReachedInstitutionalGrantLimit(int $userId): bool
    {
        if (!$this->isInstitutionalGrant()) {
            return false;
        }

        $user = User::query()->select(['id', 'email'])->find($userId);
        if (!$user) {
            return true;
        }

        $normalizedEmail = mb_strtolower(trim((string) $user->email));

        return CourseGrantClaim::query()
            ->where(function ($query) use ($userId, $normalizedEmail): void {
                $query->where('user_id', $userId);
                if ($normalizedEmail !== '') {
                    $query->orWhere(
                        'normalized_email_hash',
                        CourseGrantClaim::emailHash($normalizedEmail)
                    );
                }
            })
            ->exists();
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
     * Use the code for a specific user
     */
    public function useForUser(int $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            // One lock per learner serializes redemption of different grant
            // codes as well as repeated taps on the same code.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            /** @var self|null $lockedCode */
            $lockedCode = self::query()->lockForUpdate()->find($this->getKey());
            if (!$lockedCode) {
                return false;
            }

            $courseId = $lockedCode->getCourseIdForEnrollment();
            $course = $courseId
                ? Course::query()->lockForUpdate()->find($courseId)
                : null;
            if (!$course || !$course->isPublishedForLearning()) {
                return false;
            }

            // A response can be lost after the durable usage row commits. A
            // repeated tap must finish any legacy/incomplete order, bill or
            // enrollment instead of reporting failure for an entitlement the
            // learner already consumed. The user + code locks make this the
            // canonical recovery path as well as the normal redeem path.
            $existingUsage = $lockedCode->usages()
                ->where('user_id', $userId)
                ->orderBy('id')
                ->first();
            if ($existingUsage) {
                $ledgerCount = $lockedCode->usages()->count();
                if ((int) $lockedCode->used_count < $ledgerCount) {
                    $lockedCode->forceFill(['used_count' => $ledgerCount])->save();
                }
                if (!$lockedCode->enrollUserInCourse($userId)) {
                    throw new \RuntimeException('Course-code redemption recovery could not be completed.');
                }

                $this->setRawAttributes($lockedCode->fresh()->getAttributes(), true);
                return true;
            }

            if (!$lockedCode->canBeUsedByUser($userId)) {
                return false;
            }

            $usage = $lockedCode->usages()->create([
                'user_id' => $userId,
                'used_at' => now(),
                'ip_address' => PrivacyFingerprint::make(request()->ip()),
                'user_agent' => PrivacyFingerprint::make(request()->userAgent()),
            ]);

            if ($lockedCode->isInstitutionalGrant()) {
                $user = User::query()->findOrFail($userId);
                CourseGrantClaim::query()->create([
                    'user_id' => $userId,
                    'normalized_email_hash' => CourseGrantClaim::emailHash($user->email),
                    'email_hint' => CourseGrantClaim::emailHint($user->email),
                    'course_code_id' => $lockedCode->id,
                    'course_code_usage_id' => $usage->id,
                    'course_id' => $lockedCode->getCourseIdForEnrollment(),
                    'status' => CourseGrantClaim::STATUS_ACTIVE,
                    'claimed_at' => now(),
                ]);
            }
            $lockedCode->increment('used_count');

            if (!$lockedCode->enrollUserInCourse($userId)) {
                throw new \RuntimeException('Course-code enrollment could not be completed.');
            }

            $this->setRawAttributes($lockedCode->fresh()->getAttributes(), true);
            return true;
        }, 3);
    }

    /**
     * Enroll user in course based on code type
     *
     * This method automatically enrolls a user in the appropriate course
     * based on the code type:
     * - 'course': Enrolls in the course directly
     * - 'multiple_lessons': Enrolls in the course containing the lessons
     * - 'lesson': Enrolls in the course that contains the lesson
     *
     * @param int $userId The user ID to enroll
     * @return bool True if enrollment was successful or user already enrolled
     */
    public function enrollUserInCourse(int $userId): bool
    {
        $courseId = $this->getCourseIdForEnrollment();

        if (!$courseId) {
            \Log::warning('No course ID found for enrollment', [
                'code_id' => $this->id,
                'code_type' => $this->type
            ]);
            return false;
        }

        // Check if user is already enrolled
        $existingEnrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if ($existingEnrollment) {
            $order = $this->createOrderForCodeRedemption($userId, $courseId);
            if (!$order) {
                return false;
            }
            if ((int) $existingEnrollment->order_id === (int) $order->id
                && $existingEnrollment->isActive()) {
                return true;
            }

            // A code redemption is a new zero-cost entitlement. Re-source even
            // an apparently active row when its prior payment is held or
            // reversed; otherwise the learner would consume a valid code and
            // remain locked behind the stale financial source.
            $existingEnrollment->forceFill([
                'order_id' => $order->id,
                'access_plan_order_id' => null,
                'access_plan_id' => null,
                'access_plan_snapshot' => null,
                'is_active' => true,
                'access_granted_at' => now(),
                'expires_at' => null,
            ])->save();
            return true;
        }

        // Create order and billing for course code redemption
        $order = $this->createOrderForCodeRedemption($userId, $courseId);

        if (!$order) {
            \Log::error('Failed to create order for course code redemption', [
                'code_id' => $this->id,
                'user_id' => $userId,
                'course_id' => $courseId
            ]);
            return false;
        }

        try {
            // Create new enrollment with order_id
            $enrollment = CourseEnrollment::create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'order_id' => $order->id,
                'enrolled_at' => now(),
                'is_active' => true,
                'access_granted_at' => now(),
            ]);

            $user = \App\Models\User::find($userId);
            $course = \App\Models\Course::find($courseId);
            if ($user && $course) {
                $grant = $this->isInstitutionalGrant();
                StudentNotificationService::notifyUser(
                    $user,
                    $grant
                        ? StudentNotificationService::TYPE_INSTITUTIONAL_GRANT
                        : StudentNotificationService::TYPE_COURSE_ENROLLED,
                    $grant ? 'تم تفعيل منحتك' : 'الكورس أصبح لك',
                    $grant ? 'Your grant is active' : 'Course access active',
                    $grant
                        ? "الكورس ومشروعاته متاحة لك\nابدأ عندما يناسبك"
                        : $course->name_ar . "\nابدأ أو أكمل من مكانك",
                    $grant
                        ? 'The complete course and projects are ready whenever you are.'
                        : 'You can start ' . $course->name_en . ' and resume at any time.',
                    '/course/' . $course->id,
                    'App\Models\Course',
                    $course->id,
                    'course-enrolled:order:' . $order->id,
                    ['course' => (string) ($course->name_ar ?: $course->name_en)]
                );
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to create course enrollment', [
                'exception' => $e::class,
                'error_fingerprint' => hash('sha256', $e->getMessage()),
                'user_id' => $userId,
                'course_id' => $courseId,
                'order_id' => $order->id
            ]);
            return false;
        }
    }

    /**
     * Create order for course code redemption
     *
     * @param int $userId The user ID
     * @param int $courseId The course ID
     * @return Order|null The created order or null if failed
     */
    private function createOrderForCodeRedemption(int $userId, int $courseId): ?Order
    {
        // Check if order already exists for this code redemption
        $existingOrder = $this->getOrderForUser($userId);
        if ($existingOrder) {
            if (
                (int) $existingOrder->course_id !== $courseId
                || $existingOrder->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
                || $existingOrder->status !== Order::STATUS_APPROVED
                || !in_array($existingOrder->financial_status, [
                    null,
                    '',
                    Order::FINANCIAL_PENDING,
                    Order::FINANCIAL_SETTLED,
                ], true)
                || (float) $existingOrder->final_amount !== 0.0
            ) {
                \Log::error('Existing course-code order does not match its redemption contract', [
                    'order_id' => $existingOrder->id,
                    'code_id' => $this->id,
                    'user_id' => $userId,
                    'course_id' => $courseId,
                ]);
                return null;
            }

            $existingOrder->forceFill([
                'financial_status' => Order::FINANCIAL_SETTLED,
                'approved_at' => $existingOrder->approved_at ?: now(),
            ])->save();

            // Repair rows produced by the legacy non-atomic flow. The unique
            // order_id on bills makes this safe under retries.
            $bill = Bill::withTrashed()->firstOrNew([
                'order_id' => $existingOrder->id,
            ]);
            $bill->forceFill([
                'user_id' => $userId,
                'course_id' => $courseId,
                'bill_number' => $bill->bill_number
                    ?: Bill::numberForOrder((int) $existingOrder->id),
                'amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'payment_status' => Bill::PAYMENT_STATUS_PAID,
                'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                'paid_at' => $bill->paid_at ?: now(),
                'notes' => 'Course code grant #' . $this->id,
                'deleted_at' => null,
            ])->save();

            return $existingOrder;
        }

        try {
            // Create order
            $order = Order::create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'course_code_id' => $this->id,
                'coupon_id' => null,
                'coupon_code' => null,
                'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                'amount' => 0, // Course code redemption is free
                'discount_amount' => 0,
                'final_amount' => 0,
                'status' => Order::STATUS_APPROVED,
                'financial_status' => Order::FINANCIAL_SETTLED,
                'notes' => 'Course code grant #' . $this->id,
                'approved_at' => now(),
            ]);

            // Create billing
            Bill::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'course_id' => $courseId,
                'bill_number' => Bill::numberForOrder((int) $order->id),
                'amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'payment_status' => Bill::PAYMENT_STATUS_PAID,
                'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                'paid_at' => now(),
                'notes' => 'Course code grant #' . $this->id,
            ]);

            return $order;
        } catch (\Exception $e) {
            \Log::error('Failed to create order for course code redemption', [
                'exception' => $e::class,
                'error_fingerprint' => hash('sha256', $e->getMessage()),
                'user_id' => $userId,
                'course_id' => $courseId
            ]);
            return null;
        }
    }

    /**
     * Get the course ID for enrollment based on code type
     *
     * @return int|null The course ID for enrollment, or null if no course is associated
     */
    public function getCourseIdForEnrollment(): ?int
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

    public function targetsPublishedCourse(): bool
    {
        $courseId = $this->getCourseIdForEnrollment();
        if (!$courseId) {
            return false;
        }

        $course = Course::query()->find($courseId);

        // An administrator retiring a commercial course unlists it instead of
        // deleting the entitlement behind existing learners. New wallet
        // purchases already respect catalogue visibility; access codes and
        // grants must honor the same retirement boundary or they would keep
        // creating enrollments after the dashboard says sales are stopped.
        return $course !== null
            && (bool) $course->is_catalog_visible
            && $course->isPublishedForLearning();
    }

    /**
     * Check if user is enrolled in the course associated with this code
     *
     * @param int $userId The user ID to check
     * @return bool True if user is enrolled in the associated course
     */
    public function isUserEnrolled(int $userId): bool
    {
        $courseId = $this->getCourseIdForEnrollment();

        if (!$courseId) {
            return false;
        }

        return CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Get enrollment status for a user
     *
     * @param int $userId The user ID to check
     * @return array Array containing enrollment status and message
     */
    public function getEnrollmentStatus(int $userId): array
    {
        $courseId = $this->getCourseIdForEnrollment();

        if (!$courseId) {
            return [
                'enrolled' => false,
                'message' => 'لا يوجد دورة مرتبطة بهذا الكود'
            ];
        }

        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if ($enrollment) {
            $orderInfo = null;
            $billingInfo = null;

            if ($enrollment->order_id) {
                $order = Order::with('bill')->find($enrollment->order_id);
                if ($order) {
                    $orderInfo = [
                        'id' => $order->id,
                        'status' => $order->status,
                        'payment_method' => $order->payment_method,
                        'amount' => $order->amount,
                        'final_amount' => $order->final_amount,
                        'approved_at' => $order->approved_at ? $order->approved_at->format('Y-m-d H:i:s') : null
                    ];

                    if ($order->bill) {
                        $billingInfo = [
                            'bill_number' => $order->bill->bill_number,
                            'payment_status' => $order->bill->payment_status,
                            'total_amount' => $order->bill->total_amount,
                            'paid_at' => $order->bill->paid_at ? $order->bill->paid_at->format('Y-m-d H:i:s') : null
                        ];
                    }
                }
            }

            return [
                'enrolled' => true,
                'message' => 'أنت مسجل بالفعل في هذه الدورة',
                'enrollment' => $enrollment,
                'order' => $orderInfo,
                'billing' => $billingInfo
            ];
        }

        return [
            'enrolled' => false,
            'message' => 'لم يتم التسجيل في الدورة بعد'
        ];
    }

    /**
     * Get order and billing information for a user
     *
     * @param int $userId The user ID
     * @return array Array containing order and billing information
     */
    public function getOrderAndBillingInfo(int $userId): array
    {
        $courseId = $this->getCourseIdForEnrollment();

        if (!$courseId) {
            return [
                'order' => null,
                'billing' => null
            ];
        }

        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment || !$enrollment->order_id) {
            return [
                'order' => null,
                'billing' => null
            ];
        }

        $order = Order::with('bill')->find($enrollment->order_id);

        if (!$order) {
            return [
                'order' => null,
                'billing' => null
            ];
        }

        $orderInfo = [
            'id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'amount' => $order->amount,
            'final_amount' => $order->final_amount,
            'approved_at' => $order->approved_at ? $order->approved_at->format('Y-m-d H:i:s') : null
        ];

        $billingInfo = null;
        if ($order->bill) {
            $billingInfo = [
                'bill_number' => $order->bill->bill_number,
                'payment_status' => $order->bill->payment_status,
                'total_amount' => $order->bill->total_amount,
                'paid_at' => $order->bill->paid_at ? $order->bill->paid_at->format('Y-m-d H:i:s') : null
            ];
        }

        return [
            'order' => $orderInfo,
            'billing' => $billingInfo
        ];
    }

    /**
     * Get comprehensive information about code redemption for a user
     *
     * @param int $userId The user ID
     * @return array Array containing all redemption information
     */
    public function getRedemptionInfo(int $userId): array
    {
        $enrollmentStatus = $this->getEnrollmentStatus($userId);
        $orderAndBilling = $this->getOrderAndBillingInfo($userId);

        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'target_content_name' => $this->target_content_name,
            'enrollment_status' => $enrollmentStatus,
            'order' => $orderAndBilling['order'],
            'billing' => $orderAndBilling['billing'],
            'usage_info' => [
                'used_count' => $this->used_count,
                'max_uses' => $this->max_uses,
                'remaining_uses' => $this->remaining_uses,
                'is_valid' => $this->isValid(),
                'can_use' => $this->canBeUsedByUser($userId)
            ]
        ];
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
     * Get multiple lessons if this code is for multiple lessons
     */
    public function lessons()
    {
        if ($this->lesson_ids && is_array($this->lesson_ids)) {
            return Lesson::whereIn('id', $this->lesson_ids)->get();
        }
        return collect();
    }

    /**
     * Get multiple lessons as a collection (for direct access)
     */
    public function getLessonsCollection()
    {
        if ($this->lesson_ids && is_array($this->lesson_ids)) {
            return Lesson::whereIn('id', $this->lesson_ids)->get();
        }
        return collect();
    }

    /**
     * Get multiple lessons as a query builder (for eager loading compatibility)
     */
    public function lessonsQuery()
    {
        if ($this->lesson_ids && is_array($this->lesson_ids)) {
            return Lesson::whereIn('id', $this->lesson_ids);
        }
        return Lesson::whereRaw('1 = 0'); // Return empty query
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
                    ->where('used_count', '<', \DB::raw('max_uses'))
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
     * Get the target content (course or lesson) for this code
     */
    public function getTargetContent()
    {
        switch ($this->type) {
            case 'course':
                return $this->course;
            case 'lesson':
                return $this->lesson;
            case 'multiple_lessons':
                return $this->lessons;
            default:
                return null;
        }
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
                $lessons = $this->lessons();
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

    /**
     * Check if an order already exists for this code redemption by a user
     *
     * @param int $userId The user ID
     * @return bool True if order exists
     */
    public function hasOrderForUser(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->where('course_code_id', $this->id)
            ->exists();
    }

    /**
     * Get existing order for this code redemption by a user
     *
     * @param int $userId The user ID
     * @return Order|null The existing order or null
     */
    public function getOrderForUser(int $userId): ?Order
    {
        return Order::where('user_id', $userId)
            ->where('course_code_id', $this->id)
            ->orderBy('id')
            ->first();
    }
}

