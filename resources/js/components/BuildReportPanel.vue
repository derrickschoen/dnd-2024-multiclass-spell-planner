<script setup lang="ts">
import { computed } from 'vue';
import { ref } from 'vue';
import { useCharacterStore } from '@/stores/character';
import type { DuplicateAssessment, WorkspaceBuildReport } from '@/types';

const props = defineProps<{ report: WorkspaceBuildReport }>();
const store = useCharacterStore();
const acknowledgementNotes = ref<Record<string, string>>({});

const warningsByCategory = computed(() => {
    const groups: Record<string, DuplicateAssessment[]> = {};
    for (const item of props.report.duplicate_assessments.filter((entry) => entry.category !== 'none')) {
        (groups[item.category] ??= []).push(item);
    }
    return groups;
});

function title(value: string): string {
    if (value === 'conflicting_version') return 'CONFLICTING VERSIONS';
    return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function acknowledge(item: DuplicateAssessment): void {
    if (!item.warning_fingerprint) return;
    const note = acknowledgementNotes.value[item.warning_fingerprint]?.trim();
    if (!note) return;
    void store.execute({
        type: 'acknowledge_warning',
        warning_fingerprint: item.warning_fingerprint,
        note,
    });
}
</script>

<template>
    <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start" aria-label="Live build report">
        <section class="panel">
            <div class="flex items-center justify-between gap-4">
                <h2 class="section-title">Live report</h2>
                <span class="text-xs text-stone-500">Recomputes after every save</span>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <div class="metric"><dt>Caster level</dt><dd>{{ report.caster.caster_level }}</dd></div>
                <div class="metric"><dt>Proficiency</dt><dd>+{{ report.character.proficiency_bonus }}</dd></div>
                <div class="metric"><dt>Unique spells</dt><dd>{{ report.summary.unique_spells }}</dd></div>
                <div class="metric"><dt>Access routes</dt><dd>{{ report.summary.access_routes }}</dd></div>
            </dl>
            <h3 class="mt-5 text-sm font-semibold">Shared spell slots</h3>
            <div v-if="report.caster.slots.length" class="mt-2 flex flex-wrap gap-2">
                <span v-for="slot in report.caster.slots" :key="slot.level" class="status-badge status-neutral">L{{ slot.level }}: {{ slot.count }}</span>
            </div>
            <p v-else class="mt-2 text-sm text-stone-500">No shared spell slots.</p>
            <p v-if="report.caster.pact_magic" class="mt-2 text-sm">Pact Magic: {{ report.caster.pact_magic.count }} × level {{ report.caster.pact_magic.level }}</p>
        </section>

        <section class="panel">
            <h2 class="section-title">Class preparation ceilings</h2>
            <ul class="mt-3 divide-y divide-stone-200 text-sm dark:divide-stone-800">
                <li v-for="entry in report.classes" :key="entry.name" class="flex justify-between gap-3 py-2">
                    <span>{{ entry.name }} {{ entry.class_level }}<template v-if="entry.subclass"> · {{ entry.subclass }}</template></span>
                    <span class="font-medium">max L{{ entry.max_preparable_level }}</span>
                </li>
            </ul>
            <div class="mt-3 rounded-md border border-sky-300 bg-sky-50 p-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-100">
                <strong>Why this matters:</strong> {{ report.preparation_callout }}
            </div>
        </section>

        <section class="panel">
            <div class="flex items-center justify-between">
                <h2 class="section-title">Duplicate warnings</h2>
                <span class="status-badge" :class="Object.keys(warningsByCategory).length ? 'status-warning' : 'status-ok'">
                    <span aria-hidden="true">{{ Object.keys(warningsByCategory).length ? '⚠' : '✓' }}</span>
                    {{ Object.values(warningsByCategory).flat().length }}
                </span>
            </div>
            <p v-if="!Object.keys(warningsByCategory).length" class="mt-3 text-sm text-stone-500">No duplicate spell warnings.</p>
            <div v-for="(items, category) in warningsByCategory" :key="category" class="mt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ title(category) }}</h3>
                <article v-for="item in items" :key="item.spell_identity_id" :role="category === 'conflicting_version' ? 'alert' : undefined" class="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950">
                    <p class="font-medium"><span aria-hidden="true">⚠</span> {{ item.spell_name }}</p>
                    <p class="mt-1 text-xs leading-5">{{ item.explanation }}</p>
                    <ul v-if="item.versions.length > 1" class="mt-2 list-disc pl-5 text-xs"><li v-for="version in item.versions" :key="version.spell_version_id">{{ version.label }}</li></ul>
                    <p class="mt-1 text-xs text-stone-600 dark:text-stone-400">Sources: {{ item.sources.join(', ') }}</p>
                    <p v-if="item.acknowledgement" class="mt-2 rounded border border-amber-400 p-2 text-xs"><strong>Acknowledged:</strong> {{ item.acknowledgement.note }}</p>
                    <div v-else-if="item.warning_fingerprint" class="mt-2 flex items-end gap-2">
                        <label class="flex-1 text-xs">Acknowledgement note<input v-model="acknowledgementNotes[item.warning_fingerprint]" class="field mt-1 w-full" placeholder="Why both versions are intentional" /></label>
                        <button type="button" class="button-secondary" :disabled="store.saving || !acknowledgementNotes[item.warning_fingerprint]?.trim()" @click="acknowledge(item)">Acknowledge warning</button>
                    </div>
                </article>
            </div>
        </section>

        <section class="panel">
            <h2 class="section-title">Wizard spellbook access</h2>
            <p class="mt-2 text-xs leading-5 text-stone-600 dark:text-stone-400">{{ report.wizard.explanation }}</p>
            <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                <div><h3 class="font-semibold">In my book · {{ report.wizard.spellbook.length }}</h3><ul class="mt-1 space-y-1"><li v-for="item in report.wizard.spellbook" :key="item.spellbook_entry_id">{{ item.spell_name }} <span v-if="!item.active" class="text-amber-700 dark:text-amber-300">(unavailable — removed from catalog)</span></li></ul></div>
                <div><h3 class="font-semibold">Prepared · {{ report.wizard.prepared.length }}</h3><ul class="mt-1 space-y-1"><li v-for="item in report.wizard.prepared" :key="item.spell_version_id">{{ item.spell_name }}</li></ul></div>
                <div><h3 class="font-semibold">Ritual-only · {{ report.wizard.ritual_only.length }}</h3><ul class="mt-1 space-y-1"><li v-for="item in report.wizard.ritual_only" :key="item.spellbook_entry_id">{{ item.spell_name }}</li></ul></div>
            </div>
        </section>

        <section class="panel">
            <h2 class="section-title">Invalid or orphaned selections</h2>
            <p v-if="!report.invalid_selections.length" class="mt-3 text-sm text-stone-500"><span aria-hidden="true">✓</span> Every visible selection is current and eligible.</p>
            <ul v-else class="mt-3 space-y-2 text-sm">
                <li v-for="slot in report.invalid_selections" :key="slot.id" class="rounded-md border border-amber-300 p-3 dark:border-amber-900">
                    <span aria-hidden="true">⚠</span> <strong>{{ slot.spell_name ?? 'Empty slot' }}</strong> in {{ slot.source }} · {{ slot.label }}
                    <p class="mt-1 text-xs text-stone-600 dark:text-stone-400">{{ slot.invalid_reason ?? slot.orphan_reason ?? 'Kept as an explicit override.' }}</p>
                </li>
            </ul>
        </section>
    </aside>
</template>
