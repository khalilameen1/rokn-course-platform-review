<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class BunnyCleanupBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cleanup_ids' => 'required|array|min:1|max:100',
            'cleanup_ids.*' => 'required|integer|distinct|exists:bunny_video_cleanup_candidates,id',
        ];
    }
}
