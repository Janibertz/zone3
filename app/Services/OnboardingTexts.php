<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Was der Coach im Onboarding sagt — eine Sprechblase je Schritt.
 *
 * Feste Texte statt KI: das Onboarding soll nichts kosten und in jeder
 * Sekunde gleich antworten. Gepflegt unter /admin/onboarding-texts (Jan,
 * 07.11.2026); gespeichert wird nur, was vom Standard abweicht, damit ein
 * neuer Schritt bei allen sofort seinen Text hat.
 *
 * Platzhalter: {name} = Vorname des Athleten, {coach} = Name des Coaches.
 */
class OnboardingTexts
{
    public const SETTING_KEY = 'onboarding.coach_texts';

    public const MAX_LENGTH = 400;

    /** Schlüssel = Schritt im Onboarding, Reihenfolge = Admin-Seite. */
    public const DEFAULTS = [
        'coach'        => ['label' => 'Coach wählen',      'text' => 'Hi {name}, ich bin {coach}. Ich begleite dich durch jedes Training und passe deinen Plan an, wenn das Leben dazwischenkommt.'],
        'profile'      => ['label' => 'Deine Werte',       'text' => 'Schön, dich kennenzulernen! Aus deinem Puls und deiner Schwellenpace rechne ich deine Zonen. Keine Werte zur Hand? Eine aktuelle Laufzeit reicht – nicht die Bestzeit von vor drei Jahren.'],
        'model'        => ['label' => 'Trainingsmodell',   'text' => 'Wie viel darf hart sein? Bei 80/20 läufst du fast alles locker. Norwegisch heißt zwei Schwellentage pro Woche – stark, aber nur mit Erfahrung.'],
        'availability' => ['label' => 'Verfügbarkeit',     'text' => 'Wann hast du Zeit? Ich plane nur an diesen Tagen und nie länger als hier angegeben.'],
        'strength'     => ['label' => 'Kraft & Rumpf',     'text' => 'Zwei kurze Krafteinheiten pro Woche machen dich robuster und schneller. Sag mir, was du zu Hause hast.'],
        'bike'         => ['label' => 'Rad / Zwift',       'text' => 'Fährst du Rad? Dann tausche ich im Winter gern mal einen lockeren Lauf gegen eine Fahrt – das schont die Beine.'],
        'goal'         => ['label' => 'Ziel',              'text' => 'Worauf trainieren wir hin? Mit Rennen und Zielzeit baue ich den Plan rückwärts vom Renntag.'],
        'notify'       => ['label' => 'Benachrichtigungen', 'text' => 'Fast geschafft! Wo soll ich dich erreichen? Morgens deine Einheit, nach dem Lauf mein Feedback – sonst nichts.'],
        'connect'      => ['label' => 'Verbindungen',      'text' => 'Zum Schluss deine Uhr. Dann sehe ich jeden Lauf und gleiche ihn mit dem Plan ab.'],
    ];

    /** @return array<string, string> Schritt → Text (mit Platzhaltern). */
    public static function all(): array
    {
        $saved = json_decode((string) AppSetting::get(self::SETTING_KEY, '{}'), true) ?: [];

        $texts = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = trim((string) ($saved[$key] ?? ''));
            $texts[$key] = $value !== '' ? $value : $default['text'];
        }

        return $texts;
    }

    /**
     * Speichert nur Abweichungen vom Standard. Ein leeres Feld oder der
     * Standardtext selbst heißt „Standard“.
     *
     * @param array<string, ?string> $texts
     */
    public static function save(array $texts): void
    {
        $custom = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = trim((string) ($texts[$key] ?? ''));
            if ($value !== '' && $value !== $default['text']) {
                $custom[$key] = $value;
            }
        }

        AppSetting::set(self::SETTING_KEY, json_encode($custom, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{key: string, label: string, default: string, text: string, custom: bool}> */
    public static function forAdmin(): array
    {
        $saved = json_decode((string) AppSetting::get(self::SETTING_KEY, '{}'), true) ?: [];
        $texts = self::all();

        return array_map(fn ($key) => [
            'key'     => $key,
            'label'   => self::DEFAULTS[$key]['label'],
            'default' => self::DEFAULTS[$key]['text'],
            'text'    => $texts[$key],
            'custom'  => isset($saved[$key]),
        ], array_keys(self::DEFAULTS));
    }
}
