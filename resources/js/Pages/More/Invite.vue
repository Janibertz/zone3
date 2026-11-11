<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppButton from '@/Components/UI/AppButton.vue';

/**
 * „Freunde einladen“ (11.11.2026, Jan: „jeder Tester kann weitere
 * einladen“). Jeder Athlet hat einen eigenen Link für wenige Freunde; wer
 * darüber kommt, ist sofort drin, an der Warteliste vorbei.
 */
const props = defineProps({
    invite: { type: Object, required: true },
});

const page = usePage();
const firstName = computed(() => (page.props.auth?.user?.name ?? '').trim().split(' ')[0]);

const left = computed(() => props.invite.max === null ? null : Math.max(0, props.invite.max - props.invite.used));

const message = computed(() =>
    `Ich trainiere gerade mit Zone3, einer Lauf-App mit KI-Coach. Sie baut dir einen Plan für dein Ziel und passt ihn an, wenn etwas dazwischenkommt. In der Beta ist sie kostenlos. Mit meinem Link kommst du direkt rein:`);

const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';
const copied = ref(false);

async function share() {
    try {
        await navigator.share({ title: 'Zone3', text: message.value, url: props.invite.url });
    } catch {
        // Abgebrochen: nichts zu tun.
    }
}

async function copy() {
    try {
        await navigator.clipboard.writeText(`${message.value} ${props.invite.url}`);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        window.prompt('Link kopieren:', props.invite.url);
    }
}

const until = computed(() => new Date(props.invite.expires).toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' }));
</script>

<template>
    <Head title="Freunde einladen" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-2xl" title="Freunde einladen" :back="route('more')" />

            <div class="mx-auto max-w-2xl space-y-6 px-4 pb-8 pt-3 lg:px-8">
                <p class="mx-1 text-[17px] text-ink-2">
                    Zone3 ist noch in der Beta. Mit deinem Link kommen Freunde, die laufen, sofort rein, ohne Warteliste.
                </p>

                <!-- Plätze -->
                <div class="rounded-card bg-surface px-4 py-4">
                    <div class="flex items-baseline justify-between">
                        <span class="text-[15px] text-ink-3">Deine Plätze</span>
                        <span v-if="left !== null" class="font-rounded text-[15px] text-ink-3">
                            {{ invite.used }} von {{ invite.max }} vergeben
                        </span>
                    </div>
                    <div v-if="invite.max" class="mt-3 flex gap-2" aria-hidden="true">
                        <span v-for="n in invite.max" :key="n"
                            class="h-2.5 flex-1 rounded-full"
                            :class="n <= invite.used ? 'bg-accent' : 'bg-surface-3'" />
                    </div>
                    <p v-if="invite.friends.length" class="mt-3 text-[15px] text-ink">
                        Schon dabei: {{ invite.friends.join(', ') }}
                    </p>
                </div>

                <!-- Link -->
                <div v-if="invite.url" class="space-y-3">
                    <div class="rounded-card bg-surface px-4 py-3">
                        <p class="text-[13px] text-ink-3">Dein Link, gültig bis {{ until }}</p>
                        <p class="mt-1 select-all break-all font-mono text-[13px] text-ink">{{ invite.url }}</p>
                    </div>
                    <AppButton v-if="canShare" block size="lg" @click="share">Link teilen</AppButton>
                    <AppButton block size="lg" :variant="canShare ? 'secondary' : 'primary'" @click="copy">
                        {{ copied ? 'Kopiert ✓' : 'Nachricht mit Link kopieren' }}
                    </AppButton>
                    <p class="mx-1 text-[13px] text-ink-3">
                        Beim Teilen steht schon eine kurze Nachricht dabei. Am besten schreibst du noch einen Satz dazu, der nur zu deinem Freund passt{{ firstName ? ', ' + firstName : '' }}.
                    </p>
                </div>

                <div v-else class="rounded-card bg-surface px-4 py-4 text-[15px] text-ink-2">
                    <template v-if="invite.status === 'used'">
                        Alle Plätze sind vergeben. Danke fürs Weitersagen! Wenn noch jemand mitmachen will, schreib uns unter Hilfe &amp; Feedback.
                    </template>
                    <template v-else>
                        Einladen ist gerade pausiert. Wenn jemand mitmachen will, schreib uns unter Hilfe &amp; Feedback.
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
