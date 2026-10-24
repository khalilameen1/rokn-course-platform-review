<?php

declare(strict_types=1);

namespace App\Rules;

use App\Auth\StaffAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SupportAssignee implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(StaffAccess::class)->usersWithPermission('support.manage')->whereKey($value)->exists()) {
            $fail('اختر مسؤول دعم نشطًا لديه صلاحية إدارة الحالات');
        }
    }
}
