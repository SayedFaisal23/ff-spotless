<?php

namespace Tests\Feature;

use App\Models\ChecklistDayStatus;
use App\Models\DailyChecklist;
use App\Models\TaskSession;
use App\Models\TaskReopenAudit;
use App\Models\TaskTemplate;
use App\Models\WeeklyTaskOccurrence;
use App\Models\WeeklyTaskPostponement;
use App\Models\WeeklyTaskTemplate;
use App\Services\ChecklistMaterializer;
use App\Services\EvidenceWatermarker;
use App\Services\OperationalDate;
use App\Services\StatisticsService;
use App\Services\WeeklyTaskScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChecklistWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('checklist.timezone', 'Asia/Kuala_Lumpur');
        config()->set('checklist.past_materialization_days', 365);
        config()->set('checklist.future_materialization_days', 365);
        config()->set('checklist.admin_password', 'test-master-password');
        app()->setLocale('ms');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00.123456', 'Asia/Kuala_Lumpur'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_sessions_and_credit_snapshots_are_materialized(): void
    {
        $this->assertSame(['Pagi', 'Tengah Hari', 'Petang'], TaskSession::query()->orderBy('sort_order')->pluck('name')->all());
        $template = $this->dailyTemplate('Bersihkan kaca', 'Pagi', 1.5);

        $sheet = app(ChecklistMaterializer::class)->forDate(app(OperationalDate::class)->today());

        $this->assertCount(1, $sheet);
        $this->assertSame($template->id, $sheet->sole()->task_template_id);
        $this->assertSame('Pagi', $sheet->sole()->session_name);
        $this->assertSame('1.50', $sheet->sole()->credit_hours);
    }

    public function test_admin_can_manage_daily_templates_with_relational_sessions_and_credits(): void
    {
        $session = $this->taskSession();
        $this->loginAdmin();

        $this->post(route('admin.templates.store'), [
            'task_name' => ' Mop lobi ',
            'task_session_id' => $session->id,
            'credit_hours' => 1.25,
        ])->assertRedirect(route('admin.index'));

        $template = TaskTemplate::query()->where('task_name', 'Mop lobi')->sole();
        $this->assertSame($session->id, $template->task_session_id);
        $this->assertSame('1.25', $template->credit_hours);

        $this->post(route('admin.templates.store'), [
            'task_name' => 'Invalid credit',
            'task_session_id' => $session->id,
            'credit_hours' => 1.1,
        ])->assertSessionHasErrors('credit_hours');
    }

    public function test_session_archive_is_blocked_until_tasks_are_reassigned(): void
    {
        $session = $this->taskSession();
        $this->dailyTemplate('Task using session', $session->name);
        $this->loginAdmin();

        $this->delete(route('admin.sessions.destroy', $session))
            ->assertSessionHasErrors('session');
        $this->assertTrue($session->refresh()->is_active);
    }

    public function test_admin_can_create_rename_reorder_and_archive_an_unused_session(): void
    {
        $this->loginAdmin();
        $this->post(route('admin.sessions.store'), ['name' => 'Malam'])->assertRedirect(route('admin.index'));
        $session = TaskSession::query()->where('name', 'Malam')->sole();

        $this->patch(route('admin.sessions.update', $session), ['name' => 'Lewat Malam'])
            ->assertRedirect(route('admin.index'));
        $orderedIds = TaskSession::query()->active()->orderByDesc('sort_order')->pluck('id')->all();
        $this->patch(route('admin.sessions.reorder'), ['session_ids' => $orderedIds])
            ->assertRedirect(route('admin.index'));
        $this->delete(route('admin.sessions.destroy', $session))->assertRedirect(route('admin.index'));

        $this->assertSame('Lewat Malam', $session->refresh()->name);
        $this->assertFalse($session->is_active);
    }

    public function test_admin_weekly_crud_materializes_the_current_week_when_due_day_is_ahead(): void
    {
        $this->loginAdmin();
        $session = $this->taskSession();

        $this->post(route('admin.weekly-templates.store'), [
            'task_name' => 'Cuci kipas',
            'task_session_id' => $session->id,
            'due_weekday' => 5,
            'credit_hours' => 2.5,
        ])->assertRedirect(route('admin.index'));

        $template = WeeklyTaskTemplate::query()->where('task_name', 'Cuci kipas')->sole();
        $this->assertSame(
            '2026-07-17',
            WeeklyTaskOccurrence::query()->where('weekly_task_template_id', $template->id)->sole()->original_due_date->toDateString(),
        );

        $this->patch(route('admin.weekly-templates.update', $template), [
            'task_name' => 'Cuci semua kipas',
            'task_session_id' => $session->id,
            'due_weekday' => 6,
            'credit_hours' => 3,
        ])->assertRedirect(route('admin.index'));
        $this->assertSame('Cuci semua kipas', $template->refresh()->task_name);

        $this->delete(route('admin.weekly-templates.destroy', $template))->assertRedirect(route('admin.index'));
        $this->assertFalse($template->refresh()->is_active);
    }

    public function test_weekly_task_is_available_early_then_rolls_and_misses_after_sunday(): void
    {
        $template = $this->weeklyTemplate('Cuci stor', dueWeekday: 5);
        $scheduler = app(WeeklyTaskScheduler::class);
        $wednesday = app(OperationalDate::class)->today();

        $items = $scheduler->forChecklistDate($wednesday);
        $this->assertCount(1, $items);
        $this->assertSame('2026-07-17', $items->sole()->original_due_date->toDateString());

        $scheduler->advanceThrough(CarbonImmutable::parse('2026-07-18', 'Asia/Kuala_Lumpur'));
        $occurrence = WeeklyTaskOccurrence::query()->where('weekly_task_template_id', $template->id)->firstOrFail();
        $this->assertSame('2026-07-18', $occurrence->scheduled_date->toDateString());
        $template->forceFill(['task_name' => 'Cuci stor utama'])->save();
        $scheduler->updateTemplateSnapshots($template);
        $this->assertSame('2026-07-18', $occurrence->refresh()->scheduled_date->toDateString());
        $this->assertCount(1, $occurrence->postponements);

        $scheduler->advanceThrough(CarbonImmutable::parse('2026-07-20', 'Asia/Kuala_Lumpur'));
        $this->assertSame('missed', $occurrence->refresh()->status);
        $this->assertCount(2, $occurrence->postponements);
    }

    public function test_mc_locks_the_day_and_moves_a_due_weekly_task(): void
    {
        $this->dailyTemplate('Harian');
        $weekly = $this->weeklyTemplate('Mingguan', dueWeekday: 3);
        app(ChecklistMaterializer::class)->forDate(app(OperationalDate::class)->today());
        app(WeeklyTaskScheduler::class)->materializeWeek(app(OperationalDate::class)->today());
        $today = app(OperationalDate::class)->today()->toDateString();

        $this->post(route('checklist.availability'), [
            'date' => $today,
            'is_unavailable' => true,
        ])->assertRedirect(route('checklist.index', ['date' => $today]));

        $this->assertTrue(ChecklistDayStatus::query()->whereDate('date', $today)->where('is_unavailable', true)->exists());
        $occurrence = WeeklyTaskOccurrence::query()->where('weekly_task_template_id', $weekly->id)->sole();
        $this->assertSame('2026-07-16', $occurrence->scheduled_date->toDateString());
        $this->assertDatabaseHas('weekly_task_postponements', [
            'weekly_task_occurrence_id' => $occurrence->id,
            'reason' => 'unavailable',
        ]);

        $this->post(route('checklist.availability'), [
            'date' => $today,
            'is_unavailable' => false,
        ])->assertRedirect(route('checklist.index', ['date' => $today]));
        $this->assertSame($today, $occurrence->refresh()->scheduled_date->toDateString());
        $this->assertCount(0, $occurrence->postponements);
    }

    public function test_completion_requires_private_image_evidence_and_is_permanent(): void
    {
        Storage::fake('local');
        $this->fakeWatermarker();
        $task = $this->dailyTask();
        $today = $task->date->toDateString();

        $this->post(route('tasks.daily.complete', $task), ['date' => $today])
            ->assertSessionHasErrors('photos');

        $this->post(route('tasks.daily.complete', $task), [
            'date' => $today,
            'photos' => [$this->proof()],
        ])->assertRedirect(route('checklist.index', ['date' => $today]));

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $evidence = $task->evidence()->sole();
        Storage::disk('local')->assertExists($evidence->path);

        $this->post(route('tasks.daily.complete', $task), [
            'date' => $today,
            'photos' => [$this->proof()],
        ])->assertSessionHasErrors('task');
        $this->assertCount(1, $task->evidence);
    }

    public function test_evidence_can_only_be_streamed_by_an_admin(): void
    {
        Storage::fake('local');
        $this->fakeWatermarker();
        $task = $this->dailyTask();
        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'photos' => [$this->proof()],
        ]);
        $evidence = $task->evidence()->sole();

        $this->get(route('admin.evidence.daily', $evidence))->assertRedirect(route('home'));
        $this->loginAdmin();
        $this->get(route('admin.evidence.daily', $evidence))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cleaner_note_is_saved_with_completed_evidence(): void
    {
        Storage::fake('local');
        $this->fakeWatermarker();
        $task = $this->dailyTask();

        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'note' => 'Area closed while cleaning.',
            'photos' => [$this->proof()],
        ])->assertRedirect(route('checklist.index', ['date' => $task->date->toDateString()]));

        $this->assertSame('Area closed while cleaning.', $task->refresh()->completion_note);
    }

    public function test_admin_can_reopen_a_current_daily_task_with_an_audit_record(): void
    {
        Storage::fake('local');
        $this->fakeWatermarker();
        $task = $this->dailyTask();
        $date = $task->date->toDateString();
        $this->post(route('tasks.daily.complete', $task), [
            'date' => $date,
            'note' => 'Supplies were unavailable.',
            'photos' => [$this->proof()],
        ]);
        $evidence = $task->evidence()->sole();

        $this->patch(route('admin.tasks.daily.reopen', $task), ['reason' => 'The wrong area was photographed.'])
            ->assertRedirect(route('home'));

        $this->loginAdmin();
        $this->patch(route('admin.tasks.daily.reopen', $task), ['reason' => 'The wrong area was photographed.'])
            ->assertRedirect(route('admin.index'));

        $task->refresh();
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->completion_note);
        $this->assertSame(0, $task->evidence()->count());
        $this->assertNotNull($evidence->refresh()->invalidated_at);
        $this->assertSame('The wrong area was photographed.', $evidence->invalidation_reason);

        $audit = TaskReopenAudit::query()->sole();
        $this->assertSame('daily', $audit->task_type);
        $this->assertSame('Supplies were unavailable.', $audit->completion_note);
        $this->assertSame(1, $audit->invalidated_evidence_count);

        $this->post(route('tasks.daily.complete', $task), [
            'date' => $date,
            'note' => 'Area was cleaned after the correction.',
            'photos' => [$this->proof()],
        ])->assertRedirect(route('checklist.index', ['date' => $date]));
        $this->assertTrue($task->refresh()->is_completed);
        $this->assertSame('Area was cleaned after the correction.', $task->completion_note);
    }

    public function test_admin_dashboard_lists_overdue_daily_and_weekly_tasks(): void
    {
        $today = app(OperationalDate::class)->today();
        $this->dailyTask('Overdue daily', $today->subDay()->toDateString());
        $template = $this->weeklyTemplate('Overdue weekly', dueWeekday: 1);
        app(WeeklyTaskScheduler::class)->materializeWeek($today);
        WeeklyTaskOccurrence::query()
            ->where('weekly_task_template_id', $template->id)
            ->sole()
            ->forceFill([
                'status' => 'missed',
                'missed_reason' => 'incomplete',
                'scheduled_date' => $today->subDay(),
            ])->save();

        $this->loginAdmin();
        $this->get(route('admin.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('overdueTasks', 2)
                ->where('overdueTasks.0.taskText', 'Overdue daily')
                ->where('overdueTasks.1.taskText', 'Overdue weekly'));
    }

    public function test_completed_day_cannot_be_marked_unavailable(): void
    {
        Storage::fake('local');
        $this->fakeWatermarker();
        $task = $this->dailyTask();
        $date = $task->date->toDateString();
        $this->post(route('tasks.daily.complete', $task), ['date' => $date, 'photos' => [$this->proof()]]);

        $this->post(route('checklist.availability'), ['date' => $date, 'is_unavailable' => true])
            ->assertSessionHasErrors('is_unavailable');
        $this->assertDatabaseMissing('checklist_day_statuses', ['date' => $date, 'is_unavailable' => true]);
    }

    public function test_cleaner_can_persist_an_exact_same_session_order(): void
    {
        $first = $this->dailyTask('First');
        $second = $this->dailyTask('Second');
        $date = $first->date->toDateString();

        $this->post(route('checklist.order'), [
            'date' => $date,
            'task_session_id' => $this->taskSession()->id,
            'items' => [
                ['type' => 'daily', 'id' => $second->id],
                ['type' => 'daily', 'id' => $first->id],
            ],
        ])->assertRedirect(route('checklist.index', ['date' => $date]));

        $this->assertDatabaseHas('checklist_item_positions', ['item_type' => 'daily', 'item_id' => $second->id, 'position' => 1]);
        $this->assertDatabaseHas('checklist_item_positions', ['item_type' => 'daily', 'item_id' => $first->id, 'position' => 2]);

        $this->post(route('checklist.order'), [
            'date' => $date,
            'task_session_id' => $this->taskSession()->id,
            'items' => [['type' => 'daily', 'id' => $first->id]],
        ])->assertSessionHasErrors('items');
    }

    public function test_statistics_count_closed_daily_work_and_weekly_postponements(): void
    {
        $today = app(OperationalDate::class)->today();
        DB::table('statistics_tracking')->update(['started_on' => $today->subDay()->toDateString()]);
        $past = $this->dailyTask('Past task', $today->subDay()->toDateString());
        $current = $this->dailyTask('Current task', $today->toDateString());
        DB::table('checklist_materializations')->insertOrIgnore([
            ['date' => $today->subDay()->toDateString()],
            ['date' => $today->toDateString()],
        ]);
        $past->forceFill(['is_completed' => true, 'completed_at' => $today->subDay()->setTimezone('UTC')])->save();
        ChecklistDayStatus::query()->create(['date' => $today->toDateString(), 'is_unavailable' => true]);

        $stats = app(StatisticsService::class)->build($today->subDay(), $today);

        $this->assertSame(1, $stats['overview']['completed']);
        $this->assertSame(1, $stats['overview']['pending']);
        $this->assertSame(1, $stats['overview']['mcDays']);
        $this->assertSame(2.0, $stats['overview']['plannedCredits']);
        $this->assertSame(1.0, $stats['overview']['completedCredits']);
    }

    public function test_admin_endpoints_require_master_session_and_cleaner_page_is_anonymous(): void
    {
        $this->get(route('checklist.index'))->assertOk();
        $this->post(route('admin.sessions.store'), ['name' => 'Malam'])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('password');
    }

    public function test_non_current_completion_is_rejected_server_side(): void
    {
        Storage::fake('local');
        $task = $this->dailyTask('Old', app(OperationalDate::class)->today()->subDay()->toDateString());

        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'photos' => [$this->proof()],
        ])->assertForbidden();
        $this->assertFalse($task->refresh()->is_completed);
    }

    public function test_completion_fails_cleanly_when_watermarking_is_unavailable(): void
    {
        Storage::fake('local');
        $this->app->instance(EvidenceWatermarker::class, new class extends EvidenceWatermarker
        {
            public function watermark(UploadedFile $photo, string $text): array
            {
                throw ValidationException::withMessages([
                    'photos' => 'Pemprosesan watermark foto tidak tersedia. Sila aktifkan extension PHP GD dan EXIF di server.',
                ]);
            }
        });

        $task = $this->dailyTask();
        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'photos' => [$this->proof()],
        ])->assertSessionHasErrors('photos');

        $this->assertFalse($task->refresh()->is_completed);
        $this->assertSame(0, $task->evidence()->count());
    }

    public function test_completion_stores_hard_watermarked_image_when_gd_is_available(): void
    {
        $watermarker = app(EvidenceWatermarker::class);

        if (! $watermarker->isAvailable('image/png')) {
            $this->markTestSkipped('PHP GD with PNG support is required to verify image watermarking.');
        }

        Storage::fake('local');
        $task = $this->dailyTask();
        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'photos' => [$this->whitePngProof()],
        ])->assertRedirect(route('checklist.index', ['date' => $task->date->toDateString()]));

        $evidence = $task->evidence()->sole();
        $contents = Storage::disk('local')->get($evidence->path);
        $image = imagecreatefromstring($contents);
        $sample = imagecolorat($image, 6, 136);
        $red = ($sample >> 16) & 0xFF;
        $green = ($sample >> 8) & 0xFF;
        $blue = $sample & 0xFF;
        imagedestroy($image);

        $this->assertSame('image/png', $evidence->mime_type);
        $this->assertLessThan(250, max($red, $green, $blue));
    }

    public function test_completion_corrects_phone_jpeg_orientation_before_watermarking(): void
    {
        $watermarker = app(EvidenceWatermarker::class);

        if (! $watermarker->isAvailable('image/jpeg')) {
            $this->markTestSkipped('PHP GD with JPEG support and EXIF support is required to verify phone photo orientation.');
        }

        Storage::fake('local');
        $task = $this->dailyTask();
        $this->post(route('tasks.daily.complete', $task), [
            'date' => $task->date->toDateString(),
            'photos' => [$this->phoneJpegProof(6)],
        ])->assertRedirect(route('checklist.index', ['date' => $task->date->toDateString()]));

        $evidence = $task->evidence()->sole();
        $contents = Storage::disk('local')->get($evidence->path);
        $image = imagecreatefromstring($contents);
        $width = imagesx($image);
        $height = imagesy($image);
        $sample = imagecolorat($image, 10, $height - 10);
        $red = ($sample >> 16) & 0xFF;
        $green = ($sample >> 8) & 0xFF;
        $blue = $sample & 0xFF;
        imagedestroy($image);

        $this->assertSame('image/jpeg', $evidence->mime_type);
        $this->assertLessThan($height, $width, 'EXIF orientation 6 should be rotated into portrait dimensions.');
        $this->assertLessThan(250, max($red, $green, $blue), 'Watermark background should visibly darken the bottom-left pixels.');
    }

    public function test_dashboard_source_keeps_admin_english_and_cleaner_malay(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.vue'));

        $this->assertStringNotContainsString('Penta'.'dbir', $source);
        $this->assertStringNotContainsString('penta'.'dbir', $source);
        $this->assertStringContainsString('Admin Access', $source);
        $this->assertStringContainsString('Dashboard', $source);
        $this->assertStringContainsString('Back to today', $source);
        $this->assertStringContainsString('Buka senarai hari ini', $source);
        $this->assertStringContainsString('Hantar bukti & tandakan selesai', $source);
        $this->assertStringContainsString('() => closeEvidence(true)', $source);
    }

    private function taskSession(string $name = 'Pagi'): TaskSession
    {
        return TaskSession::query()->where('name', $name)->sole();
    }

    private function dailyTemplate(string $name, string $session = 'Pagi', float $credits = 1): TaskTemplate
    {
        return TaskTemplate::query()->create([
            'task_name' => $name,
            'task_session_id' => $this->taskSession($session)->id,
            'credit_hours' => $credits,
            'sort_order' => (int) TaskTemplate::query()->max('sort_order') + 1,
            'is_active' => true,
        ]);
    }

    private function weeklyTemplate(string $name, int $dueWeekday): WeeklyTaskTemplate
    {
        return WeeklyTaskTemplate::query()->create([
            'task_name' => $name,
            'task_session_id' => $this->taskSession()->id,
            'due_weekday' => $dueWeekday,
            'credit_hours' => 2,
            'sort_order' => (int) WeeklyTaskTemplate::query()->max('sort_order') + 1,
            'starts_on' => app(OperationalDate::class)->today()->startOfWeek()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function dailyTask(string $name = 'Clean entrance glass', ?string $date = null): DailyChecklist
    {
        $template = $this->dailyTemplate($name);

        return DailyChecklist::query()->create([
            'date' => $date ?? app(OperationalDate::class)->today()->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => $template->task_name,
            'task_session_id' => $template->task_session_id,
            'session_name' => $template->taskSession->name,
            'credit_hours' => $template->credit_hours,
            'is_completed' => false,
        ]);
    }

    private function proof(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXsAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('proof.png', $png);
    }

    private function whitePngProof(): UploadedFile
    {
        $image = imagecreatetruecolor(320, 160);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 319, 159, $white);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('proof.png', $contents);
    }

    private function phoneJpegProof(int $orientation): UploadedFile
    {
        $image = imagecreatetruecolor(120, 80);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 119, 79, $white);

        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('proof.jpg', $this->addExifOrientation($contents, $orientation));
    }

    private function addExifOrientation(string $jpeg, int $orientation): string
    {
        $payload = "Exif\0\0"
            .'II'
            .pack('v', 42)
            .pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112)
            .pack('v', 3)
            .pack('V', 1)
            .pack('v', $orientation)
            .pack('v', 0)
            .pack('V', 0);

        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function fakeWatermarker(): void
    {
        $this->app->instance(EvidenceWatermarker::class, new class extends EvidenceWatermarker
        {
            public function watermark(UploadedFile $photo, string $text): array
            {
                $path = $photo->getRealPath();
                $contents = is_string($path) ? file_get_contents($path) : '';
                $mime = (string) $photo->getMimeType();

                return [
                    'contents' => is_string($contents) ? $contents : '',
                    'mime_type' => $mime,
                    'extension' => match ($mime) {
                        'image/jpeg' => 'jpg',
                        'image/webp' => 'webp',
                        default => 'png',
                    },
                    'size_bytes' => is_string($contents) ? strlen($contents) : 0,
                ];
            }
        });
    }

    private function loginAdmin(): void
    {
        $this->post(route('admin.login'), ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));
    }
}
