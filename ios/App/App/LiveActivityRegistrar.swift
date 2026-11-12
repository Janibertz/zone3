import Foundation
#if canImport(ActivityKit)
import ActivityKit
#endif

/// Meldet dem Server die Tokens der Live Activity „Heute steht an“.
///
/// Zwei Sorten: das **Start-Token** (ab iOS 17.2), mit dem der Server die
/// Activity per Push startet, auch bei geschlossener App, und je laufender
/// Activity ein **Update-Token**, mit dem er sie auf „Erledigt“ stellt und
/// beendet. Startet der Server sie, weckt iOS die App kurz im Hintergrund,
/// damit sie das Update-Token abholen kann — die Seite (WKWebView) läuft
/// dann nicht. Darum schickt die App die Tokens selbst, an dieselbe
/// Adresse wie das Widget (`/widget/{token}/live-activity`), ohne Session.
///
/// Gibt es die Widget-Adresse noch nicht (App nie geöffnet, abgemeldet),
/// merkt sie sich die Tokens und schickt sie nach `flush()`.
enum LiveActivityRegistrar {
    private static let pendingKey = "zone3.liveActivity.pending"
    private static var started = false

    static func start() {
        #if canImport(ActivityKit)
        guard #available(iOS 17.2, *), !started else { return }
        started = true

        Task {
            for await data in Activity<TrainingActivityAttributes>.pushToStartTokenUpdates {
                send(["kind": "start", "token": hex(data)])
            }
        }
        Task {
            for await activity in Activity<TrainingActivityAttributes>.activityUpdates {
                observe(activity)
            }
        }
        for activity in Activity<TrainingActivityAttributes>.activities {
            observe(activity)
        }
        #endif
    }

    #if canImport(ActivityKit)
    @available(iOS 17.2, *)
    private static func observe(_ activity: Activity<TrainingActivityAttributes>) {
        Task {
            for await data in activity.pushTokenUpdates {
                send([
                    "kind": "update",
                    "token": hex(data),
                    "activity_id": activity.id,
                    "day": activity.attributes.day,
                ])
            }
        }
    }
    #endif

    /// Wenn die Seite die Widget-Adresse neu gesetzt hat.
    static func flush() {
        let pending = UserDefaults.standard.array(forKey: pendingKey) as? [[String: String]] ?? []
        UserDefaults.standard.removeObject(forKey: pendingKey)
        pending.forEach { send($0) }
    }

    private static func endpoint() -> URL? {
        guard let feed = WidgetFeedStore.load(), feed.hasSuffix(".json") else { return nil }
        return URL(string: String(feed.dropLast(5)) + "/live-activity")
    }

    private static func send(_ body: [String: String]) {
        guard let url = endpoint() else {
            remember(body)
            return
        }
        var request = URLRequest(url: url, timeoutInterval: 20)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try? JSONSerialization.data(withJSONObject: body)

        URLSession.shared.dataTask(with: request) { _, response, error in
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            // Netz weg oder Server gestört: beim nächsten Öffnen noch einmal.
            if error != nil || status >= 500 { remember(body) }
        }.resume()
    }

    private static func remember(_ body: [String: String]) {
        var pending = UserDefaults.standard.array(forKey: pendingKey) as? [[String: String]] ?? []
        pending.removeAll { $0["kind"] == body["kind"] && $0["activity_id"] == body["activity_id"] }
        pending.append(body)
        UserDefaults.standard.set(Array(pending.suffix(10)), forKey: pendingKey)
    }

    private static func hex(_ data: Data) -> String {
        data.map { String(format: "%02x", $0) }.joined()
    }
}
