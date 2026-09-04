<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class CoursePdf extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'title_en',
        'description',
        'description_en',
        'file_path',
        'storage_disk',
        'original_filename',
        'file_size',
        'content_sha256',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'order' => 'integer',
    ];

    /**
     * Get the course that owns the PDF.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the full storage path for the PDF file.
     */
    public function getFullPathAttribute()
    {
        if (!$this->hasConfiguredStorage()) {
            throw new \RuntimeException('Course PDF storage is not configured.');
        }

        return Storage::disk($this->storage_disk)->path($this->file_path);
    }

    public function getStorageDiskAttribute($value): string
    {
        return trim((string) $value);
    }

    /**
     * Check if the PDF file exists.
     */
    public function fileExists()
    {
        if (!$this->hasConfiguredStorage()) {
            return false;
        }

        return Storage::disk($this->storage_disk)->exists($this->file_path);
    }

    /**
     * Get file size in human-readable format.
     */
    public function getFormattedFileSizeAttribute()
    {
        $bytes = $this->file_size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Delete the PDF file from storage.
     */
    public function deleteFile()
    {
        if ($this->fileExists()) {
            return Storage::disk($this->storage_disk)->delete($this->file_path);
        }
        return false;
    }

    private function hasConfiguredStorage(): bool
    {
        $disk = trim((string) $this->storage_disk);
        $path = trim((string) $this->file_path);

        return $disk !== '' && $path !== '' && is_array(config("filesystems.disks.{$disk}"));
    }

    /**
     * Scope active PDFs.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered PDFs.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}
