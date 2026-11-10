<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';
import { LEGAL } from '@/legal';

/**
 * Impressum nach § 5 DDG und § 18 Abs. 2 MStV (/impressum), öffentlich.
 * Das Kontaktformular ist der zweite schnelle Kontaktweg neben der E-Mail.
 */
const page  = usePage();
const flash = computed(() => page.props.flash ?? {});
const user  = computed(() => page.props.auth?.user ?? null);

const form = useForm({
    name:    user.value?.name ?? '',
    email:   user.value?.email ?? '',
    message: '',
    website: '', // Honigtopf, für Menschen unsichtbar
});

function send() {
    form.post(route('contact.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    });
}

const field = 'mt-1 block w-full rounded-field border-0 bg-surface-2 px-3.5 py-3 text-[16px] text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent';
</script>

<template>
    <Head title="Impressum – Zone3" />

    <div class="dark min-h-screen overflow-x-hidden bg-canvas text-ink">

        <nav class="border-b border-line">
            <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-5">
                <Link href="/" class="flex items-center gap-2">
                    <ZoneIcon class="h-8 w-8" />
                    <Wordmark class="h-[19px] text-ink" />
                </Link>
                <Link href="/login" class="text-sm text-ink-2 transition hover:text-ink active:opacity-60">Anmelden</Link>
            </div>
        </nav>

        <main class="mx-auto max-w-3xl space-y-10 px-5 py-10 text-[15px] leading-relaxed text-ink-2">

            <header>
                <h1 class="text-[34px] font-bold leading-[41px] tracking-tight text-ink">Impressum</h1>
            </header>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">Angaben gemäß § 5 DDG</h2>
                <p class="select-text">
                    {{ LEGAL.name }}<br>
                    <template v-for="line in LEGAL.address" :key="line">{{ line }}<br></template>
                </p>
                <p class="mt-3">Zone3 ist ein privat betriebenes Projekt und befindet sich in einer kostenlosen Beta.</p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">Kontakt</h2>
                <p>
                    E-Mail: <a :href="`mailto:${LEGAL.email}`" class="select-text text-accent">{{ LEGAL.email }}</a><br>
                    Oder über das Kontaktformular unten.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
                <p>{{ LEGAL.name }}, Anschrift wie oben.</p>
            </section>

            <section>
                <h2 class="mb-3 text-[22px] font-bold text-ink">Verbraucherstreitbeilegung</h2>
                <p>
                    Ich bin nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer
                    Verbraucherschlichtungsstelle teilzunehmen.
                </p>
            </section>

            <section id="kontakt" class="rounded-card bg-surface p-5">
                <h2 class="mb-1 text-[22px] font-bold text-ink">Kontaktformular</h2>
                <p class="text-ink-3">Ich antworte per Mail, in der Regel innerhalb von 24 bis 48 Stunden.</p>

                <div v-if="flash.success" class="mt-4 rounded-field bg-success-soft px-4 py-3 text-[15px] text-success-ink">
                    {{ flash.success }}
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="send">
                    <label class="block">
                        <span class="text-[13px] font-semibold text-ink-2">Name (optional)</span>
                        <input v-model="form.name" type="text" autocomplete="name" maxlength="120" :class="field" />
                    </label>
                    <label class="block">
                        <span class="text-[13px] font-semibold text-ink-2">E-Mail</span>
                        <input v-model="form.email" type="email" autocomplete="email" required maxlength="255" :class="field" />
                        <span v-if="form.errors.email" class="mt-1 block text-[13px] text-danger">{{ form.errors.email }}</span>
                    </label>
                    <label class="block">
                        <span class="text-[13px] font-semibold text-ink-2">Nachricht</span>
                        <textarea v-model="form.message" rows="6" required minlength="10" maxlength="5000" :class="field" />
                        <span v-if="form.errors.message" class="mt-1 block text-[13px] text-danger">{{ form.errors.message }}</span>
                    </label>
                    <!-- Honigtopf: aus dem Blick und aus der Tab-Reihenfolge -->
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" />
                    </div>
                    <p class="text-[13px] text-ink-3">
                        Deine Angaben nutze ich nur, um dir zu antworten. Mehr dazu in der
                        <Link :href="route('privacy')" class="text-accent">Datenschutzerklärung</Link>.
                    </p>
                    <button type="submit" :disabled="form.processing"
                        class="inline-flex h-11 items-center rounded-full bg-accent px-6 text-[15px] font-semibold text-white transition active:scale-[0.98] disabled:opacity-40">
                        Nachricht senden
                    </button>
                </form>
            </section>
        </main>

        <footer class="border-t border-line px-5 py-8">
            <div class="mx-auto flex max-w-3xl items-center justify-between text-[13px] text-ink-3">
                <Link :href="route('privacy')" class="transition hover:text-ink-2 active:opacity-60">Datenschutz</Link>
                <Link href="/" class="transition hover:text-ink-2 active:opacity-60">Zur Startseite</Link>
            </div>
        </footer>
    </div>
</template>
