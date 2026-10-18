<?php

namespace Database\Seeders;

use App\Models\Grade;
use Illuminate\Database\Seeder;

class GradeSeeder extends Seeder
{
    public function run()
    {
        $grades = [
            [
                'type' => 'preparatory',
                'name_ar' => 'Grade 1',
                'name_en' => 'Grade 1',
                'description_ar' => 'First grade of preparatory education',
                'description_en' => 'First grade of preparatory education',
                'country' => 'egypt',
            ],
            [
                'type' => 'preparatory',
                'name_ar' => 'Grade 2',
                'name_en' => 'Grade 2',
                'description_ar' => 'Second grade of preparatory education',
                'description_en' => 'Second grade of preparatory education',
                'country' => 'egypt',
            ],
            [
                'type' => 'preparatory',
                'name_ar' => 'Grade 3',
                'name_en' => 'Grade 3',
                'description_ar' => 'Third grade of preparatory education',
                'description_en' => 'Third grade of preparatory education',
                'country' => 'egypt',
            ],
            [
                'type' => 'secondary',
                'name_ar' => 'Secondary 1',
                'name_en' => 'Secondary 1',
                'description_ar' => 'First grade of secondary education',
                'description_en' => 'First grade of secondary education',
                'country' => 'egypt',
            ],
            [
                'type' => 'secondary',
                'name_ar' => 'Secondary 2',
                'name_en' => 'Secondary 2',
                'description_ar' => 'Second grade of secondary education',
                'description_en' => 'Second grade of secondary education',
                'country' => 'egypt',
            ],
            [
                'type' => 'secondary',
                'name_ar' => 'Secondary 3',
                'name_en' => 'Secondary 3',
                'description_ar' => 'Third grade of secondary education',
                'description_en' => 'Third grade of secondary education',
                'country' => 'egypt',
            ],
        ];

        foreach ($grades as $grade) {
            Grade::create($grade);
        }
    }
}
