import Capacitor
import Foundation
import StoreKit
import UIKit

/// Apples Bewertungsdialog (seit 25.11.2026).
///
/// Die Seite entscheidet, wann gefragt wird (nach der ersten geschafften
/// Planwoche); hier wird nur der Dialog angefordert. Ob er wirklich
/// erscheint, entscheidet iOS: höchstens dreimal in 365 Tagen, und wer
/// in den Einstellungen „In-App-Bewertungen“ ausgeschaltet hat, sieht nichts.
/// Eine Rückmeldung, ob bewertet wurde, gibt Apple bewusst nicht.
@objc(StoreReviewPlugin)
public class StoreReviewPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "StoreReviewPlugin"
    public let jsName = "StoreReview"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "requestReview", returnType: CAPPluginReturnPromise),
    ]

    @objc func requestReview(_ call: CAPPluginCall) {
        DispatchQueue.main.async {
            let scene = UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .first { $0.activationState == .foregroundActive }
            guard let scene else {
                call.reject("keine aktive Szene")
                return
            }
            SKStoreReviewController.requestReview(in: scene)
            call.resolve()
        }
    }
}
