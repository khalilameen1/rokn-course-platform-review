<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

final class AdminContactReadService
{
    /** @return Builder<Contact> */
    public function messages(): Builder
    {
        return Contact::query()->with(['resolver', 'resolvedUser']);
    }
}
