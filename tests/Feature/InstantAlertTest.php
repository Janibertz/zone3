<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Observability\InstantAlert;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/** Gescheiterte Aufgaben und Fehler im Log kommen sofort, höchstens einmal pro Stunde. */
class InstantAlertTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['observability.alerts.instant' => true]);
        Cache::forget(InstantAlert::SLOT_KEY);

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $url) {
            $this->sent[] = compact('title', 'body', 'url') + ['user' => $user->id];
        });
        $this->app->instance(WebPushService::class, $push);
    }

    public function test_an_error_in_the_log_reaches_the_admins_once_an_hour(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create();

        Log::error("Undefined array key 0\n#0 Stacktrace");
        Log::error('Noch ein Fehler');
        Log::warning('Nur eine Warnung');

        $this->assertCount(1, $this->sent);
        $this->assertSame($admin->id, $this->sent[0]['user']);
        $this->assertStringContainsString('Undefined array key 0', $this->sent[0]['body']);
        $this->assertStringNotContainsString('Stacktrace', $this->sent[0]['body']);

        $this->travel(61)->minutes();
        Log::critical('Eine Stunde später');
        $this->assertCount(2, $this->sent);
    }

    public function test_a_failed_job_names_the_job(): void
    {
        User::factory()->create(['is_admin' => true]);

        app(InstantAlert::class)->jobFailed('App\\Jobs\\RegeneratePlanJob', new \RuntimeException('kaputt'));

        $this->assertStringContainsString('RegeneratePlanJob', $this->sent[0]['body']);
        $this->assertStringContainsString('kaputt', $this->sent[0]['body']);
    }

    public function test_a_warning_and_a_switched_off_alert_stay_quiet(): void
    {
        User::factory()->create(['is_admin' => true]);

        Log::warning('Nur eine Warnung');
        config(['observability.alerts.instant' => false]);
        Log::error('Abgeschaltet');

        $this->assertSame([], $this->sent);
    }
}
