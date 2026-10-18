<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

final class CourseRatingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'version' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'اختر تقييمك',
            'rating.integer' => 'التقييم غير صحيح',
            'rating.min' => 'التقييم غير صحيح',
            'rating.max' => 'التقييم غير صحيح',
            'comment.max' => 'التعليق طويل جدًا',
            'version.required' => 'حدّث الكورس ثم حاول مرة أخرى',
            'version.integer' => 'حدّث الكورس ثم حاول مرة أخرى',
            'version.min' => 'حدّث الكورس ثم حاول مرة أخرى',
        ];
    }

    protected function prepareForValidation(): void
    {
        $comment = trim((string) $this->input('comment', ''));
        $this->merge(['comment' => $comment !== '' ? $comment : null]);
    }
}
