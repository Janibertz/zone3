<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\InstagramPublication;
use App\Models\User;
use App\Services\Admin\AdminPushService;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Instagram\InstagramSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramPublishTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-29 12:01', 'Europe/Berlin'));
        config([
            'services.instagram.account_id'   => '1789',
            'services.instagram.access_token' => 'env-token',
            'services.instagram.api_url'      => 'https://graph.instagram.com',
        ]);
        $this->dir = sys_get_temp_dir() . '/ig-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function write(string $slug, array $data): void
    {
        file_put_contents("{$this->dir}/{$slug}.json", json_encode($data));
    }

    private function publisher(): InstagramPublisher
    {
        return new InstagramPublisher(app(InstagramClient::class), new InstagramSchedule($this->dir), app(AdminPushService::class));
    }

    public function test_a_manual_story_reminds_the_admins_even_without_instagram_access(): void
    {
        config(['services.instagram.access_token' => null]);
        $this->write('story-link', [
            'at' => '2026-11-29 12:00', 'type' => 'manual_story', 'media' => ['https://zone3.run/s.jpg'],
            'sticker' => ['type' => 'link', 'text' => 'Zone3 laden', 'url' => 'https://apps.apple.com/app/zone3/id6832175374'],
        ]);
        $this->write('reel', ['at' => '2026-11-29 12:00', 'type' => 'reel', 'media' => ['https://zone3.run/r.mp4']]);
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create();

        $push = $this->mock(AdminPushService::class);
        $push->shouldReceive('notify')->once()
            ->withArgs(fn ($u, $title, $body, $url) => $u->is($admin) && str_contains($body, 'Link-Sticker') && $url === '/admin/instagram/story-link');

        Http::fake();
        $this->assertSame(['story-link' => 'benachrichtigt'], $this->publisher()->run());
        $this->assertSame([], $this->publisher()->run());
        Http::assertNothingSent();

        $this->app->instance(InstagramSchedule::class, new InstagramSchedule($this->dir));
        $this->actingAs($admin)->get('/admin/instagram/story-link')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Instagram/Show')
                ->where('post.sticker.url', 'https://apps.apple.com/app/zone3/id6832175374')
                ->where('post.status', 'notified'));
        $this->actingAs($admin)->get('/admin/instagram')->assertOk();

        $this->actingAs($admin)->post('/admin/instagram/story-link/posted')->assertRedirect();
        $this->assertSame(InstagramPublication::POSTED, InstagramPublication::where('slug', 'story-link')->value('status'));

        $this->actingAs(User::factory()->create())->get('/admin/instagram/story-link')->assertForbidden();
    }

    public function test_a_due_trial_reel_is_created_and_published_once_instagram_is_done(): void
    {
        $this->write('trial-a', [
            'at' => '2026-11-29 12:00', 'type' => 'trial_reel',
            'media' => ['https://zone3.run/social/trial-a.mp4'],
            'cover' => 'https://zone3.run/social/cover-a.jpg', 'caption' => 'Hallo',
        ]);

        $status = 'IN_PROGRESS';
        Http::fake(function (Request $r) use (&$status) {
            return match (true) {
                str_ends_with($r->url(), '/1789/media')         => Http::response(['id' => 'c1']),
                str_contains($r->url(), '/c1?')                 => Http::response(['status_code' => $status]),
                str_ends_with($r->url(), '/1789/media_publish') => Http::response(['id' => 'm1']),
                str_contains($r->url(), '/m1?')                 => Http::response(['permalink' => 'https://www.instagram.com/reel/x/']),
            };
        });

        $this->assertSame(['trial-a' => 'wartet'], $this->publisher()->run());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1789/media')
            && $r['media_type'] === 'REELS'
            && $r['trial_params'] === '{"graduation_strategy":"MANUAL"}'
            && $r['cover_url'] === 'https://zone3.run/social/cover-a.jpg'
            && ! isset($r['share_to_feed'])
            && $r['access_token'] === 'env-token');

        $status = 'FINISHED';
        $this->assertSame(['trial-a' => 'veröffentlicht'], $this->publisher()->run());

        $row = InstagramPublication::where('slug', 'trial-a')->first();
        $this->assertSame(InstagramPublication::PUBLISHED, $row->status);
        $this->assertSame('https://www.instagram.com/reel/x/', $row->permalink);

        // Ein weiterer Lauf fasst den Beitrag nicht mehr an.
        $this->assertSame([], $this->publisher()->run());
        Http::assertSentCount(5);
    }

    public function test_a_carousel_creates_its_items_first(): void
    {
        $this->write('karussell', [
            'at' => '2026-11-29 11:30', 'type' => 'carousel', 'caption' => 'Text',
            'media' => ['https://zone3.run/a.jpg', 'https://zone3.run/b.jpg'],
        ]);

        $n = 0;
        Http::fake(function (Request $r) use (&$n) {
            if (str_ends_with($r->url(), '/1789/media')) {
                return Http::response(['id' => 'c' . (++$n)]);
            }
            if (str_ends_with($r->url(), '/media_publish')) {
                return Http::response(['id' => 'm1']);
            }

            return Http::response(str_contains($r->url(), 'permalink') ? ['permalink' => 'p'] : ['status_code' => 'FINISHED']);
        });

        $this->assertSame(['karussell' => 'veröffentlicht'], $this->publisher()->run());
        Http::assertSent(fn (Request $r) => ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === 'c1,c2');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish') && $r['creation_id'] === 'c3');
    }

    public function test_future_posts_wait_and_old_ones_are_not_caught_up(): void
    {
        $this->write('morgen', ['at' => '2026-11-30 10:00', 'type' => 'image', 'media' => ['https://zone3.run/a.jpg']]);
        $this->write('gestern', ['at' => '2026-11-28 10:00', 'type' => 'image', 'media' => ['https://zone3.run/a.jpg']]);
        Http::fake();

        $this->assertSame(['gestern' => 'verpasst'], $this->publisher()->run());
        Http::assertNothingSent();
        $this->assertNull(InstagramPublication::where('slug', 'morgen')->first());
    }

    public function test_an_error_is_recorded_and_not_retried(): void
    {
        $this->write('story', ['at' => '2026-11-29 12:00', 'type' => 'story', 'media' => ['https://zone3.run/s.jpg']]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid image']], 400)]);

        $this->assertSame(['story' => 'fehlgeschlagen'], $this->publisher()->run());
        $row = InstagramPublication::where('slug', 'story')->first();
        $this->assertSame(InstagramPublication::FAILED, $row->status);
        $this->assertStringContainsString('Invalid image', $row->error);

        $this->assertSame([], $this->publisher()->run());
        Http::assertSentCount(1);
    }

    public function test_a_failed_post_can_be_retried_by_hand(): void
    {
        $this->write('story', ['at' => '2026-11-29 12:00', 'type' => 'story', 'media' => ['https://zone3.run/s.jpg']]);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Cannot parse access token']], 400)
            ->push(['id' => 'c1'])
            ->push(['status_code' => 'IN_PROGRESS']);
        $this->assertSame(['story' => 'fehlgeschlagen'], $this->publisher()->run());

        $post = (new InstagramSchedule($this->dir))->find('story');
        $this->publisher()->retry($post);

        $row = InstagramPublication::where('slug', 'story')->first();
        $this->assertSame(InstagramPublication::WAITING, $row->status);
        $this->assertSame('c1', $row->container_id);
    }

    public function test_a_facebook_token_goes_through_graph_facebook_com(): void
    {
        config(['services.instagram.access_token' => 'EAAxyz']);
        $this->write('story', ['at' => '2026-11-29 12:00', 'type' => 'story', 'media' => ['https://zone3.run/s.jpg']]);
        Http::fake(['*' => Http::response(['id' => 'c1', 'status_code' => 'IN_PROGRESS'])]);

        $this->publisher()->run();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v21.0/1789/media'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.instagram.com'));
        $this->assertSame(0, app(InstagramClient::class)->refreshToken());
    }

    public function test_a_pasted_token_is_cleaned(): void
    {
        $this->assertSame('IGAAabc', InstagramClient::clean(" \"IGAAabc\"\n"));
        $this->assertSame('IGAAabc', InstagramClient::clean('Bearer IGAAabc'));
        $this->assertNull(InstagramClient::clean('  '));
    }

    public function test_the_refreshed_token_is_stored_and_used(): void
    {
        Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'neu', 'expires_in' => 5184000])]);

        $this->artisan('instagram:refresh-token')->assertSuccessful();

        $this->assertSame('neu', Crypt::decryptString(AppSetting::get(InstagramClient::TOKEN_KEY)));
        $this->assertSame('neu', app(InstagramClient::class)->token());
    }

    public function test_the_committed_posts_are_valid(): void
    {
        $this->addToAssertionCount(1);
        foreach ((new InstagramSchedule())->all() as $post) {
            $this->assertContains($post['type'], InstagramSchedule::TYPES);
            foreach (array_merge($post['media'], array_filter([$post['cover']])) as $url) {
                $this->assertStringStartsWith('https://zone3.run/', $url);
                $this->assertFileExists(public_path(parse_url($url, PHP_URL_PATH)), "{$post['slug']}: {$url} fehlt in public/");
            }
        }
    }
}
