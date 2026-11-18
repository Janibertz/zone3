<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPush;
use App\Models\User;
use App\Services\Admin\AdminPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „Push senden“ (18.11.2026): an alle, an eine Gruppe oder an einen
 * Athleten. Wer Telegram nutzt, bekommt es über Telegram.
 */
class AdminPushController extends Controller
{
    public function __construct(private readonly AdminPushService $push) {}

    public function index(Request $request): Response
    {
        $athletes = User::where('is_active', true)->whereNull('waitlisted_at')
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id'      => $u->id,
                'name'    => $u->name,
                'first'   => preg_split('/\s+/u', trim($u->name))[0] ?? $u->name,
                'channel' => $this->push->channel($u),
                'label'   => $this->push->channelLabel($u),
            ]);

        $groups = collect(AdminPushService::GROUPS)->map(function ($label, $key) {
            $reach = $this->push->reach($this->push->audience('group', $key));

            return ['key' => $key, 'label' => $label] + $reach;
        })->values();

        return Inertia::render('Admin/Push/Index', [
            'athletes' => $athletes,
            'groups'   => $groups,
            'all'      => $this->push->reach($this->push->audience('all')),
            'targets'  => collect(AdminPushService::TARGETS)->map(fn ($l, $u) => ['url' => $u, 'label' => $l])->values(),
            'me'       => $this->push->channelLabel($request->user()),
            'preset'   => $request->integer('user') ?: null,
            'history'  => AdminPush::latest()->limit(15)->get()->map(fn (AdminPush $p) => [
                'id'       => $p->id,
                'title'    => $p->title,
                'audience' => $p->audience_label,
                'at'       => $p->created_at->toIso8601String(),
                'reached'  => $p->reached,
                'telegram' => $p->via_telegram,
                'push'     => $p->via_push,
            ]),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'group', 'user', 'test'])],
            'group'    => ['nullable', 'required_if:audience,group', Rule::in(array_keys(AdminPushService::GROUPS))],
            'user_id'  => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
            'title'    => ['required', 'string', 'max:100'],
            'body'     => ['required', 'string', 'max:500'],
            'url'      => ['required', Rule::in(array_keys(AdminPushService::TARGETS))],
        ]);

        [$users, $label, $target] = match ($data['audience']) {
            'test'  => [collect([$request->user()]), 'Test an mich', $request->user()->id],
            'user'  => [$this->push->audience('user', null, (int) $data['user_id']), User::find($data['user_id'])?->name ?? 'Athlet', (int) $data['user_id']],
            'group' => [$this->push->audience('group', $data['group']), 'Gruppe ' . AdminPushService::GROUPS[$data['group']], null],
            default => [$this->push->audience('all'), 'an alle', null],
        };

        $sent = $this->push->send($users, $data['title'], $data['body'], $data['url'], $data['audience'], $label, $request->user(), $target);

        $how = collect([
            $sent->via_telegram ? "{$sent->via_telegram} über Telegram" : null,
            $sent->via_push ? "{$sent->via_push} per Push" : null,
        ])->filter()->implode(', ');

        return back()->with($sent->reached > 0 ? 'success' : 'error', $sent->reached > 0
            ? "Gesendet: {$how}."
            : 'Niemand erreicht — kein Telegram und kein Push-Gerät.');
    }
}
