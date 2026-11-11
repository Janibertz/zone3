import Foundation
import Security

/// Die Adresse, unter der das Widget seine Daten holt
/// (`/widget/{token}.json`, `App\Http\Controllers\WidgetController`).
///
/// App und Widget laufen als getrennte Prozesse mit getrennten Speichern.
/// Gemeinsam haben sie nur, was beide Berechtigungen nennen — hier eine
/// Gruppe im Schlüsselbund (`keychain-access-groups`). Die braucht anders
/// als eine App Group keine Freischaltung im Developer-Portal.
///
/// Diese Datei gehört zu beiden Targets (App und Zone3Widget).
enum WidgetFeedStore {
    private static let service = "run.zone3.widget"
    private static let account = "feed-url"

    /// „TEAMID.run.zone3.shared“ — die Team-ID steht über
    /// `$(AppIdentifierPrefix)` in der Info.plist beider Targets.
    private static var accessGroup: String? {
        guard let prefix = Bundle.main.object(forInfoDictionaryKey: "AppIdentifierPrefix") as? String,
              !prefix.isEmpty, !prefix.hasPrefix("$") else { return nil }
        return prefix + "run.zone3.shared"
    }

    private static func query() -> [String: Any] {
        var q: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        if let group = accessGroup {
            q[kSecAttrAccessGroup as String] = group
        }
        return q
    }

    @discardableResult
    static func save(_ url: String) -> Bool {
        let data = Data(url.utf8)
        let update: [String: Any] = [kSecValueData as String: data]
        let status = SecItemUpdate(query() as CFDictionary, update as CFDictionary)
        if status == errSecSuccess { return true }

        var add = query()
        add[kSecValueData as String] = data
        // Das Widget liest auch, wenn das iPhone gesperrt ist — nach dem
        // ersten Entsperren seit dem Start.
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
        return SecItemAdd(add as CFDictionary, nil) == errSecSuccess
    }

    static func load() -> String? {
        var q = query()
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        var item: CFTypeRef?
        guard SecItemCopyMatching(q as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    static func clear() {
        SecItemDelete(query() as CFDictionary)
    }
}
