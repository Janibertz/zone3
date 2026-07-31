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
                card:  '1rem',
                sheet: '1.5rem',
                field: '0.75rem',
            },

            boxShadow: {
                card:  'var(--z-shadow-card)',
                sheet: 'var(--z-shadow-sheet)',
                bar:   'var(--z-shadow-bar)',
            },

            transitionTimingFunction: {
                // iOS-like easing — quick start, gentle settle
                sheet: 'cubic-bezier(0.32, 0.72, 0, 1)',
            },
        },
    },

    plugins: [forms],
};
