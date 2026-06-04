<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isDark, toggle } = useDarkMode();

const moreOpen = ref(false);

const navItems = [
    {
        label: 'Übersicht',
        routeName: 'admin.dashboard',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />`,
    },
    {
        label: 'Nutzer',
        routeName: 'admin.users.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />`,
    },
    {
        label: 'AI Logs',
        routeName: 'admin.ai-logs.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />`,
    },
    {
        label: 'Support',
        routeName: 'admin.support.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />`,
    },
];

const moreNavItems = [
    {
        label: 'Coaches',
        routeName: 'admin.coaches.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />`,
    },
    {
        label: 'Wiki',
        routeName: 'admin.wiki.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />`,
    },
    {
        label: 'Einstellungen',
        routeName: 'admin.settings.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />`,
    },
];

const allNavItems = [...navItems, ...moreNavItems];
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-slate-950">

        <!-- ══════════════════════════════════════
             DESKTOP SIDEBAR
             ══════════════════════════════════════ -->
        <aside class="hidden lg:flex lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:w-64 flex-col bg-white dark:bg-slate-900 border-r border-gray-100 dark:border-slate-800">
            <!-- Logo -->
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-gray-100 dark:border-slate-800">
                <div class="h-8 w-8 rounded-xl bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center shadow-sm">
                    <span class="text-white text-sm font-bold">Z3</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-base font-bold text-gray-900 dark:text-white tracking-tight leading-tight">Zone3</span>
                    <span class="text-[10px] font-semibold text-red-500 dark:text-red-400 uppercase tracking-widest leading-tight">Admin</span>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-0.5">
                <p class="px-3 mb-3 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Verwaltung</p>
                <Link
                    v-for="item in allNavItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150"
                    :class="route().current(item.routeName)
                        ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400'
                        : 'text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    {{ item.label }}
                    <span v-if="route().current(item.routeName)" class="ml-auto h-1.5 w-1.5 rounded-full bg-red-500 dark:bg-red-400" />
                </Link>
            </nav>

            <!-- Footer -->
            <div class="shrink-0 border-t border-gray-100 dark:border-slate-800 p-4 space-y-1">
                <Link
                    :href="route('dashboard')"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white transition-colors"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                    </svg>
                    Zurück zur App
                </Link>
                <button
                    @click="toggle"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white transition-colors"
                >
                    <svg v-if="isDark" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                    {{ isDark ? 'Helles Design' : 'Dunkles Design' }}
                </button>
                <div class="flex items-center gap-3 px-2 py-2">
                    <div class="h-9 w-9 shrink-0 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center shadow-sm">
                        <span class="text-sm font-bold text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ user.name }}</p>
                        <p class="text-xs text-red-500 dark:text-red-400 font-medium truncate">Administrator</p>
                    </div>
                </div>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm text-gray-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Abmelden
                </Link>
            </div>
        </aside>

        <!-- ══════════════════════════════════════
             MOBILE TOP BAR
             ══════════════════════════════════════ -->
        <header class="lg:hidden fixed top-0 inset-x-0 z-20 h-14 flex items-center justify-between bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 px-3">
            <!-- Left: back to app + logo -->
            <div class="flex items-center gap-2">
                <Link
                    :href="route('dashboard')"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors"
                    title="Zurück zur App"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div class="flex items-center gap-2">
                    <div class="h-7 w-7 rounded-lg bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center shadow-sm">
                        <span class="text-white text-xs font-bold">Z3</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-gray-900 dark:text-white tracking-tight leading-tight">Zone3</span>
                        <span class="text-[9px] font-semibold text-red-500 dark:text-red-400 uppercase tracking-widest leading-tight">Admin</span>
                    </div>
                </div>
            </div>

            <!-- Right: dark mode + logout -->
            <div class="flex items-center gap-1">
                <button
                    @click="toggle"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors"
                >
                    <svg v-if="isDark" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                </Link>
            </div>
        </header>

        <!-- ══════════════════════════════════════
             MAIN CONTENT
             ══════════════════════════════════════ -->
        <!-- pt-14 on the outer wrapper pushes ALL content (header + main) below the fixed mobile top bar -->
        <div class="lg:pl-64 flex flex-col min-h-screen pt-14 lg:pt-0">
            <header v-if="$slots.header" class="bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800">
                <div class="px-4 sm:px-6 py-3 lg:py-4">
                    <slot name="header" />
                </div>
            </header>
            <main class="flex-1 pb-24 lg:pb-0">
                <slot />
            </main>
        </div>

        <!-- ══════════════════════════════════════
             MOBILE BOTTOM TAB BAR (4 primary + Mehr)
             ══════════════════════════════════════ -->
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white dark:bg-slate-900 border-t border-gray-100 dark:border-slate-800 pb-safe-tabbar">
            <div class="flex items-center h-16">
                <Link
                    v-for="item in navItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex-1 flex flex-col items-center justify-center gap-1 h-full transition-colors relative"
                    :class="route().current(item.routeName)
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-gray-400 dark:text-slate-500'"
                >
                    <span v-if="route().current(item.routeName)"
                        class="absolute top-0 left-1/2 -translate-x-1/2 h-0.5 w-8 bg-red-500 dark:bg-red-400 rounded-b-full"
                    />
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    <span class="text-[10px] font-medium leading-none">{{ item.label }}</span>
                </Link>

                <!-- Mehr button -->
                <button
                    @click="moreOpen = true"
                    class="flex-1 flex flex-col items-center justify-center gap-1 h-full transition-colors relative"
                    :class="moreOpen ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-slate-500'"
                >
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                    <span class="text-[10px] font-medium leading-none">Mehr</span>
                </button>
            </div>
        </nav>

        <!-- ══════════════════════════════════════
             MOBILE "MEHR" SHEET
             ══════════════════════════════════════ -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="moreOpen" class="lg:hidden fixed inset-0 z-40 bg-black/40" @click="moreOpen = false" />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-full"
            enter-to-class="translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0"
            leave-to-class="translate-y-full"
        >
            <div v-if="moreOpen"
                class="lg:hidden fixed bottom-0 inset-x-0 z-50 bg-white dark:bg-slate-900 rounded-t-2xl shadow-2xl pb-safe-tabbar overflow-hidden"
            >
                <!-- Sheet handle -->
                <div class="flex justify-center pt-3 pb-1">
                    <div class="h-1 w-10 rounded-full bg-gray-200 dark:bg-slate-700" />
                </div>

                <!-- Header -->
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-slate-800">
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">Admin-Menü</span>
                    <button @click="moreOpen = false"
                        class="h-8 w-8 flex items-center justify-center rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- More nav links -->
                <nav class="px-3 py-3 space-y-0.5">
                    <Link
                        v-for="item in moreNavItems"
                        :key="item.routeName"
                        :href="route(item.routeName)"
                        @click="moreOpen = false"
                        class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-colors"
                        :class="route().current(item.routeName)
                            ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400'
                            : 'text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'"
                    >
                        <span class="h-9 w-9 rounded-xl flex items-center justify-center shrink-0"
                            :class="route().current(item.routeName)
                                ? 'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400'
                                : 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400'">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                        </span>
                        <span>{{ item.label }}</span>
                    </Link>

                    <!-- Divider -->
                    <div class="my-2 border-t border-gray-100 dark:border-slate-800" />

                    <!-- Zurück zur App -->
                    <Link
                        :href="route('dashboard')"
                        @click="moreOpen = false"
                        class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-colors text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10"
                    >
                        <span class="h-9 w-9 rounded-xl flex items-center justify-center shrink-0 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                            </svg>
                        </span>
                        <span class="font-semibold">Zurück zur App</span>
                    </Link>
                </nav>

                <!-- User info -->
                <div class="mx-3 mt-1 mb-2 rounded-xl bg-gray-50 dark:bg-slate-800 px-3 py-2.5 flex items-center gap-3">
                    <div class="h-8 w-8 shrink-0 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center">
                        <span class="text-xs font-bold text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ user.name }}</p>
                        <p class="text-xs text-red-500 dark:text-red-400 font-medium">Administrator</p>
                    </div>
                </div>
            </div>
        </Transition>

    </div>
</template>
