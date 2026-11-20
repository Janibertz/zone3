package run.zone3.app;

import android.net.Uri;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.Logger;

/**
 * Wie Capacitors Client, aber die Fehlerseite bekommt den Grund mit
 * (fehler.html?code=…). Capacitor lädt bei einem Fehler nur die nackte
 * Seite, und ohne Gerät am Rechner sieht man sonst nie, woran es lag.
 */
public class ZoneWebViewClient extends BridgeWebViewClient {

    private final Bridge bridge;

    public ZoneWebViewClient(Bridge bridge) {
        super(bridge);
        this.bridge = bridge;
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        if (!request.isForMainFrame()) {
            super.onReceivedError(view, request, error);
            return;
        }
        String code = "net " + error.getErrorCode() + " " + error.getDescription();
        showError(view, code, request.getUrl());
    }

    @Override
    public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
        if (!request.isForMainFrame()) {
            super.onReceivedHttpError(view, request, response);
            return;
        }
        String code = "http " + response.getStatusCode() + " " + response.getReasonPhrase();
        showError(view, code, request.getUrl());
    }

    private void showError(WebView view, String code, Uri url) {
        Logger.error("Zone3 lädt nicht: " + code + " (" + url + ")");
        String errorUrl = bridge.getErrorUrl();
        if (errorUrl == null) {
            return;
        }
        view.loadUrl(
            Uri.parse(errorUrl).buildUpon()
                .appendQueryParameter("code", code)
                .appendQueryParameter("url", String.valueOf(url))
                .build()
                .toString()
        );
    }
}
