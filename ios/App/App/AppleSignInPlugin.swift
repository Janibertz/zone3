import AuthenticationServices
import Capacitor
import Foundation

/// „Mit Apple fortfahren“ im nativen Sheet (seit 09.11.2026).
///
/// Die Seite holt vorher eine Nonce vom Server (`/auth/apple/nonce`) und
/// gibt sie hier mit; Apple schreibt sie ins id_token, und der Server prüft
/// beides (`AppleSignInController::native`). Hier wird nichts gespeichert.
///
/// Den Namen liefert Apple nur beim allerersten Mal — darum geht er mit.
@objc(AppleSignInPlugin)
public class AppleSignInPlugin: CAPPlugin, CAPBridgedPlugin, ASAuthorizationControllerDelegate, ASAuthorizationControllerPresentationContextProviding {
    public let identifier = "AppleSignInPlugin"
    public let jsName = "AppleSignIn"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "isAvailable", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "authorize", returnType: CAPPluginReturnPromise),
    ]

    private var pending: CAPPluginCall?
    /// Festhalten, bis Apple antwortet — sonst ist der Delegate weg.
    private var controller: ASAuthorizationController?

    /// Ein älterer Build hat das Plugin nicht; die Seite fragt hier zuerst.
    @objc func isAvailable(_ call: CAPPluginCall) {
        call.resolve(["available": true])
    }

    @objc func authorize(_ call: CAPPluginCall) {
        guard let nonce = call.getString("nonce"), !nonce.isEmpty else {
            call.reject("Keine Nonce übergeben.")
            return
        }

        let request = ASAuthorizationAppleIDProvider().createRequest()
        request.requestedScopes = [.fullName, .email]
        request.nonce = nonce

        pending = call
        DispatchQueue.main.async {
            let controller = ASAuthorizationController(authorizationRequests: [request])
            controller.delegate = self
            controller.presentationContextProvider = self
            self.controller = controller
            controller.performRequests()
        }
    }

    public func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        bridge?.webView?.window ?? ASPresentationAnchor()
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        self.controller = nil
        guard let call = pending else { return }
        pending = nil

        guard let credential = authorization.credential as? ASAuthorizationAppleIDCredential,
              let tokenData = credential.identityToken,
              let token = String(data: tokenData, encoding: .utf8) else {
            call.reject("Apple hat kein Token geliefert.")
            return
        }

        call.resolve([
            "identityToken": token,
            "givenName": credential.fullName?.givenName ?? "",
            "familyName": credential.fullName?.familyName ?? "",
        ])
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        self.controller = nil
        guard let call = pending else { return }
        pending = nil

        if let e = error as? ASAuthorizationError, e.code == .canceled {
            call.resolve(["cancelled": true])
            return
        }
        call.reject(error.localizedDescription)
    }
}
