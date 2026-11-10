<script setup>
/**
 * Ein Satz vom Coach, aus den Daten gerechnet (`App\Services\Today\TodayExtras`),
 * kein Modellaufruf. „Antworten“ öffnet den Coach-Chat.
 */
defineProps({
    text:  { type: String, required: true },
    coach: { type: Object, default: null },
});
defineEmits(['reply']);
</script>

<template>
    <section aria-label="Coach" class="flex h-full flex-col gap-4 rounded-[20px] bg-surface p-[18px] lg:rounded-[24px] lg:p-7">
        <div class="flex items-start gap-3 lg:items-center">
            <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent text-[17px] font-extrabold text-white lg:h-12 lg:w-12 lg:text-[20px]">
                {{ (coach?.name ?? 'C').charAt(0) }}
            </span>
            <div class="min-w-0">
                <p class="text-[13px] font-semibold text-ink-3 lg:text-[17px] lg:font-bold lg:text-ink">{{ coach?.name ?? 'Dein Coach' }}</p>
                <p class="hidden text-[13px] text-ink-3 lg:block">dein Coach</p>
                <p class="mt-0.5 text-[17px] font-semibold leading-[23px] text-ink lg:hidden">{{ text }}</p>
            </div>
        </div>
        <p class="hidden text-[22px] font-bold leading-[29px] tracking-[-0.02em] text-ink lg:block">{{ text }}</p>
        <div class="mt-auto hidden lg:block">
            <button type="button" class="inline-flex h-10 items-center rounded-full bg-surface-2 px-4 text-[15px] font-semibold text-accent transition active:scale-95"
                @click="$emit('reply')">Antworten</button>
        </div>
    </section>
</template>
