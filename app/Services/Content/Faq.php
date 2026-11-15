<?php

namespace App\Services\Content;

/**
 * Häufige Fragen (/faq). Antworten dürfen einfaches HTML tragen (Links, <strong>);
 * für das strukturierte FAQPage-Markup wird es entfernt.
 *
 * Was hier über Daten steht, muss zur Datenschutzseite passen (Privacy.vue).
 * Wer dort etwas ändert, liest diese Antworten mit. WHOOP steht hier bewusst
 * nicht: ohne schriftliche Zustimmung keine öffentliche Aussage (API Terms 5.2).
 */
class Faq
{
    public static function groups(): array
    {
        return [
            [
                'id' => 'zone3',
                'title' => 'Zone3',
                'items' => [
                    ['q' => 'Was ist Zone3?',
                     'a' => 'Ein Trainingscoach fürs Laufen. Zone3 baut dir einen Plan für dein Ziel aus deinen eigenen Läufen, deiner Zeit und deiner Form, und passt ihn an, wenn das Leben dazwischenkommt. Der Coach wertet deine Einheiten aus und antwortet dir im Chat oder über Telegram.'],
                    ['q' => 'Für wen ist Zone3 gedacht?',
                     'a' => 'Für alle, die regelmäßig laufen wollen: von den ersten 5 km bis zur neuen Bestzeit im Halbmarathon oder Marathon. Der Plan richtet sich nach dem, was du heute kannst und wie viel Zeit du hast, nicht nach einer Tabelle.'],
                    ['q' => 'Was kostet Zone3?',
                     'a' => 'Zone3 ist ein junges Projekt und zurzeit kostenlos. Ein Abo kann später kommen. Wenn es so weit ist, sagen wir das offen und vorher an.'],
                    ['q' => 'Wie komme ich rein?',
                     'a' => 'Über <a href="/login?ref=faq">zone3.run</a> mit Apple oder mit einem Link an deine E-Mail-Adresse, ein Passwort brauchst du nicht. Neue Konten landen zuerst auf einer Warteliste, wir schalten dich dann frei. Mit dem Einladungslink eines Freundes bist du sofort drin.'],
                    ['q' => 'Gibt es eine App?',
                     'a' => 'Zone3 läuft im Browser. Auf dem Handy legst du es auf den Homescreen und nutzt es wie eine App. Eine eigene iPhone-App ist auf dem Weg, sie bringt Apple Health, die Apple Watch und Widgets mit.'],
                ],
            ],
            [
                'id' => 'training',
                'title' => 'Training und Plan',
                'items' => [
                    ['q' => 'Wie entsteht mein Plan?',
                     'a' => 'Aus deinen Läufen der letzten Wochen, deinem Ziel und den Tagen, an denen du Zeit hast. Wie die Woche aussieht, legen feste Trainingsregeln fest: wie viel du steigern darfst, wann eine leichtere Woche kommt, wie lang der lange Lauf wird. Die KI wählt darin die passenden Einheiten aus und erklärt sie. Was sie schreibt, prüft Zone3 gegen diese Regeln, bevor es in deinen Plan kommt.'],
                    ['q' => 'Brauche ich ein Rennen als Ziel?',
                     'a' => 'Nein. Ohne Rennen wählst du eine Richtung: Grundlage aufbauen, schneller werden über 5 oder 10 km oder Form halten. Zone3 plant dann in Blöcken von zwölf Wochen, auf Wunsch mit einem 5-km-Test am Ende. Trägst du später ein Rennen ein, übernimmt der Rennplan ab dem nächsten Montag.'],
                    ['q' => 'Was passiert, wenn ich krank bin oder keine Zeit habe?',
                     'a' => 'Sag es dem Coach oder trag es in deiner Tagesform ein. Krank heißt Ruhetag, danach steigst du behutsam wieder ein. Fällt eine Einheit aus, rechnet Zone3 die Woche neu. Wie viel Zeit du hast, kannst du jede Woche neu angeben.'],
                    ['q' => 'Ändert der Coach meinen Plan einfach?',
                     'a' => 'Nur, wenn du ihn darum bittest. Hat er selbst eine Idee, zum Beispiel nach einer schlechten Nacht, schlägt er sie vor, und du nimmst sie an oder lehnst sie ab.'],
                    ['q' => 'Geht es auch ohne Uhr?',
                     'a' => 'Ja. Plan, Coach und Tagesform funktionieren auch so, erledigte Einheiten hakst du im Plan ab. Ältere Läufe kannst du als FIT-Datei hochladen. Mit einer Uhr wird es genauer, weil Zone3 dann Pace, Puls und Erholung sieht.'],
                ],
            ],
            [
                'id' => 'geraete',
                'title' => 'Uhren und Apps',
                'items' => [
                    ['q' => 'Welche Uhren und Apps funktionieren?',
                     'a' => 'Garmin, COROS und Polar, die Apple Watch und Apple Health über die iPhone-App, Zwift über intervals.icu und jede Uhr, die FIT-Dateien schreibt. Den Coach erreichst du auch über Telegram, deinen Plan kannst du als Kalender abonnieren.'],
                    ['q' => 'Kommen die Einheiten auf meine Uhr?',
                     'a' => 'Bei Garmin automatisch für die nächsten sieben Tage, bei COROS für heute und morgen, auf der Apple Watch über die iPhone-App. Radeinheiten landen über intervals.icu in Zwift. Polar liefert nur Daten an Zone3, Einheiten kann Zone3 dort nicht hinschicken.'],
                ],
            ],
            [
                'id' => 'daten',
                'title' => 'Deine Daten',
                'items' => [
                    ['q' => 'Welche Daten liest Zone3?',
                     'a' => 'Was es für deinen Plan braucht: deine Trainings (Strecke, Dauer, Pace, Puls, Runden) und, wenn du es erlaubst, deine Erholung von der Uhr (Schlaf, HRV, Ruhepuls). Dazu deine Angaben im Profil, deine Tagesform und deine Nachrichten an den Coach. Zyklusdaten, Gewicht und Kontakte liest Zone3 nie.'],
                    ['q' => 'Wozu braucht Zone3 meine Gesundheitswerte?',
                     'a' => 'Damit der Plan zu deinem Körper passt. Sinkt deine HRV über Tage oder steigt dein Ruhepuls, schlägt der Coach eher eine leichtere Einheit vor oder zieht die Entlastungswoche vor. Gesundheitswerte überträgt Zone3 nur mit deiner ausdrücklichen Einwilligung beim Verbinden.'],
                    ['q' => 'Wer bekommt meine Daten?',
                     'a' => 'Für Plan, Auswertungen und Antworten des Coachs schickt Zone3 den Ausschnitt deiner Daten, den die Aufgabe braucht, an OpenAI. OpenAI verarbeitet sie in unserem Auftrag, dafür gibt es einen Vertrag nach Art. 28 DSGVO. E-Mail-Adresse und Passwort gehen nicht mit. Die übrigen Dienste, etwa die Uhrenhersteller, die du selbst verbindest, stehen vollständig in der <a href="/privacy">Datenschutzerklärung</a>.'],
                    ['q' => 'Trainiert OpenAI seine KI mit meinen Daten?',
                     'a' => 'Nein. Zone3 hat das Teilen von Daten mit OpenAI zu Trainingszwecken abgeschaltet. Deine Daten werden weder von uns noch von OpenAI zum Training von KI-Modellen verwendet.'],
                    ['q' => 'Wo liegen meine Daten?',
                     'a' => 'Auf einem Server von netcup in Deutschland. Dort laufen Anwendung und Datenbank.'],
                    ['q' => 'Verkauft Zone3 Daten oder zeigt Werbung?',
                     'a' => 'Nein. Keine Werbung, kein Verkauf von Daten, keine Tracking- oder Analysedienste. Andere Nutzer sehen deine Trainings nicht, außer du teilst selbst etwas, etwa einen Live-Tracking-Link oder ein Workout.'],
                    ['q' => 'Wie trenne ich eine Uhr oder nehme meine Einwilligung zurück?',
                     'a' => 'Unter Profil → Verbindungen trennst du jede Verbindung mit einem Tipp. Damit endet auch deine Einwilligung, und Zone3 löscht die Erholungswerte, die von dort kamen. Ob übernommene Trainings bleiben, hängt vom Anbieter ab, das steht in der <a href="/privacy">Datenschutzerklärung</a>.'],
                    ['q' => 'Wie lösche ich mein Konto?',
                     'a' => 'Unter Profil → Konto → Konto löschen. Alle deine Daten werden sofort und endgültig gelöscht, auch die Protokolle der KI-Anfragen. Verbundene Uhren meldet Zone3 vorher ab.'],
                ],
            ],
        ];
    }

    /** schema.org FAQPage für Suchmaschinen. */
    public static function structuredData(): array
    {
        $entities = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $entities[] = [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($item['a'])],
                ];
            }
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities, 'inLanguage' => 'de'];
    }
}
