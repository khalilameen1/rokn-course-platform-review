<?php

namespace App\Http\Requests\API;

class OrderRequestRequest extends FormRequest
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
            'price' => 'required',
            'latitude' => 'required',
            'longitude' => 'required',
            'distance' => 'required',
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'price' => 'سعر العرض',
            'distance' => 'المسافه',
            'latitude' => 'الموقع ',
            'longitude' => 'الموقع',
        ];
    }
}
