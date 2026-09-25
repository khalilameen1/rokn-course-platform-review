<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\CourseCode;
use App\Support\CourseCodeRejection;

final class CourseCodeUnavailable extends \DomainException
{
    public function __construct(
        public readonly CourseCodeRejection $reason,
        public readonly ?CourseCode $courseCode = null
    ) {
        parent::__construct($reason->value);
    }
}
