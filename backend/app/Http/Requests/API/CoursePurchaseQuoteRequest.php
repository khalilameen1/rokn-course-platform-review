<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

/** A quote reads terms only; it has no reviewed total to bind yet. */
final class CoursePurchaseQuoteRequest extends CourseAuthorizationRequest
{
    public function rules()
    {
        $rules = parent::rules();
        unset($rules['expected_price']);

        return $rules;
    }
}
