import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Semantic colors are driven by CSS variables (see resources/css/app.css).
 * The variables hold bare RGB triplets so Tailwind's opacity modifiers keep
 * working — `bg-surface/60`, `text-ink-3`, `border-line` etc.
 */
const token = (name) => ({ opacityValue }) =>
    opacityValue === undefined
        ? `rgb(var(--z-${name}))`
        : `rgb(var(--z-${name}) / ${opacityValue})`;

/** Accent-style token family: base, soft background, readable ink on soft. */
const family = (name) => ({
    DEFAULT: token(name),
    soft:    token(`${name}-soft`),
    ink:     token(`${name}-ink`),
});

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    /*
     * Jede `hover:`-Klasse wird hinter `@media (hover: hover)` gelegt.
     *
     * Beruehrung kennt kein Hover, also faelscht der Browser eines: der erste
     * Tipp auf ein Element setzt `:hover` und LAESST ES STEHEN, bis woanders
     * getippt wird. Ein Knopf mit `hover:scale-105` bleibt nach dem Antippen
     * vergroessert. Zone3 hat 346 solcher Klassen in 60 Dateien, und Zone3
     * wird als PWA vom iPhone-Homescreen benutzt — das ist der haeufigste
     * „fuehlt sich wie eine Webseite an"-Verraeter der ganzen App.
     *
     * In Tailwind 4 ist das die Vorgabe; in 3.x braucht es dieses Flag.
     * Druckfeedback kommt unabhaengig davon ueber `:active`, das auf jeder
     * Eingabeart funktioniert.
     */
    future: {
        hoverOnlyWhenSupported: true,
    },
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // ── Neutrals ────────────────────────────────────────────
                canvas: token('bg'),               // page background
                surface: {
                    DEFAULT: token('surface'),     // cards, sheets, bars
                    2:       token('surface-2'),   // nested / subtle fills
                    3:       token('surface-3'),   // hover, pressed
                },
                line: {
                    DEFAULT: token('border'),      // hairlines
                    strong:  token('border-strong'),
                },
                ink: {
                    DEFAULT: token('ink'),         // primary text
                    2:       token('ink-2'),       // secondary text
                    3:       token('ink-3'),       // muted / captions
                },

                // ── Accents ─────────────────────────────────────────────
                accent:  family('accent'),
                success: family('success'),
                warn:    family('warn'),
                danger:  family('danger'),
                info:    family('info'),
            },

            borderRadius: {
                card:  '1.25rem',   // 20px — die grosszuegigen Kartenecken
                sheet: '1.75rem',
                field: '0.875rem',
            },

            boxShadow: {
                card:  'var(--z-shadow-card)',
                sheet: 'var(--z-shadow-sheet)',
                bar:   'var(--z-shadow-bar)',
            },

            /*
             * Die eingebauten CSS-Kurven sind zu weich — ihnen fehlt der
             * Anschub, der eine Bewegung absichtlich wirken laesst. Drei
             * Kurven decken alles ab, was Zone3 braucht.
             */
            transitionTimingFunction: {
                // iOS-like easing — quick start, gentle settle. Fuer Sheets
                // und Panels, die aus dem Bildrand kommen.
                sheet: 'cubic-bezier(0.32, 0.72, 0, 1)',

                // Alles, was auftritt oder verschwindet: startet schnell,
                // also sieht der Nutzer die Bewegung im selben Moment, in dem
                // er sie ausgeloest hat. `ease-in` gehoert hier NIE hin — es
                // verzoegert den Anfang, genau den Augenblick, auf den
                // gesehen wird, und laesst dieselben 200 ms langsamer wirken.
                'out-strong': 'cubic-bezier(0.23, 1, 0.32, 1)',

                // Fuer Elemente, die sich AUF dem Schirm bewegen oder
                // umformen — natuerliches Anfahren und Abbremsen.
                'in-out-strong': 'cubic-bezier(0.77, 0, 0.175, 1)',
            },
        },
    },

    plugins: [forms],
};
