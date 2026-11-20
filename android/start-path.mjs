// Nach `npx cap sync android` aufrufen.
// Capacitor Android hängt appStartPath ohne Schrägstrich an server.url
// (Bridge.java: appUrl += appUrlPath) – aus "https://zone3.run" + "dashboard"
// wurde "https://zone3.rundashboard", die App fand den Server nie.
// iOS fügt den Pfad sauber an und bleibt bei "dashboard"; nur die
// Android-Kopie der Konfiguration bekommt den führenden Schrägstrich.
import { readFileSync, writeFileSync } from 'node:fs';

const file = 'android/app/src/main/assets/capacitor.config.json';
const config = JSON.parse(readFileSync(file, 'utf8'));
const path = config.server?.appStartPath ?? '';
if (path && !path.startsWith('/')) {
    config.server.appStartPath = '/' + path;
}
writeFileSync(file, JSON.stringify(config, null, '\t') + '\n');
console.log('Android startet bei', (config.server?.url ?? '') + (config.server?.appStartPath ?? ''));
