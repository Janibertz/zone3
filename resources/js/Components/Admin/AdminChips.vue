<script setup>
/** Filter-Chips: schwarz = gewählt. `tone: 'warn'|'danger'` färbt die Schrift. */
defineProps({
    modelValue: { type: [String, Number], default: null },
    options:    { type: Array, required: true },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:flex-wrap lg:px-0">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            class="h-8 shrink-0 rounded-2xl px-3 text-[14px] font-semibold transition duration-150 ease-out-strong active:scale-[0.97]"
            :class="modelValue === o.value
                ? 'bg-ink text-canvas'
                : ['bg-surface', o.tone === 'warn' ? 'text-warn-ink' : o.tone === 'danger' ? 'text-danger-ink' : 'text-ink']"
            @click="$emit('update:modelValue', o.value)"
        >{{ o.label }}<template v-if="o.count !== undefined && o.count !== null"> {{ o.count }}</template></button>
    </div>
</template>
