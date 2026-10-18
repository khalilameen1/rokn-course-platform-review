<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $days_ar_array = ['1' => 'السبت',
            '2' => 'الأحد',
            '3' => 'الاثنين',
            '4' => 'الثلاثاء',
            '5' => 'الأربعاء',
            '6' => 'الخميس',
            '7' => 'الجمعة',
        ];
        $days_en_array = ['1' => 'Saturday',
            '2' => 'Sunday',
            '3' => 'Monday',
            '4' => 'Tuesday',
            '5' => 'Wednesday',
            '6' => 'Thursday',
            '7' => 'friday',
        ];

        return [
            'id' => (int) $this->id,
            'day' => (int) $this->day,
            'day_name_ar' => $days_ar_array[$this->day],
            'day_name_en' => $days_en_array[$this->day],
            'open_time' => $this->open_time ? $this->open_time : null,
            'close_time' => $this->close_time ? $this->close_time : null,

        ];
    }
}
