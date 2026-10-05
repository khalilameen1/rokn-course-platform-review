<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('user_device_tokens')
            && Schema::hasColumn('user_device_tokens', 'device_id')) {
            DB::table('user_device_tokens')
                ->whereNotNull('device_id')
                ->select('device_id')
                ->groupBy('device_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('device_id')
                ->pluck('device_id')
                ->each(function (string $deviceId): void {
                    $keep = DB::table('user_device_tokens')
                        ->where('device_id', $deviceId)
                        ->max('id');
                    DB::table('user_device_tokens')
                        ->where('device_id', $deviceId)
                        ->where('id', '<>', $keep)
                        ->delete();
                });
            if (!Schema::hasIndex('user_device_tokens', ['device_id'], 'unique')) {
                Schema::table('user_device_tokens', function (Blueprint $table): void {
                    if (Schema::hasIndex('user_device_tokens', 'user_device_tokens_device_id_index')) {
                        $table->dropIndex('user_device_tokens_device_id_index');
                    }
                    $table->unique('device_id', 'user_device_tokens_installation_unique');
                });
            }
        }

        if (Schema::hasTable('notification_campaigns')) {
            Schema::table('notification_campaigns', function (Blueprint $table): void {
                if (!Schema::hasColumn('notification_campaigns', 'scheduled_at')) {
                    $table->timestamp('scheduled_at')->nullable()->index()->after('retry_count');
                }
                if (!Schema::hasColumn('notification_campaigns', 'selection_cursor')) {
                    $table->unsignedBigInteger('selection_cursor')->default(0)->after('scheduled_at');
                }
                if (!Schema::hasColumn('notification_campaigns', 'selection_finished_at')) {
                    $table->timestamp('selection_finished_at')->nullable()->after('selection_cursor');
                }
                if (!Schema::hasColumn('notification_campaigns', 'resolved_count')) {
                    $table->unsignedInteger('resolved_count')->default(0)->after('inbox_count');
                }
                if (!Schema::hasColumn('notification_campaigns', 'skipped_count')) {
                    $table->unsignedInteger('skipped_count')->default(0)->after('resolved_count');
                }
            });
        }

        if (!Schema::hasTable('notification_campaign_recipients')) {
            Schema::create('notification_campaign_recipients', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('notification_campaign_id');
                $table->unsignedBigInteger('user_id');
                $table->string('status', 24)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('resolution_code', 64)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['notification_campaign_id', 'user_id'],
                    'notification_campaign_recipient_once'
                );
                $table->index(
                    ['notification_campaign_id', 'status', 'id'],
                    'notification_campaign_recipient_work'
                );
                $table->foreign(
                    'notification_campaign_id',
                    'notification_recipient_campaign_fk'
                )
                    ->references('id')->on('notification_campaigns')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('notification_push_deliveries')) {
            Schema::create('notification_push_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('student_notification_id');
                $table->unsignedBigInteger('user_device_token_id');
                $table->string('token_fingerprint', 64);
                $table->string('device_os', 20)->nullable();
                $table->string('status', 24)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->timestamps();

                $table->unique(
                    ['student_notification_id', 'user_device_token_id'],
                    'notification_push_device_once'
                );
                $table->index(['status', 'attempted_at'], 'notification_push_recovery');
                $table->foreign('student_notification_id')
                    ->references('id')->on('student_notifications')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('student_notifications')) {
            if (!Schema::hasIndex('student_notifications', 'student_notifications_cooldown_lookup')) {
                Schema::table('student_notifications', fn (Blueprint $table) => $table->index(
                    ['user_id', 'notification_type', 'created_at'],
                    'student_notifications_cooldown_lookup'
                ));
            }
            if (!Schema::hasIndex('student_notifications', 'student_notifications_campaign_delivery')) {
                Schema::table('student_notifications', fn (Blueprint $table) => $table->index(
                    ['delivery_key', 'push_sent_at', 'push_failed_at'],
                    'student_notifications_campaign_delivery'
                ));
            }
        }

        if (Schema::hasTable('admin_notifications')
            && Schema::hasColumn('admin_notifications', 'system_key')) {
            DB::table('admin_notifications')->insertOrIgnore([[
                'system_key' => 'support_case_update',
                'surface' => 'transactional',
                'title_ar' => 'تحديث على بلاغك',
                'title_en' => 'Support case updated',
                'description_ar' => 'راجع آخر تحديث على البلاغ {case}',
                'description_en' => 'Review the latest update on case {case}',
                'action_label_ar' => 'افتح البلاغ',
                'action_label_en' => 'View case',
                'secondary_action_label_ar' => 'إغلاق',
                'secondary_action_label_en' => 'Close',
                'link' => null,
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 30,
                'cooldown_hours' => 0,
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'system_key' => 'new_course',
                'surface' => 'announcement',
                'title_ar' => 'كورس جديد',
                'title_en' => 'New course',
                'description_ar' => '{course}',
                'description_en' => '{course}',
                'action_label_ar' => 'افتح الكورس',
                'action_label_en' => 'View course',
                'secondary_action_label_ar' => 'لاحقًا',
                'secondary_action_label_en' => 'Later',
                'link' => null,
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 80,
                'cooldown_hours' => 72,
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_notifications')) {
            Schema::table('student_notifications', function (Blueprint $table): void {
                if (Schema::hasIndex('student_notifications', 'student_notifications_cooldown_lookup')) {
                    $table->dropIndex('student_notifications_cooldown_lookup');
                }
                if (Schema::hasIndex('student_notifications', 'student_notifications_campaign_delivery')) {
                    $table->dropIndex('student_notifications_campaign_delivery');
                }
            });
        }
        Schema::dropIfExists('notification_push_deliveries');
        Schema::dropIfExists('notification_campaign_recipients');

        if (Schema::hasTable('notification_campaigns')) {
            Schema::table('notification_campaigns', function (Blueprint $table): void {
                if (Schema::hasIndex('notification_campaigns', 'notification_campaigns_scheduled_at_index')) {
                    $table->dropIndex('notification_campaigns_scheduled_at_index');
                }
                $columns = array_values(array_filter([
                    'scheduled_at',
                    'selection_cursor',
                    'selection_finished_at',
                    'resolved_count',
                    'skipped_count',
                ], static fn (string $column): bool => Schema::hasColumn('notification_campaigns', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('user_device_tokens')
            && Schema::hasColumn('user_device_tokens', 'device_id')
            && Schema::hasIndex('user_device_tokens', 'user_device_tokens_installation_unique')) {
            Schema::table('user_device_tokens', function (Blueprint $table): void {
                $table->dropUnique('user_device_tokens_installation_unique');
                if (!Schema::hasIndex('user_device_tokens', 'user_device_tokens_device_id_index')) {
                    $table->index('device_id');
                }
            });
        }
    }
};
