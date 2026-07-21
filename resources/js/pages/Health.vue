<script setup lang="ts">
defineProps<{
    laravel: string;
    php: string;
    journalMode: string;
    foreignKeys: number;
}>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8">
        <h1 class="text-xl font-semibold">Spell Planner — stack health</h1>
        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <dt class="text-neutral-500">Laravel</dt>
            <dd data-testid="laravel">{{ laravel }}</dd>
            <dt class="text-neutral-500">PHP</dt>
            <dd data-testid="php">{{ php }}</dd>
            <dt class="text-neutral-500">SQLite journal mode</dt>
            <dd data-testid="journal-mode">{{ journalMode }}</dd>
            <dt class="text-neutral-500">Foreign keys</dt>
            <dd data-testid="foreign-keys">{{ foreignKeys }}</dd>
        </dl>
    </main>
</template>
