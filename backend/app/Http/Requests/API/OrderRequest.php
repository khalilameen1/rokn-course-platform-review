<?php

namespace App\Http\Requests\API;

class OrderRequest extends FormRequest
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
            // 'user_id' => 'required|exists:users,id',
            'provider' => 'exists:users,id',
            // 'price' => 'required',
            // 'tax' => 'required',

            'client_lat' => 'required',
            'client_lng' => 'required',
            'delivering_lat' => 'required',
            'delivering_lng' => 'required',
            'coupon_id' => 'exists:coupons,id',
            'delivery_time_id' => 'required',
            // 'total'=> 'required',
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            //  'user_id' => 'تأكد من وجود هذا العضو',
            'provider' => 'تأكد من وجود هذا المندوب',
            // 'price' => 'من فضلك ضع السعر العام ',
            // 'tax' => 'الضريبة',
            'client_lat' => ' أضف موقع الاستلام',
            'client_lng' => ' أضف موقع الاستلام',
            'delivering_lat' => ' أضف موقع التسليم',
            'delivering_lng' => ' أضف موقع التسليم',
            'coupon_id' => 'تأكد من صحة الكوبون',
            'delivery_time_id' => 'وقت التسليم',
            // 'total'=> 'أضف السعر النهائي',
        ];
    }
}
