<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
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
            'name_ar' => 'required|string|min:3|max:50',
            'name_en' => 'required|string|min:3|max:50',
            'image' => ($this->method() === 'POST' ? 'required|image' : ''),
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'name_ar' => 'أسم  المتجر ',
            'name_en' => 'أسم المتجر باللإنجليزية',
            'image' => 'صوره المتجر',
        ];
    }
}
