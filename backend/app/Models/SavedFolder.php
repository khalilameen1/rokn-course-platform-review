<?php

namespace App\Models;

use App\Support\UnicodeText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'normalized_name',
        'client_request_id',
    ];

    public static function normalizeName(mixed $name): string
    {
        $clean = self::cleanName($name);

        return mb_strtolower($clean !== '' ? $clean : 'قائمة محفوظة', 'UTF-8');
    }

    public static function cleanName(mixed $name): string
    {
        return UnicodeText::clean($name, false);
    }

    /**
     * Get the user that owns the saved folder.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the lessons saved in this folder.
     */
    public function lessons()
    {
        return $this->belongsToMany(Lesson::class, 'saved_folder_lessons')
            ->withTimestamps();
    }
}
