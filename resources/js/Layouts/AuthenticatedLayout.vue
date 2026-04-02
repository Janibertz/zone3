<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isDark, toggle } = useDarkMode();

const navItems = [
    {
        label: 'Dashboard',
        routeName: 'dashboard',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />`,
    },
    {
        label: 'Aktivitäten',
        routeName: 'activities.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />`,
    },
    {
        label: 'Statistiken',
        routeName: 'statistics.index',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" />`,
    },
    {
        label: 'Profil',
        routeName: 'profile.edit',
        icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />`,
    },
];
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-slate-950">

        <!-- ══════════════════════════════════════
             DESKTOP SIDEBAR (hidden on mobile)
             ══════════════════════════════════════ -->
        <aside class="hidden lg:flex lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:w-64 flex-col bg-white dark:bg-slate-900 border-r border-gray-100 dark:border-slate-800">
            <!-- Logo -->
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 border-b border-gray-100 dark:border-slate-800">
                <div class="h-8 w-8 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                    <span class="text-white text-sm font-bold">Z3</span>
                </div>
                <span class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-0.5">
                <p class="px-3 mb-3 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Navigation</p>
                <Link
                    v-for="item in navItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150"
                    :class="route().current(item.routeName)
                        ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400'
                        : 'text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    {{ item.label }}
                    <span v-if="route().current(item.routeName)" class="ml-auto h-1.5 w-1.5 rounded-full bg-indigo-500 dark:bg-indigo-400" />
                </Link>
            </nav>

            <!-- User + Dark toggle -->
            <div class="shrink-0 border-t border-gray-100 dark:border-slate-800 p-4 space-y-1">
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
                    <div class="h-9 w-9 shrink-0 rounded-full bg-gradient-to-br from-indigo-400 to-indigo-600 flex items-center justify-center shadow-sm">
                        <span class="text-sm font-bold text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ user.name }}</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 truncate">{{ user.email }}</p>
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
             MOBILE TOP BAR (logo + dark toggle)
             ══════════════════════════════════════ -->
        <header class="lg:hidden fixed top-0 inset-x-0 z-20 h-14 flex items-center justify-between bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 px-4">
            <div class="flex items-center gap-2.5">
                <div class="h-7 w-7 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-sm">
                    <span class="text-white text-xs font-bold">Z3</span>
                </div>
                <span class="text-base font-bold text-gray-900 dark:text-white tracking-tight">Zone3</span>
            </div>
            <div class="flex items-center gap-1">
                <!-- Dark mode toggle -->
                <button
                    @click="toggle"
                    class="h-9 w-9 flex items-center justify-center rounded-xl text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors"
                >
                    <svg v-if="isDark" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>
                <!-- Abmelden -->
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
        <div class="lg:pl-64 flex flex-col min-h-screen">
            <!-- Desktop page header slot -->
            <header v-if="$slots.header" class="hidden lg:block bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800">
                <div class="px-6 py-4">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page content — top padding for mobile header, bottom for mobile tab bar -->
            <main class="flex-1 pt-14 pb-20 lg:pt-0 lg:pb-0">
                <slot />
            </main>
        </div>

        <!-- ══════════════════════════════════════
             MOBILE BOTTOM TAB BAR
             ══════════════════════════════════════ -->
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white dark:bg-slate-900 border-t border-gray-100 dark:border-slate-800"
             style="padding-bottom: env(safe-area-inset-bottom);">
            <div class="flex items-center h-16">
                <Link
                    v-for="item in navItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex-1 flex flex-col items-center justify-center gap-1 h-full transition-colors relative"
                    :class="route().current(item.routeName)
                        ? 'text-indigo-600 dark:text-indigo-400'
                        : 'text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300'"
                >
                    <!-- Active indicator line at top -->
                    <span
                        v-if="route().current(item.routeName)"
                        class="absolute top-0 left-1/2 -translate-x-1/2 h-0.5 w-10 bg-indigo-500 dark:bg-indigo-400 rounded-b-full"
                    />
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" v-html="item.icon" />
                    <span class="text-[10px] font-medium leading-none">{{ item.label }}</span>
                </Link>
            </div>
        </nav>

    </div>
</template>
