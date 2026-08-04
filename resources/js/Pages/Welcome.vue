<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const scrolled = ref(false);
function onScroll() { scrolled.value = window.scrollY > 40; }
onMounted(() => window.addEventListener('scroll', onScroll));
onUnmounted(() => window.removeEventListener('scroll', onScroll));
</script>

<template>
    <Head title="Zone3 – Dein KI Running Coach" />

    <div class="min-h-screen bg-[#0a0e1a] text-white overflow-x-hidden">

        <!-- NAV -->
        <nav :class="['fixed top-0 inset-x-0 z-50 transition-all duration-300', scrolled ? 'bg-[#0a0e1a]/95 backdrop-blur border-b border-white/5 shadow-lg' : '']">
            <div class="mx-auto max-w-6xl px-5 h-16 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-accent flex items-center justify-center text-white font-bold text-sm">Z3</div>
                    <span class="font-bold text-lg tracking-tight">Zone3</span>
                </div>
                <div class="flex items-center gap-3">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                        class="px-4 py-2 rounded-lg bg-accent hover:opacity-90 text-sm font-semibold transition">
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-white/70 hover:text-white transition">
                            Anmelden
                        </Link>
                        <Link v-if="canRegister" :href="route('register')"
                            class="px-4 py-2 rounded-lg bg-accent hover:opacity-90 text-sm font-semibold transition">
                            Kostenlos starten
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- HERO -->
        <section class="relative min-h-screen flex items-center justify-center px-5 pt-16">
            <!-- Background glow -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] rounded-full bg-accent/20 blur-[120px]"></div>
                <div class="absolute top-2/3 left-1/4 w-[300px] h-[300px] rounded-full bg-accent/10 blur-[80px]"></div>
            </div>

            <div class="relative text-center max-w-3xl mx-auto">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-accent/10 border border-accent/20 text-accent text-sm font-medium mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
                    KI-gestütztes Lauftraining
                </div>

                <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-[1.1] mb-6">
                    Trainiere smarter.<br>
                    <span class="bg-gradient-to-r from-accent via-accent to-accent bg-clip-text text-transparent">
                        Laufe schneller.
                    </span>
                </h1>

                <p class="text-lg sm:text-xl text-white/60 max-w-xl mx-auto mb-10 leading-relaxed">
                    Zone3 analysiert deine Strava-Läufe, berechnet deine Herzfrequenz- und Pace-Zonen und gibt dir täglich personalisierte KI-Empfehlungen.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                        class="w-full sm:w-auto px-8 py-4 rounded-field bg-accent hover:opacity-90 font-bold text-base transition shadow-lg">
                        Zum Dashboard →
                    </Link>
                    <template v-else>
                        <Link v-if="canRegister" :href="route('register')"
                            class="w-full sm:w-auto px-8 py-4 rounded-field bg-accent hover:opacity-90 font-bold text-base transition shadow-lg">
                            Jetzt kostenlos starten →
                        </Link>
                        <Link v-if="canLogin" :href="route('login')"
                            class="w-full sm:w-auto px-8 py-4 rounded-field bg-surface/5 hover:bg-surface/10 border border-white/10 font-semibold text-base transition">
                            Bereits registriert? Anmelden
                        </Link>
                    </template>
                </div>

                <!-- Stats -->
                <div class="mt-16 grid grid-cols-3 gap-4 max-w-md mx-auto">
                    <div class="text-center">
                        <div class="text-2xl font-black text-white">5</div>
                        <div class="text-xs text-white/40 mt-1">Herz&shy;frequenz&shy;zonen</div>
                    </div>
                    <div class="text-center border-x border-white/10">
                        <div class="text-2xl font-black text-white">KI</div>
                        <div class="text-xs text-white/40 mt-1">Tägliche Empfehlung</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-black text-white">4</div>
                        <div class="text-xs text-white/40 mt-1">Rennzeit&shy;vorhersagen</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FEATURES -->
        <section class="py-24 px-5">
            <div class="mx-auto max-w-6xl">
                <div class="text-center mb-16">
                    <h2 class="text-3xl sm:text-4xl font-black mb-4">Alles was du für dein Training brauchst</h2>
                    <p class="text-white/50 max-w-lg mx-auto">Kein Rätselraten mehr. Zone3 gibt dir konkrete, datenbasierte Trainingsempfehlungen.</p>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <!-- Feature 1 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-accent/10 border border-accent/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            🏃
                        </div>
                        <h3 class="font-bold text-lg mb-2">Strava Sync</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Verbinde deinen Strava-Account und alle deine Läufe werden automatisch importiert und analysiert.</p>
                    </div>

                    <!-- Feature 2 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-accent/10 border border-accent/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            ❤️
                        </div>
                        <h3 class="font-bold text-lg mb-2">Herzfrequenz-Zonen</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Automatische Berechnung deiner persönlichen 5 HF-Zonen basierend auf deiner Laktatschwelle.</p>
                    </div>

                    <!-- Feature 3 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-accent/10 border border-accent/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            ⚡
                        </div>
                        <h3 class="font-bold text-lg mb-2">Pace-Zonen</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Deine Schwellenpace wird automatisch aus deinen letzten Läufen berechnet und kontinuierlich aktualisiert.</p>
                    </div>

                    <!-- Feature 4 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-success/10 border border-success/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            🤖
                        </div>
                        <h3 class="font-bold text-lg mb-2">KI-Tagesempfehlung</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Täglich eine personalisierte Trainingsempfehlung von GPT-4o basierend auf deinem Wohlbefinden und Trainingszustand.</p>
                    </div>

                    <!-- Feature 5 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-warn/10 border border-warn/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            🎯
                        </div>
                        <h3 class="font-bold text-lg mb-2">Rennzeitvorhersagen</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Vorhersagen für 5 km, 10 km, Halbmarathon und Marathon basierend auf deiner aktuellen Fitness.</p>
                    </div>

                    <!-- Feature 6 -->
                    <div class="rounded-card bg-surface/[0.03] border border-white/[0.06] p-6 hover:bg-surface/[0.05] transition group">
                        <div class="w-12 h-12 rounded-field bg-danger/10 border border-danger/20 flex items-center justify-center mb-5 text-2xl group-hover:scale-110 transition">
                            📊
                        </div>
                        <h3 class="font-bold text-lg mb-2">Statistiken & Trends</h3>
                        <p class="text-white/50 text-sm leading-relaxed">Monatliche und wöchentliche Übersichten deiner Kilometer, Läufe und Pace-Entwicklung auf einen Blick.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- HOW IT WORKS -->
        <section class="py-24 px-5 relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute bottom-0 right-0 w-[400px] h-[400px] rounded-full bg-accent/10 blur-[100px]"></div>
            </div>
            <div class="mx-auto max-w-4xl relative">
                <div class="text-center mb-16">
                    <h2 class="text-3xl sm:text-4xl font-black mb-4">In 3 Schritten loslegen</h2>
                    <p class="text-white/50">Keine komplizierte Einrichtung. Keine manuelle Eingabe.</p>
                </div>

                <div class="space-y-5">
                    <div class="flex gap-6 items-start p-6 rounded-card bg-surface/[0.03] border border-white/[0.06]">
                        <div class="w-12 h-12 rounded-full bg-accent flex items-center justify-center font-black text-lg flex-shrink-0">1</div>
                        <div>
                            <h3 class="font-bold text-lg mb-1">Account erstellen</h3>
                            <p class="text-white/50 text-sm">Registriere dich kostenlos mit deiner E-Mail-Adresse. Keine Kreditkarte erforderlich.</p>
                        </div>
                    </div>
                    <div class="flex gap-6 items-start p-6 rounded-card bg-surface/[0.03] border border-white/[0.06]">
                        <div class="w-12 h-12 rounded-full bg-accent flex items-center justify-center font-black text-lg flex-shrink-0">2</div>
                        <div>
                            <h3 class="font-bold text-lg mb-1">Strava verbinden & Profil einrichten</h3>
                            <p class="text-white/50 text-sm">Verbinde deinen Strava-Account. Zone3 importiert automatisch deine Läufe und berechnet deine HF- und Pace-Zonen.</p>
                        </div>
                    </div>
                    <div class="flex gap-6 items-start p-6 rounded-card bg-surface/[0.03] border border-white/[0.06]">
                        <div class="w-12 h-12 rounded-full bg-accent flex items-center justify-center font-black text-lg flex-shrink-0">3</div>
                        <div>
                            <h3 class="font-bold text-lg mb-1">Täglich trainieren & verbessern</h3>
                            <p class="text-white/50 text-sm">Erhalte jeden Tag eine KI-Empfehlung, verfolge deine Ziele und sieh wie deine Fitness Schritt für Schritt wächst.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 px-5">
            <div class="mx-auto max-w-2xl text-center">
                <div class="rounded-3xl bg-gradient-to-br from-accent/20 via-accent/10 to-accent/20 border border-accent/20 p-10 sm:p-16">
                    <h2 class="text-3xl sm:text-4xl font-black mb-4">Bereit für deinen nächsten PR?</h2>
                    <p class="text-white/60 mb-8 text-lg">Starte noch heute und trainiere mit einem Plan der auf dich zugeschnitten ist.</p>
                    <Link v-if="$page.props.auth.user" :href="route('dashboard')"
                        class="inline-flex px-8 py-4 rounded-field bg-accent hover:opacity-90 font-bold text-base transition shadow-lg">
                        Zum Dashboard →
                    </Link>
                    <template v-else>
                        <Link v-if="canRegister" :href="route('register')"
                            class="inline-flex px-8 py-4 rounded-field bg-accent hover:opacity-90 font-bold text-base transition shadow-lg">
                            Kostenlos registrieren →
                        </Link>
                    </template>
                </div>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="border-t border-white/[0.06] py-8 px-5">
            <div class="mx-auto max-w-6xl flex flex-col sm:flex-row items-center justify-between gap-4 text-white/30 text-sm">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded bg-accent flex items-center justify-center text-white font-bold text-xs">Z3</div>
                    <span>Zone3</span>
                </div>
                <div>Dein persönlicher KI Running Coach</div>
                <div class="flex items-center gap-4">
                    <Link :href="route('support')" class="hover:text-white/60 transition">Support & Datenschutz</Link>
                    <Link :href="route('login')" class="hover:text-white/60 transition">Anmelden</Link>
                    <Link v-if="canRegister" :href="route('register')" class="hover:text-white/60 transition">Registrieren</Link>
                </div>
            </div>
        </footer>

    </div>
</template>
