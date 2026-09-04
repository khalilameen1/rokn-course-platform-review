<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('attachments') && Schema::hasTable('course_pdfs')) {
            DB::transaction(fn () => $this->promoteLegacyPdfs(), 3);
            Schema::drop('attachments');
        }

        if (Schema::hasTable('course_sections') && Schema::hasColumn('course_sections', 'deleted_at')) {
            DB::table('course_sections')
                ->whereNull('deleted_at')
                ->whereIn('sectionable_type', [
                    'App\\Models\\CoursePdf',
                    'course_pdf',
                    'course_pdfs',
                ])
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'attachment_prompt_frequency')) {
            DB::table('courses')
                ->where('attachment_prompt_frequency', 'once_per_module')
                ->update(['attachment_prompt_frequency' => 'once_per_course']);
        }

        if (Schema::hasTable('course_modules')) {
            $columns = array_values(array_filter(
                ['attachments_link', 'attachment_platform'],
                static fn (string $column): bool => Schema::hasColumn('course_modules', $column)
            ));
            if ($columns !== []) {
                Schema::table('course_modules', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('attachments')) {
            Schema::create('attachments', function (Blueprint $table): void {
                $table->id();
                $table->string('attachable_type');
                $table->unsignedBigInteger('attachable_id');
                $table->string('title');
                $table->string('file_path');
                $table->string('storage_disk', 64)->default('public')->index();
                $table->string('file_type')->nullable();
                $table->string('mime_type', 190)->nullable();
                $table->bigInteger('file_size')->nullable();
                $table->char('content_sha256', 64)->nullable();
                $table->integer('order')->default(0);
                $table->timestamps();
                $table->index(['attachable_type', 'attachable_id', 'order']);
                $table->unique(
                    ['attachable_type', 'attachable_id', 'content_sha256'],
                    'attachments_content_unique'
                );
            });
        }

        if (!Schema::hasTable('course_modules')) {
            return;
        }

        Schema::table('course_modules', function (Blueprint $table): void {
            if (!Schema::hasColumn('course_modules', 'attachments_link')) {
                $table->string('attachments_link')->nullable();
            }
            if (!Schema::hasColumn('course_modules', 'attachment_platform')) {
                $table->string('attachment_platform', 16)->default('both');
            }
        });
    }

    private function promoteLegacyPdfs(): void
    {
        if (DB::table('attachments')->exists() && !Schema::hasTable('account_file_deletions')) {
            throw new RuntimeException('The durable file-deletion ledger must exist before retiring course attachments.');
        }

        $moduleOwners = Schema::hasTable('course_modules')
            ? DB::table('course_modules')->get(['id', 'course_id', 'order'])->keyBy('id')
            : collect();
        $sectionOwners = Schema::hasTable('course_sections')
            ? DB::table('course_sections')->get(['id', 'course_id', 'order'])->keyBy('id')
            : collect();

        $nextOrder = DB::table('course_pdfs')
            ->whereNull('deleted_at')
            ->get(['course_id', 'order'])
            ->groupBy('course_id')
            ->map(static fn ($pdfs): int => (int) $pdfs->max('order'))
            ->all();

        $knownHashes = [];
        $knownPaths = [];
        foreach (DB::table('course_pdfs')->whereNull('deleted_at')->get() as $pdf) {
            $courseId = (int) $pdf->course_id;
            $hash = strtolower(trim((string) ($pdf->content_sha256 ?? '')));
            if ($hash !== '') {
                $knownHashes[$courseId][$hash] = true;
            }
            $knownPaths[$courseId][$this->pathKey(
                (string) ($pdf->storage_disk ?? ''),
                (string) $pdf->file_path
            )] = true;
        }

        DB::table('attachments')->orderBy('id')->chunkById(200, function ($attachments) use (
            $moduleOwners,
            $sectionOwners,
            &$nextOrder,
            &$knownHashes,
            &$knownPaths
        ): void {
            foreach ($attachments as $attachment) {
                $type = ltrim(strtolower((string) $attachment->attachable_type), '\\');
                $owner = match ($type) {
                    'app\\models\\coursemodule', 'course_module', 'course_modules' => $moduleOwners->get($attachment->attachable_id),
                    'app\\models\\coursesection', 'course_section', 'course_sections' => $sectionOwners->get($attachment->attachable_id),
                    default => null,
                };
                $courseId = (int) ($owner->course_id ?? 0);
                $disk = trim((string) ($attachment->storage_disk ?? '')) ?: 'public';
                $path = trim((string) $attachment->file_path);
                $hash = strtolower(trim((string) ($attachment->content_sha256 ?? '')));
                $hash = preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : '';
                $pathKey = $this->pathKey($disk, $path);
                $duplicateByHash = $courseId > 0
                    && $hash !== ''
                    && isset($knownHashes[$courseId][$hash]);
                $duplicateByPath = $courseId > 0
                    && isset($knownPaths[$courseId][$pathKey]);
                $canPromote = $courseId > 0 && $this->isPdf($attachment);

                if ($canPromote && !$duplicateByHash && !$duplicateByPath) {
                    $nextOrder[$courseId] = ((int) ($nextOrder[$courseId] ?? 0)) + 1;
                    DB::table('course_pdfs')->insert([
                        'course_id' => $courseId,
                        'title' => trim((string) $attachment->title) ?: pathinfo($path, PATHINFO_FILENAME),
                        'title_en' => null,
                        'description' => null,
                        'description_en' => null,
                        'file_path' => $path,
                        'storage_disk' => $disk,
                        'original_filename' => basename(str_replace('\\', '/', $path)),
                        'file_size' => $attachment->file_size,
                        'content_sha256' => $hash !== '' ? $hash : null,
                        'order' => $nextOrder[$courseId],
                        'is_active' => true,
                        'created_at' => $attachment->created_at ?? now(),
                        'updated_at' => $attachment->updated_at ?? now(),
                        'deleted_at' => null,
                    ]);
                    if ($hash !== '') {
                        $knownHashes[$courseId][$hash] = true;
                    }
                    $knownPaths[$courseId][$pathKey] = true;
                } elseif (!$duplicateByPath) {
                    $this->enqueueCleanup($disk, $path);
                }

                DB::table('attachments')->where('id', $attachment->id)->delete();
            }
        });
    }

    private function isPdf(object $attachment): bool
    {
        $mime = strtolower(trim((string) ($attachment->mime_type ?? '')));
        $type = strtolower(trim((string) ($attachment->file_type ?? '')));
        $path = (string) ($attachment->file_path ?? '');
        $urlPath = parse_url($path, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($urlPath) ? $urlPath : $path, PATHINFO_EXTENSION));

        return $mime === 'application/pdf' || $type === 'pdf' || $extension === 'pdf';
    }

    private function pathKey(string $disk, string $path): string
    {
        return strtolower(trim($disk)).'|'.ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function enqueueCleanup(string $disk, string $path): void
    {
        $disk = trim($disk);
        $path = ltrim(trim($path), '/');
        if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        DB::table('account_file_deletions')->updateOrInsert(
            [
                'disk' => $disk,
                'path_hash' => hash('sha256', $path),
            ],
            [
                'user_id' => null,
                'path' => Crypt::encryptString($path),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'completed_at' => null,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
