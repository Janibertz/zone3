/**
 * Findet Bezeichner, die im Template stehen, aber im <script setup> fehlen.
 *
 * Vue meldet so etwas nicht beim Bauen — die Seite baut sauber durch und
 * wirft den Fehler erst zur Laufzeit, im Browser, beim Nutzer. Beim Umbau
 * des Dashboards ist genau das einmal passiert: eine Funktion fiel beim
 * Entfernen von totem Code mit weg, der Build blieb gruen.
 *
 * Wie es funktioniert: Was der SFC-Compiler nicht als Setup-Binding
 * aufloesen kann, landet im erzeugten Code als `_ctx.<name>`.
 *
 * Aufruf ohne Argumente prueft alle .vue-Dateien unter resources/js,
 * sonst nur die genannten Dateien.
 */
import { readFileSync, readdirSync, statSync } from 'fs';
import { join } from 'path';
import { parse, compileScript } from 'vue/compiler-sfc';

/** Globale, die zur Laufzeit da sind und nicht im Script stehen muessen. */
const GLOBALS = new Set([
    'route',        // Ziggy
    '$page',        // Inertia
    '$slots', '$props', '$attrs', '$emit', '$el',
]);

function vueFilesIn(dir) {
    return readdirSync(dir).flatMap((entry) => {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) return vueFilesIn(full);
        return full.endsWith('.vue') ? [full] : [];
    });
}

const files = process.argv.length > 2
    ? process.argv.slice(2)
    : vueFilesIn('resources/js');

const broken = [];

for (const file of files) {
    const source = readFileSync(file, 'utf8');
    const { descriptor } = parse(source, { filename: file });

    if (!descriptor.scriptSetup) continue;

    const compiled = compileScript(descriptor, { id: file, inlineTemplate: true });

    const unresolved = [...new Set(
        [...compiled.content.matchAll(/_ctx\.([A-Za-z_$][\w$]*)/g)].map((m) => m[1])
    )].filter((name) => !GLOBALS.has(name));

    if (unresolved.length) {
        broken.push({ file, unresolved });
    }
}

if (broken.length === 0) {
    console.log(`${files.length} Datei(en) geprüft — alle Bindings aufgelöst.`);
    process.exit(0);
}

for (const { file, unresolved } of broken) {
    console.error(`\nFEHLENDE BINDINGS  ${file}`);
    unresolved.forEach((name) => console.error(`      -> ${name}`));
}

console.error(`\n${broken.length} Datei(en) mit fehlenden Bindings.`);
process.exit(1);
