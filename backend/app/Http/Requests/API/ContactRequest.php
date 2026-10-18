<?php

namespace App\Http\Requests\API;

use App\Support\UnicodeText;
use Illuminate\Support\Str;

class ContactRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->input('name') !== null) {
            $normalized['name'] = UnicodeText::clean($this->input('name'), false);
        }
        if ($this->input('message') !== null) {
            $normalized['message'] = UnicodeText::clean($this->input('message'));
        }
        if ($this->input('phone') !== null) {
            $normalized['phone'] = UnicodeText::identifier($this->input('phone'));
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }

        if ($this->filled('client_request_id')) {
            return;
        }

        $candidate = trim((string) $this->header('Idempotency-Key'));
        $this->merge([
            'client_request_id' => Str::isUuid($candidate)
                ? $candidate
                : (string) Str::uuid(),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'client_request_id' => 'required|uuid',
            'name' => 'required|string|min:2|max:120',
            'phone' => 'nullable|string|max:32',
            'email' => 'required|email|max:255',
            'message' => 'required|string|min:10|max:2000',
        ];
    }
}
