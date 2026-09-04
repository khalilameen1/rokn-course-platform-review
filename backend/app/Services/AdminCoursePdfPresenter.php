<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePdf;

final class AdminCoursePdfPresenter
{
    /** @return array<string, mixed> */
    public function one(Course $course, CoursePdf $pdf): array
    {
        if ((int) $pdf->course_id !== (int) $course->id) {
            throw new \LogicException('The course attachment does not belong to this course.');
        }

        return [
            'id' => (int) $pdf->id,
            'title' => (string) $pdf->title,
            'title_en' => $pdf->title_en,
            'description' => $pdf->description,
            'description_en' => $pdf->description_en,
            'original_filename' => $pdf->original_filename,
            'file_size' => (int) $pdf->file_size,
            'formatted_file_size' => (string) $pdf->formatted_file_size,
            'order' => (int) $pdf->order,
            'is_active' => (bool) $pdf->is_active,
            'preview_url' => route('admin.courses.pdfs.preview', [$course, $pdf]),
            'update_url' => route('admin.courses.pdfs.update', [$course, $pdf]),
            'toggle_url' => route('admin.courses.pdfs.toggle-status', [$course, $pdf]),
            'delete_url' => route('admin.courses.pdfs.destroy', [$course, $pdf]),
        ];
    }
}
