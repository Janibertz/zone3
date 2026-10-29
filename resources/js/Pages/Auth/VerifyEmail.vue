<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

const props = defineProps({ status: String });

const form = useForm({});
const submit = () => { form.post(route('verification.send')); };
const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="E-Mail bestätigen" />

        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="text-center mb-7">
                <Link href="/" class="inline-flex flex-col items-center gap-2">
                    <ZoneIcon class="h-14 w-14" />
                    <Wordmark class="mt-1 h-6 text-ink" />
                </Link>
                <h1 class="mt-6 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em] text-ink">E-Mail bestätigen</h1>
            </div>

            <!-- Card -->
            <div class="rounded-card bg-surface p-5">

                <!-- Success -->
                <div v-if="verificationLinkSent" class="mb-5 flex items-center gap-2 rounded-field bg-success-soft px-4 py-3 text-[15px] text-success-ink">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Bestätigungslink wurde gesendet.
                </div>

                <div class="flex items-start gap-3 mb-5">
                    <div class="shrink-0 h-9 w-9 rounded-full bg-accent-soft flex items-center justify-center">
                        <svg class="h-5 w-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                        </svg>
                    </div>
                    <p class="text-[15px] text-ink-2 leading-snug">
                        Wir haben dir einen Bestätigungslink per E-Mail gesendet. Bitte überprüfe deinen Posteingang und klicke auf den Link.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-full bg-accent px-6 text-[17px] font-semibold text-white transition active:scale-[0.97] hover:opacity-90 disabled:opacity-40"
                    >
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Bestätigungslink erneut senden
                    </button>

                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="inline-flex h-[50px] w-full items-center justify-center rounded-full bg-surface-2 px-6 text-[17px] font-semibold text-accent transition active:scale-[0.97]"
                    >
                        Abmelden
                    </Link>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>
