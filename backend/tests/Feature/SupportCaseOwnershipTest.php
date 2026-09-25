<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\SupportCaseAccessService;
use App\Services\SupportCaseReadService;
use App\Services\SupportCaseScreenshotService;
use App\Services\SupportCaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupportCaseOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Screenshot staging commits the orphan ledger before writing bytes.
        // An outer test transaction would hide that real admission boundary.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('feedback');
        $this->freezeTime();
    }

    public function test_customer_reads_hide_internal_content_without_resolving_a_writer_or_image_processor(): void
    {
        $owner = $this->owner();
        $token = $owner->generateApiToken();
        $report = $this->report(['user_id' => $owner->id]);
        $visible = $this->message($report, 'customer', 'Visible answer');
        $hidden = $this->message($report, 'internal', 'Private staff note');
        $report->attachments()->create([
            'support_case_message_id' => $hidden->id, 'disk' => 'feedback', 'path' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'scan_status' => 'sanitized',
        ]);
        $report->attachments()->create([
            'support_case_message_id' => $visible->id, 'disk' => 'feedback', 'path' => 'corrupt.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'scan_status' => 'corrupt',
        ]);
        $before = $report->refresh()->getAttributes();
        foreach ([SupportCaseService::class, SupportCaseScreenshotService::class] as $class) {
            $this->app->bind($class, static function (): never {
                throw new \LogicException('Customer reads must not resolve writers or image processing');
            });
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $payload = app(SupportCaseReadService::class)->customerPayload($report);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
        self::assertCount(1, $payload['messages']);
        self::assertSame('Visible answer', $payload['messages'][0]['text']);
        self::assertSame([], $payload['messages'][0]['attachments']);
        self::assertSame([], $payload['attachments']);
        self::assertSame($before, $report->fresh()->getAttributes());
        $this->withToken($token)->getJson('/api/v1/feedback/'.$report->public_id)
            ->assertOk()->assertJsonCount(1, 'data.messages')->assertDontSee('Private staff note');
        $this->withToken($token)->getJson('/api/v1/feedback')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_guest_credential_header_and_claim_keep_the_existing_access_contract(): void
    {
        $access = app(SupportCaseAccessService::class);
        $requestId = (string) Str::uuid();
        $credential = $access->createGuestCredential($requestId);
        self::assertSame($credential, $access->createGuestCredential($requestId));
        self::assertSame(hash('sha256', $credential['token']), $credential['hash']);
        $report = $this->report(['guest_access_hash' => $credential['hash']]);
        $url = '/api/v1/feedback/'.$report->public_id;

        $this->getJson($url)->assertNotFound();
        $this->withHeader('X-Support-Access', str_repeat('x', 129))->getJson($url)->assertNotFound();
        $this->withHeader('X-Support-Access', 'wrong-token')->getJson($url)->assertNotFound();
        $this->withHeader('X-Support-Access', ' '.$credential['token'].' ')->getJson($url)
            ->assertOk()->assertJsonPath('data.public_id', $report->public_id);

        $owner = $this->owner();
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->generateApiToken())->postJson($url.'/claim')->assertOk();
        $report->refresh();
        self::assertSame($owner->id, (int) $report->user_id);
        self::assertNull($report->guest_access_hash);
        $access->authorizeViewer($report, $owner, null);
        try {
            $access->authorizeViewer($report, null, $credential['token']);
            self::fail('Claiming must revoke guest-token access.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_http_screenshot_receipt_replays_without_duplicate_bytes_and_signed_links_expire(): void
    {
        $image = UploadedFile::fake()->image('screen.png', 2100, 100)->size(2);
        $data = [
            'client_request_id' => (string) Str::uuid(), 'category' => 'bug',
            'message' => 'الصورة توضح المشكلة عند فتح الكورس',
        ];
        $send = fn (array $input) => $this->call(
            'POST', '/api/v1/feedback', $input, [], ['screenshot' => $image], ['HTTP_ACCEPT' => 'application/json']
        );
        $first = $send($data)->assertCreated()->assertJsonPath('data.replayed', false);
        $url = $first->json('data.messages.0.attachments.0.url');
        $report = FeedbackReport::query()->sole();
        $attachment = $report->attachments()->sole();
        $bytes = Storage::disk('feedback')->get($attachment->path);
        self::assertSame('image/jpeg', $attachment->mime_type);
        self::assertSame(2048, (int) $attachment->width);
        self::assertSame(hash('sha256', $bytes), $attachment->sha256);
        self::assertSame(strlen($bytes), (int) $attachment->size_bytes);
        $send($data)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.public_id', $report->public_id);
        $send([...$data, 'message' => 'رسالة مختلفة تستخدم نفس رقم الطلب'])->assertStatus(409);
        self::assertSame(1, $report->messages()->count());
        self::assertSame(1, $report->attachments()->count());
        self::assertCount(1, Storage::disk('feedback')->allFiles());
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_staff_reply_replay_does_not_repeat_events_and_internal_notes_stay_private(): void
    {
        $report = $this->report();
        $staff = $this->owner('admin');
        $writer = app(SupportCaseService::class);
        $id = (string) Str::uuid();
        $first = $writer->appendStaffMessage($report, $staff, 'Public answer', 'customer', $id, 1);
        $replay = $writer->appendStaffMessage($report, $staff, 'Public answer', 'customer', $id, 1);
        self::assertSame($first->id, $replay->id);
        self::assertSame(1, $report->events()->count());
        self::assertSame(2, (int) $report->fresh()->version);
        $writer->appendStaffMessage($report, $staff, 'Internal diagnosis', 'internal', (string) Str::uuid(), 2);
        $payload = app(SupportCaseReadService::class)->customerPayload($report->fresh());
        self::assertCount(1, $payload['messages']);
        self::assertSame('Public answer', $payload['messages'][0]['text']);
        try {
            $writer->appendStaffMessage($report, $staff, 'Changed reply', 'customer', $id, 3);
            self::fail('Replays cannot replace the accepted reply.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertSame(2, $report->messages()->count());
        self::assertSame(3, (int) $report->fresh()->version);
    }

    public function test_unreadable_screenshot_does_not_admit_a_message_or_write_storage(): void
    {
        $report = $this->report();
        $invalid = UploadedFile::fake()->createWithContent('screen.jpg', 'not an image');
        try {
            app(SupportCaseService::class)->appendLearnerMessage(
                $report, null, 'تفاصيل المشكلة', (string) Str::uuid(), $invalid
            );
            self::fail('Image admission must fail before message persistence.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
        self::assertSame(0, $report->messages()->count());
        self::assertSame(0, $report->attachments()->count());
        self::assertSame([], Storage::disk('feedback')->allFiles());
    }

    private function report(array $extra = []): FeedbackReport
    {
        return FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'تفاصيل المشكلة كاملة', 'version' => 1, ...$extra,
        ]);
    }

    private function owner(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Support owner', 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }

    private function message(FeedbackReport $report, string $visibility, string $body): SupportCaseMessage
    {
        return $report->messages()->create([
            'public_id' => (string) Str::ulid(), 'author_type' => 'staff',
            'visibility' => $visibility, 'body' => $body,
        ]);
    }
}
