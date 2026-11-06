<script setup>
import { computed } from 'vue';

/**
 * Körpergrafik: welche Muskeln eine Krafteinheit belastet hat.
 *
 * Eigene Zeichnung (06.11.2026, Jans Wahl gegen die wger-Grafik, deren
 * Lizenz offen ist): Vorder- und Rückansicht, je eine Hälfte gezeichnet und
 * gespiegelt. Die Flächen heißen wie `App\Services\Strength\Muscles::LABELS`.
 * `load` ist je Fläche 0–1 (stärkste = 1); je stärker, desto satter der
 * Akzent. Unbelastete Muskeln bleiben grau, der Körper noch heller.
 */
const props = defineProps({
    // { glutes: 1, hamstrings: 0.6, … }
    load:   { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
});

const BODY = 'M50 2 C43 2 40.5 8 40.8 14.5 C41 20.5 43 24.5 45 26.5 L45 29.5 C40 31.5 32 32.5 28 35.5 C24 38.5 22.8 45 22.4 52 L20.2 71 C19.4 79 17.6 87 17 95 L15.8 103.5 C15.4 107 16.6 109 18.4 108 L19.8 103 C21.2 97 22.8 90 24.4 82 L27.4 66 L30.4 59 C31.2 70 31.4 80 32 90 C30.6 100 30.2 108 30.8 116 C31.8 132 33 146 34 156 C33 168 33.6 182 35.6 195 C34.8 200 37 203.5 41 203.5 C44.2 203.5 44.4 200.5 43.6 197 C44.4 184 45.2 170 44.8 158 C46.2 144 47.2 128 48 114 L50 107 Z';

const FRONT = {
    shoulders:"M29.5 36.5 C25 38.5 23.4 44.5 23.6 50.5 C26.4 49.8 29.4 47.6 31.2 44.6 C32.8 41.6 32.6 37.4 29.5 36.5 Z",
  chest:"M33.4 38.6 C38.4 36 45 35.8 49.4 37.4 L49.4 49.8 C45 53 38.4 53 34.4 50 C32.4 47 31.8 42 33.4 38.6 Z",
  biceps:"M24.2 53 C22.4 58 22.4 65.6 23.6 70 C26.2 68 28.4 63 29 57.4 C28.6 53.4 26.2 52 24.2 53 Z",
  obliques:"M35.6 53.4 C37.8 60 39.4 72 39.8 84 C40.6 88 42 90 43.4 90 L43.4 55.4 C41 54.4 38 53.6 35.6 53.4 Z",
  abs:["M44.4 54.4 h4.9 v6.6 h-4.9 Z","M44.4 62.6 h4.9 v6.8 h-4.9 Z","M44.4 71 h4.9 v7 h-4.9 Z","M44.4 79.6 h4.9 v11.6 C47.6 91.6 45.6 90 44.4 88 Z"],
  hip_flexors:"M40.4 91.2 C42.4 94.4 45.2 98.4 48.6 101.4 L48.6 94.6 C46.4 92.6 43.4 91.4 40.4 91.2 Z",
  quads:"M33.4 97.4 C31.4 108 31.6 125 33.4 139.6 C35.2 145.6 38.2 149.6 41 149.6 C43 145 44.4 130 44.8 118 C44.8 108 42 100.4 37 96.4 Z",
  adductors:"M45.8 104.6 C47.6 108.6 48.4 116 48 125 C47.2 129.4 46 127.6 45.6 122 Z",
  shins:"M39 159.6 C37.8 170 38.2 182 39.6 192 C41.2 190 42.2 178 42.2 166 C41.8 161 40.6 159 39 159.6 Z",
  calves:"M35.4 158 C33.8 166 34.2 176 35.8 184 C36.8 176 37.4 166 36.8 159 Z",
};

const BACK = {
  traps:"M49.4 26 C46 30 40 33 33.6 35.6 C38 38 43 42.4 46 50 L49.4 62 Z",
    shoulders:"M29.5 36.5 C25 38.5 23.4 44.5 23.6 50.5 C26.4 49.8 29.4 47.6 31.2 44.6 C32.8 41.6 32.6 37.4 29.5 36.5 Z",
  triceps:"M23.6 52.4 C22 58 22.6 66 24 70 C26.6 67 28.6 61 29.2 55 C27.6 52 25.6 51.4 23.6 52.4 Z",
  lats:"M32.6 44.4 C33.6 55 36.8 66.4 42 76.4 C44 80 46.6 81.6 49.4 81.8 L49.4 66 C46 57.4 41.6 49.6 32.6 44.4 Z",
  lower_back:"M42.8 80.4 C43.8 86 44.4 92 44.4 96 L49.4 96 L49.4 83.2 C47 83 44.8 82 42.8 80.4 Z",
  glutes:"M32.8 96.4 C31 104 31.8 112 35.8 116 C40.8 119.4 46 118.4 49.4 114.4 L49.4 98 C45 94 38 93.4 32.8 96.4 Z",
  hamstrings:"M33.2 120 C32 130 33 142 36 150 C38 152 41 152 43 150 C45 140 46 128 46 120 C42 122.4 37 122.4 33.2 120 Z",
  calves:"M35 156 C33 164 34 176 37 184 C39 186 41 184 42 180 C43.4 170 43 162 41.4 156 C39.4 154 37 154 35 156 Z",
};

const paths = (shapes) => Object.entries(shapes).flatMap(([key, d]) => [].concat(d).map((p, i) => ({ key, d: p, id: key + i })));
const front = paths(FRONT);
const back  = paths(BACK);

const fill = (key) => {
    const a = props.load[key];
    return a ? `rgb(var(--z-accent) / ${(0.3 + 0.7 * a).toFixed(2)})` : 'rgb(var(--z-surface-3))';
};

const top = computed(() => Object.entries(props.load)
    .sort((a, b) => b[1] - a[1])
    .map(([k]) => props.labels[k] || k));

const ariaLabel = computed(() => top.value.length ? 'Belastete Muskeln: ' + top.value.join(', ') : 'Keine Muskeln zugeordnet');
</script>

<template>
    <figure>
        <svg viewBox="0 0 200 210" class="mx-auto block h-auto w-full max-w-[360px]" role="img" :aria-label="ariaLabel">
            <g v-for="(view, vi) in [front, back]" :key="vi" :transform="`translate(${vi * 100} 0)`">
                <g v-for="side in [0, 1]" :key="side" :transform="side ? 'translate(100 0) scale(-1 1)' : undefined">
                    <path :d="BODY" fill="rgb(var(--z-surface-2))" />
                    <path v-for="p in view" :key="p.id" :d="p.d" :fill="fill(p.key)" class="transition-[fill] duration-300" />
                </g>
            </g>
        </svg>
        <figcaption v-if="top.length" class="mt-3 flex flex-wrap justify-center gap-1.5">
            <span v-for="(name, i) in top.slice(0, 6)" :key="name"
                class="rounded-full px-2.5 py-1 text-[13px] font-semibold"
                :class="i < 2 ? 'bg-accent-soft text-accent-ink' : 'bg-surface-2 text-ink-2'">{{ name }}</span>
        </figcaption>
    </figure>
</template>
