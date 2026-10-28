<?php

namespace App\Services;

/**
 * Die Module der Übersicht, die ein Athlet selbst anordnet und ausblendet.
 *
 * Fest bleiben „Heute" und die Karten, die eine Antwort verlangen
 * (Zielprüfung, Wochenfrage, Coach-Vorschlag, Wiedereinstieg, „Wie lief's?").
 * Sie stehen deshalb nicht in dieser Liste.
 *
 * Gespeichert wird `users.dashboard_layout` als `{order: [...], hidden: [...]}`.
 * Die Liste unten ist die Vorgabe und zugleich die Wahrheit darüber, welche
 * Module es gibt: ein unbekannter Schlüssel fällt beim Lesen weg, ein neues
 * Modul erscheint bei allen — auch bei denen, die schon umsortiert haben.
 */
class DashboardLayout
{
    /**
     * „Monat“, „Neues Ziel“ und die Kurzinfo-Kacheln (Streak · Form · Tagesform) sind seit dem Redesign vom 28.10.2026 weg:
     * der Monat ist der Kalender-Tab, ein neues Rennen legt man unter Rennen an, und
     * Form und Tagesform stehen schon in ihren eigenen Modulen.
     * Gespeicherte Schlüssel fallen beim Lesen von selbst weg.
     *
     * Reihenfolge = Vorgabe. `wide`: ab lg über die ganze Breite, sonst
     * liegen zwei (ab 2xl drei) Module nebeneinander.
     */
    public const MODULES = [
        'woche'        => ['label' => 'Woche',                      'wide' => true],
        'koerper'      => ['label' => 'Körper',                     'wide' => true],
        'form'         => ['label' => 'Form (Belastung)',           'wide' => false],
        'intensitaet'  => ['label' => 'Locker / Hart',              'wide' => false],
        'tempo'        => ['label' => 'Tempo & Prognose',           'wide' => false],
        'rueckblick'   => ['label' => 'Wochenrückblick',            'wide' => false],
        'erholung'     => ['label' => 'Erholung',                   'wide' => true],
        'aktivitaeten' => ['label' => 'Aktivitäten',                'wide' => true],
        'events'       => ['label' => 'Events',                     'wide' => false],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    /**
     * Die vollständige Liste in der Reihenfolge des Athleten.
     *
     * @return list<array{key: string, label: string, wide: bool, hidden: bool}>
     */
    public static function resolve(?array $stored): array
    {
        $hidden = array_values(array_intersect(self::keys(), (array) ($stored['hidden'] ?? [])));

        return array_map(fn (string $key) => [
            'key'    => $key,
            'label'  => self::MODULES[$key]['label'],
            'wide'   => self::MODULES[$key]['wide'],
            'hidden' => in_array($key, $hidden, true),
        ], self::order($stored['order'] ?? []));
    }

    /**
     * Gespeicherte Reihenfolge, bereinigt. Fehlt ein Modul (weil es neu ist),
     * steht es hinter seinem Vorgänger aus der Vorgabe — nicht am Ende, wo
     * es niemand bemerkt, und nicht oben, wo es die Anordnung umwirft.
     *
     * @return list<string>
     */
    public static function order(array $stored): array
    {
        $keys  = self::keys();
        $order = array_values(array_unique(array_values(array_filter(
            $stored,
            fn ($k) => is_string($k) && in_array($k, $keys, true),
        ))));

        foreach ($keys as $i => $key) {
            if (in_array($key, $order, true)) {
                continue;
            }

            $at = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $pos = array_search($keys[$j], $order, true);
                if ($pos !== false) {
                    $at = $pos + 1;
                    break;
                }
            }
            array_splice($order, $at, 0, [$key]);
        }

        return $order;
    }
}
