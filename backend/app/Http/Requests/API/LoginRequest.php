<?php

namespace App\Http\Requests\API;

class LoginRequest extends FormRequest
{
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
            // 'phone' => 'required|exists:users,phone',
            'phone' => 'required',
            'password' => 'required',
            'device_id' => 'nullable|string|max:255',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_os' => 'nullable|string|max:255',
        ];
    }
}
