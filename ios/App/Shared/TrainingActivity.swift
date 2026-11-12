#if canImport(ActivityKit)
import ActivityKit
import Foundation

/// Die Live Activity „Heute steht an“ (Mockup „Zone3 Live Activities“,
/// Jan am 12.11.2026). Gestartet, aktualisiert und beendet wird sie nur vom
/// Server per APNs (`App\Services\LiveActivity\LiveActivityService`); die
/// App meldet ihm dafür die Tokens (`LiveActivityRegistrar`).
///
/// Die Feldnamen sind ein Vertrag mit dem Server: iOS dekodiert
/// `content-state` und `attributes` aus dem Push selbst, mit den
/// Standard-Schlüsseln. Wer hier etwas umbenennt, ändert es dort mit.
///
/// Diese Datei gehört zu beiden Targets (App und Zone3Widget).
@available(iOS 16.1, *)
struct TrainingActivityAttributes: ActivityAttributes {
    public struct ContentState: Codable, Hashable {
        /// Alle Einheiten des Tages erledigt.
        var done: Bool
        /// Kurz für die Insel: „Tempo“, „Kraft“, „Rad“.
        var label: String
        /// Lang für den Chip: „Tempolauf“.
        var name: String
        var accent: String
        /// „2–4“ oder nil.
        var zone: String?
        var title: String
        var metrics: [Metric]
        var band: [Segment]
        var foot: String?
        /// Ganze Adresse, die ein Tipp öffnet.
        var url: String
    }

    struct Metric: Codable, Hashable {
        var v: String
        var u: String
    }

    struct Segment: Codable, Hashable {
        var f: Double
        var z: Int
        var w: Bool
    }

    /// Der Tag (YYYY-MM-DD), für den sie gilt.
    var day: String
}
#endif
