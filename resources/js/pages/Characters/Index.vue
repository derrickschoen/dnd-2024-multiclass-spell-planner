<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '@/components/AppShell.vue';
import type { CharacterSummary } from '@/types';

defineProps<{ characters: CharacterSummary[] }>();

const name = ref('');
const creating = ref(false);

function createCharacter(): void {
    if (!name.value.trim() || creating.value) return;
    creating.value = true;
    router.post('/characters', { name: name.value.trim() }, {
        onFinish: () => { creating.value = false; },
    });
}

function deleteCharacter(character: CharacterSummary): void {
    if (!window.confirm(`Delete ${character.name}? This cannot be undone.`)) return;
    router.delete(`/characters/${character.id}`);
}
</script>

<template>
    <Head title="Characters" />
    <AppShell title="Characters" subtitle="Open a build or start a new multiclass spell plan." >
        <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
            <form class="panel mb-6 flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="createCharacter">
                <label class="flex-1 text-sm font-medium">
                    Character name
                    <input v-model="name" class="field mt-1 w-full" maxlength="120" required autocomplete="off" placeholder="e.g. Selene, spellblade" />
                </label>
                <button class="button-primary" type="submit" :disabled="creating || !name.trim()">
                    {{ creating ? 'Creating…' : 'Create character' }}
                </button>
            </form>

            <div v-if="characters.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="character in characters" :key="character.id" class="panel flex min-h-48 flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold">{{ character.name }}</h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">Level {{ character.level || 0 }}</p>
                        </div>
                        <span class="status-badge" :class="character.warning_count ? 'status-warning' : 'status-ok'">
                            <span aria-hidden="true">{{ character.warning_count ? '⚠' : '✓' }}</span>
                            {{ character.warning_count }} warning{{ character.warning_count === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <p class="mt-4 flex-1 text-sm leading-6 text-stone-700 dark:text-stone-300">
                        {{ character.classes.length ? character.classes.join(' / ') : 'No classes yet. Open the build to add one.' }}
                    </p>
                    <div class="mt-5 flex items-center gap-2 border-t border-stone-200 pt-4 dark:border-stone-800">
                        <Link :href="`/characters/${character.id}`" class="button-primary">Open workspace</Link>
                        <button type="button" class="button-danger ml-auto" :aria-label="`Delete ${character.name}`" @click="deleteCharacter(character)">Delete</button>
                    </div>
                </article>
            </div>

            <section v-else class="panel py-16 text-center">
                <div class="text-3xl" aria-hidden="true">⌁</div>
                <h2 class="mt-3 text-lg font-semibold">No characters yet</h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">Name your first character above, then add classes and spell choices.</p>
            </section>
        </main>
    </AppShell>
</template>
