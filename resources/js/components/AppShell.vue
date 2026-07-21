<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps<{ title: string; subtitle?: string }>();

const dark = ref(false);

function applyTheme(value: boolean): void {
    dark.value = value;
    document.documentElement.classList.toggle('dark', value);
}

function toggleTheme(): void {
    const value = !dark.value;
    localStorage.setItem('spell-planner-theme', value ? 'dark' : 'light');
    applyTheme(value);
}

onMounted(() => {
    const saved = localStorage.getItem('spell-planner-theme');
    applyTheme(saved === null ? window.matchMedia('(prefers-color-scheme: dark)').matches : saved === 'dark');
});
</script>

<template>
    <div class="min-h-full bg-stone-50 text-stone-950 dark:bg-stone-950 dark:text-stone-100">
        <header class="border-b border-stone-300 bg-white dark:border-stone-800 dark:bg-stone-900">
            <div class="mx-auto flex max-w-[1800px] items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <div class="min-w-0">
                    <Link href="/" class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-700 hover:underline dark:text-violet-300">Spell Planner</Link>
                    <h1 class="truncate text-xl font-semibold">{{ title }}</h1>
                    <p v-if="subtitle" class="text-sm text-stone-600 dark:text-stone-400">{{ subtitle }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <slot name="actions" />
                    <button type="button" class="button-secondary" :aria-label="dark ? 'Use light theme' : 'Use dark theme'" @click="toggleTheme">
                        <span aria-hidden="true">{{ dark ? '☀' : '☾' }}</span>
                        <span class="hidden sm:inline">{{ dark ? 'Light' : 'Dark' }}</span>
                    </button>
                </div>
            </div>
        </header>
        <slot />
    </div>
</template>
