import Capacitor
import Foundation
import WidgetKit

/// Verbindet die Seite (zone3.run) mit dem Widget auf dem Homescreen.
///
/// Die Seite holt beim Öffnen den Widget-Token (`POST /widget/token`) und
/// gibt die Adresse hierher; das Widget liest sie aus dem geteilten
/// Schlüsselbund und lädt seine Daten danach selbst, auch bei geschlossener
/// App. `reload` sagt iOS, dass sich etwas geändert hat — aus der App im
/// Vordergrund zählt das nicht gegen das Tageskontingent des Widgets.
@objc(ZoneWidgetPlugin)
public class ZoneWidgetPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "ZoneWidgetPlugin"
    public let jsName = "ZoneWidget"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "setFeed", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "reload", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "clear", returnType: CAPPluginReturnPromise),
    ]

    @objc func setFeed(_ call: CAPPluginCall) {
        guard let url = call.getString("url"), url.hasPrefix("https://") else {
            call.reject("url fehlt")
            return
        }
        let changed = WidgetFeedStore.load() != url
        let saved = changed ? WidgetFeedStore.save(url) : true
        WidgetCenter.shared.reloadAllTimelines()
        call.resolve(["saved": saved, "changed": changed])
    }

    @objc func reload(_ call: CAPPluginCall) {
        WidgetCenter.shared.reloadAllTimelines()
        call.resolve()
    }

    /// Beim Abmelden: das Widget soll niemandem mehr die Einheiten zeigen.
    @objc func clear(_ call: CAPPluginCall) {
        WidgetFeedStore.clear()
        WidgetCenter.shared.reloadAllTimelines()
        call.resolve()
    }
}
