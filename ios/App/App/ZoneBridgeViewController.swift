import Capacitor

/// Die Bridge der App mit den eigenen Plugins, die nicht aus npm kommen.
/// Plugins im App-Target findet Capacitor nicht von selbst; sie werden hier
/// angemeldet.
class ZoneBridgeViewController: CAPBridgeViewController {
    override open func capacitorDidLoad() {
        bridge?.registerPluginInstance(WatchWorkoutsPlugin())
        bridge?.registerPluginInstance(AppleSignInPlugin())
    }
}
