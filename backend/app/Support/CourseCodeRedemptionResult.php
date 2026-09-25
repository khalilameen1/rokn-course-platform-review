<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseCode;

/** Only a successful, committed redemption or existing effective access. */
final readonly class CourseCodeRedemptionResult
{
    public function __construct(
        public CourseCode $courseCode,
        public bool $alreadyEnrolled
    ) {
    }
}
