<?php

namespace App\Services\Polar;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Der eine Webhook von Zone3s Polar-Client.
 *
 * Polar gibt den Signaturschlüssel genau einmal heraus, beim Anlegen. Er
 * liegt verschlüsselt in `app_settings`, nicht in der Umgebung — so braucht
 * niemand ihn von Hand einzutragen. `ensure()` läuft stündlich
 * (`polar:webhook`): fehlt der Webhook oder der Schlüssel, wird neu
 * angelegt; ist er nach sieben Tagen Fehlzustellung abgeschaltet, wird er
 * wieder eingeschaltet.
 *
 * Beim Anlegen schickt Polar sofort einen PING an die URL und legt nur an,
 * wenn der mit 200 beantwortet wird. Darum nicht in `startup.sh` — dort läuft
 * der Webserver noch nicht.
 */
class PolarWebhook
{
    public const EVENTS = ['EXERCISE'];

    private const SECRET_KEY = 'polar_webhook_secret';

    public function __construct(private readonly PolarClient $client) {}

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/') . '/polar/webhook';
    }

    public function secret(): ?string
    {
        $stored = AppSetting::get(self::SECRET_KEY);
        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public function verifies(string $body, ?string $signature): bool
    {
        $secret = $this->secret();

        return $secret !== null && $signature !== null
            && hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($signature)));
    }

    /** @return string was getan wurde */
    public function ensure(): string
    {
        $current = $this->client->webhook();

        if ($current && $this->secret() && ($current['url'] ?? null) === $this->url()
            && empty(array_diff(self::EVENTS, (array) ($current['events'] ?? [])))) {
            if (($current['active'] ?? true) === false) {
                $this->client->activateWebhook();

                return 'activated';
            }

            return 'ok';
        }

        if ($current) {
            $this->client->deleteWebhook((string) $current['id']);
        }

        $created = $this->client->createWebhook($this->url(), self::EVENTS);
        AppSetting::set(self::SECRET_KEY, Crypt::encryptString($created['signature_secret_key']));

        Log::info('Polar-Webhook angelegt', ['id' => $created['id'] ?? null, 'url' => $this->url()]);

        return 'created';
    }
}
