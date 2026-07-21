<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppShell from '@/components/AppShell.vue';
import BuildReportPanel from '@/components/BuildReportPanel.vue';
import SpellCombobox from '@/components/SpellCombobox.vue';
import { useCharacterStore } from '@/stores/character';
import type { CharacterClass, CharacterCommand, EligibleSpell, Workspace, WorkspaceSlot } from '@/types';

const props = defineProps<{ workspace: Workspace }>();
const store = useCharacterStore();
store.initialize(props.workspace);

const abilityNames = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
const selectionFilter = ref('all');
const traitFilter = ref('all');
const sourceFilter = ref('all');
const levelFilter = ref('all');
const sortKey = ref<keyof WorkspaceSlot>('source');
const sortDirection = ref<1 | -1>(1);
const newClassId = ref<number | null>(null);
const savePointLabel = ref('');
const savePointSaving = ref(false);
const overrideNotes = ref<Record<number, string>>({});
const comboboxes = ref<Record<number, InstanceType<typeof SpellCombobox> | null>>({});

const current = computed(() => store.workspace!);
const report = computed(() => current.value.report);
const availableToAdd = computed(() => current.value.available_classes.filter(
    (option) => !current.value.classes.some((entry) => entry.class_definition_id === option.id),
));
const sources = computed(() => [...new Set(current.value.slots.map((slot) => slot.source))].sort());
const castingSources = computed(() => {
    const bySource = new Map<string, { source: string; ability: string; attack: number | null; dc: number | null }>();
    for (const slot of current.value.slots) {
        if (!slot.ability || bySource.has(slot.source)) continue;
        bySource.set(slot.source, {
            source: slot.source,
            ability: slot.ability,
            attack: slot.attack_bonus,
            dc: slot.save_dc,
        });
    }
    return [...bySource.values()];
});
const filteredSlots = computed(() => current.value.slots.filter((slot) => {
    if (selectionFilter.value === 'selected' && !slot.spell_id) return false;
    if (selectionFilter.value === 'empty' && slot.spell_id) return false;
    if (traitFilter.value === 'duplicates' && slot.duplicate_status === 'none') return false;
    if (traitFilter.value === 'rituals' && !slot.ritual) return false;
    if (traitFilter.value === 'concentration' && !slot.concentration) return false;
    if (traitFilter.value === 'non_concentration' && slot.concentration) return false;
    if (sourceFilter.value !== 'all' && slot.source !== sourceFilter.value) return false;
    if (levelFilter.value !== 'all' && slot.spell_level !== Number(levelFilter.value)) return false;
    return true;
}).sort((left, right) => {
    const a = left[sortKey.value] ?? '';
    const b = right[sortKey.value] ?? '';
    return String(a).localeCompare(String(b), undefined, { numeric: true }) * sortDirection.value;
}));

function modifier(score: number): string {
    const value = Math.floor((score - 10) / 2);
    return value >= 0 ? `+${value}` : String(value);
}

function signed(value: number | null): string {
    return value === null ? '—' : value >= 0 ? `+${value}` : String(value);
}

function title(value: string): string {
    return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function sort(column: keyof WorkspaceSlot): void {
    if (sortKey.value === column) sortDirection.value = sortDirection.value === 1 ? -1 : 1;
    else { sortKey.value = column; sortDirection.value = 1; }
}

function sortMarker(column: keyof WorkspaceSlot): string {
    return sortKey.value === column ? (sortDirection.value === 1 ? ' ↑' : ' ↓') : '';
}

function updateAbility(ability: string, event: Event): void {
    const score = Number((event.target as HTMLInputElement).value);
    if (score === report.value.character.abilities[ability]) return;
    void store.execute({ type: 'update_ability', ability, score });
}

function updateClass(entry: CharacterClass, changes: Partial<CharacterClass>): void {
    void store.execute({
        type: 'update_class',
        class_definition_id: entry.class_definition_id,
        level: changes.level ?? entry.level,
        subclass_definition_id: changes.subclass_definition_id === undefined
            ? entry.subclass_definition_id : changes.subclass_definition_id,
    });
}

function removeClass(entry: CharacterClass): void {
    if (!window.confirm(`Remove ${entry.name} and orphan its spell choices?`)) return;
    void store.execute({ type: 'update_class', class_definition_id: entry.class_definition_id, level: null });
}

function addClass(): void {
    if (!newClassId.value) return;
    void store.execute({ type: 'update_class', class_definition_id: newClassId.value, level: 1, subclass_definition_id: null });
    newClassId.value = null;
}

function selectSpell(slot: WorkspaceSlot, spell: EligibleSpell): void {
    void store.execute({ type: 'set_slot', slot_id: slot.id, mode: 'select', spell_version_id: spell.id });
}

function clearSlot(slot: WorkspaceSlot): void {
    if (!window.confirm(`Clear ${slot.spell_name ?? 'this selection'} from ${slot.label}?`)) return;
    void store.execute({ type: 'set_slot', slot_id: slot.id, mode: 'clear' });
}

function keepOverride(slot: WorkspaceSlot): void {
    const note = overrideNotes.value[slot.id]?.trim();
    if (!note) return;
    void store.execute({ type: 'set_slot', slot_id: slot.id, mode: 'keep_override', note });
}

async function replaceSlot(slot: WorkspaceSlot): Promise<void> {
    await nextTick();
    comboboxes.value[slot.id]?.focus();
}

function setCombobox(slotId: number, element: unknown): void {
    comboboxes.value[slotId] = element as InstanceType<typeof SpellCombobox> | null;
}

async function savePoint(): Promise<void> {
    if (!savePointLabel.value.trim() || savePointSaving.value) return;
    savePointSaving.value = true;
    try {
        const response = await fetch(`/characters/${report.value.character.id}/save-points`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify({ label: savePointLabel.value.trim() }),
        });
        const body = await response.json() as { workspace: Workspace; message?: string };
        if (!response.ok) throw new Error(body.message ?? 'Save point failed.');
        if (store.workspace) store.workspace.save_points = body.workspace.save_points;
        savePointLabel.value = '';
    } finally {
        savePointSaving.value = false;
    }
}

async function restoreSavePoint(id: number, label: string): Promise<void> {
    if (!window.confirm(`Restore “${label}”? Current unsaved history will be replaced, but this restore can be undone.`)) return;
    const response = await fetch(`/characters/${report.value.character.id}/save-points/${id}/command`, { headers: { Accept: 'application/json' } });
    const body = await response.json() as { command: CharacterCommand };
    if (response.ok) await store.execute(body.command);
}

function keyboardShortcuts(event: KeyboardEvent): void {
    if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') return;
    const target = event.target as HTMLElement;
    if (target.matches('input, textarea, select, [contenteditable="true"]')) return;
    event.preventDefault();
    if (event.shiftKey) void store.redo();
    else void store.undo();
}

onMounted(() => window.addEventListener('keydown', keyboardShortcuts));
onBeforeUnmount(() => window.removeEventListener('keydown', keyboardShortcuts));
</script>

<template>
    <Head :title="report.character.name" />
    <AppShell :title="report.character.name" :subtitle="`Level ${report.character.character_level} · revision ${current.revision}`">
        <template #actions>
            <span class="hidden text-xs text-stone-500 md:inline" aria-live="polite">{{ store.saving ? 'Saving…' : 'Autosaved' }}</span>
            <button type="button" class="button-secondary" :disabled="!store.canUndo" title="Ctrl+Z" @click="store.undo">↶ Undo</button>
            <button type="button" class="button-secondary" :disabled="!store.canRedo" title="Ctrl+Shift+Z" @click="store.redo">↷ Redo</button>
        </template>

        <div v-if="store.error" class="mx-auto mt-4 max-w-[1800px] px-4 sm:px-6" role="alert">
            <div class="rounded-md border border-red-400 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                <strong>Could not save:</strong> {{ store.error }}
                <a v-if="store.stale" :href="`/characters/${report.character.id}`" class="ml-2 underline">Reload this character</a>
            </div>
        </div>

        <main class="mx-auto grid max-w-[1800px] gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_23rem]">
            <div class="min-w-0 space-y-5">
                <section class="panel">
                    <h2 class="section-title">Ability scores</h2>
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        <label v-for="ability in abilityNames" :key="ability" class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">
                            {{ ability.slice(0, 3) }}
                            <input class="field mt-1 w-full" type="number" min="1" max="30" :value="report.character.abilities[ability]" :disabled="store.saving" @change="updateAbility(ability, $event)" />
                            <span class="mt-1 block text-center text-sm text-stone-900 dark:text-stone-100">modifier {{ modifier(report.character.abilities[ability] ?? 10) }}</span>
                        </label>
                    </div>
                    <div v-if="castingSources.length" class="mt-4 border-t border-stone-200 pt-3 dark:border-stone-800">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">Resulting casting numbers by source</h3>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span v-for="source in castingSources" :key="source.source" class="status-badge status-neutral">
                                {{ source.source }} · {{ source.ability.slice(0, 3).toUpperCase() }} · attack {{ signed(source.attack) }} · DC {{ source.dc ?? '—' }}
                            </span>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title">Classes</h2><span class="text-xs text-stone-500">Autosaves on change</span></div>
                    <div class="mt-3 space-y-2">
                        <div v-for="entry in current.classes" :key="entry.id" class="grid items-end gap-2 rounded-md border border-stone-200 p-3 sm:grid-cols-[1fr_6rem_1fr_auto] dark:border-stone-800">
                            <div class="text-sm font-semibold">{{ entry.name }}</div>
                            <label class="text-xs">Level<input class="field mt-1 w-full" type="number" min="1" max="20" :value="entry.level" :disabled="store.saving" @change="updateClass(entry, { level: Number(($event.target as HTMLInputElement).value) })" /></label>
                            <label class="text-xs">Subclass<select class="field mt-1 w-full" :value="entry.subclass_definition_id ?? ''" :disabled="store.saving || !entry.subclasses.length" @change="updateClass(entry, { subclass_definition_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"><option value="">None</option><option v-for="subclass in entry.subclasses" :key="subclass.id" :value="subclass.id">{{ subclass.name }}</option></select></label>
                            <button type="button" class="button-danger" :disabled="store.saving" @click="removeClass(entry)">Remove</button>
                        </div>
                        <p v-if="!current.classes.length" class="rounded-md border border-dashed border-stone-300 p-6 text-center text-sm text-stone-500 dark:border-stone-700">No classes yet. Add one below to generate its spell slots.</p>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <label class="sr-only" for="add-class">Class to add</label>
                        <select id="add-class" v-model="newClassId" class="field min-w-52"><option :value="null">Choose a class…</option><option v-for="option in availableToAdd" :key="option.id" :value="option.id">{{ option.name }}</option></select>
                        <button type="button" class="button-primary" :disabled="!newClassId || store.saving" @click="addClass">Add class</button>
                    </div>
                </section>

                <section class="panel p-0">
                    <div class="border-b border-stone-200 p-4 dark:border-stone-800">
                        <h2 class="section-title">Spell choice slots</h2>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            <label class="filter-label">Selection<select v-model="selectionFilter" class="field mt-1"><option value="all">Selected + empty</option><option value="selected">Selected only</option><option value="empty">Empty only</option></select></label>
                            <label class="filter-label">Traits<select v-model="traitFilter" class="field mt-1"><option value="all">All traits</option><option value="duplicates">Duplicates</option><option value="rituals">Rituals</option><option value="concentration">Concentration</option><option value="non_concentration">Non-concentration</option></select></label>
                            <label class="filter-label">Source<select v-model="sourceFilter" class="field mt-1"><option value="all">All sources</option><option v-for="source in sources" :key="source" :value="source">{{ source }}</option></select></label>
                            <label class="filter-label">Selected spell level<select v-model="levelFilter" class="field mt-1"><option value="all">All levels</option><option v-for="level in 10" :key="level - 1" :value="String(level - 1)">Level {{ level - 1 }}</option></select></label>
                        </div>
                        <p class="mt-2 text-xs text-stone-500">Showing {{ filteredSlots.length }} of {{ current.slots.length }} slots. Search results always contain eligible spells only.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="slot-table">
                            <thead><tr>
                                <th><button @click="sort('source')">Source{{ sortMarker('source') }}</button></th>
                                <th><button @click="sort('label')">Slot label{{ sortMarker('label') }}</button></th>
                                <th><button @click="sort('bucket')">Bucket{{ sortMarker('bucket') }}</button></th>
                                <th>Level range</th><th>Current selection</th><th>Ability</th><th>Attack/DC</th><th>Concentration</th><th>Ritual</th><th>Duplicate</th><th>State</th>
                            </tr></thead>
                            <tbody>
                                <template v-for="slot in filteredSlots" :key="slot.id">
                                    <tr :class="slot.eligibility === 'invalid' || slot.state !== 'active' ? 'bg-amber-50 dark:bg-amber-950/40' : ''">
                                        <td class="font-medium">{{ slot.source }}</td><td>{{ slot.label }}</td><td>{{ title(slot.bucket) }}</td><td>L{{ slot.level_min }}–{{ slot.level_max }}</td>
                                        <td><span v-if="slot.locked" class="font-medium">{{ slot.spell_name }}</span><SpellCombobox v-else :ref="(element) => setCombobox(slot.id, element)" :character-id="report.character.id" :slot-id="slot.id" :model-value="slot.spell_name" :invalid="slot.eligibility === 'invalid' || slot.state !== 'active'" :disabled="store.saving" @select="selectSpell(slot, $event)" /></td>
                                        <td>{{ slot.ability ? slot.ability.slice(0, 3).toUpperCase() : '—' }}</td><td>{{ signed(slot.attack_bonus) }} / {{ slot.save_dc ?? '—' }}</td>
                                        <td>{{ slot.concentration ? '◆ Yes' : '— No' }}</td><td>{{ slot.ritual ? '◇ Yes' : '— No' }}</td>
                                        <td><span v-if="slot.duplicate_status !== 'none'" class="status-badge status-warning">⚠ {{ title(slot.duplicate_status) }}</span><span v-else>— None</span></td>
                                        <td><span class="status-badge" :class="slot.state === 'active' && slot.eligibility !== 'invalid' ? 'status-ok' : 'status-warning'">{{ slot.state === 'active' && slot.eligibility !== 'invalid' ? '✓' : '⚠' }} {{ title(slot.state === 'active' ? slot.eligibility : slot.state) }}</span></td>
                                    </tr>
                                    <tr v-if="slot.eligibility === 'invalid' || slot.state !== 'active'" class="bg-amber-50 dark:bg-amber-950/40">
                                        <td colspan="11" class="border-b border-amber-300 px-3 py-3 dark:border-amber-900">
                                            <div class="flex flex-wrap items-end gap-2 text-sm">
                                                <p class="mr-auto max-w-2xl"><strong><span aria-hidden="true">⚠</span> Selection needs attention.</strong> {{ slot.invalid_reason ?? slot.orphan_reason ?? 'This selection is being kept as an explicit override.' }}<template v-if="slot.override_note"> Note: {{ slot.override_note }}</template></p>
                                                <button type="button" class="button-secondary" @click="replaceSlot(slot)">Replace</button>
                                                <label class="text-xs">Override note<input v-model="overrideNotes[slot.id]" class="field mt-1 w-56" placeholder="Why this house rule is allowed" /></label>
                                                <button type="button" class="button-secondary" :disabled="!overrideNotes[slot.id]?.trim()" @click="keepOverride(slot)">Keep as override</button>
                                                <button type="button" class="button-danger" @click="clearSlot(slot)">Clear</button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!filteredSlots.length"><td colspan="11" class="py-12 text-center text-sm text-stone-500">No slots match these filters. Clear a filter to see more choices.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel">
                    <h2 class="section-title">Save points</h2>
                    <form class="mt-3 flex flex-wrap gap-2" @submit.prevent="savePoint"><label class="min-w-60 flex-1 text-sm">Save point name<input v-model="savePointLabel" class="field mt-1 w-full" maxlength="120" placeholder="Before changing Wizard levels" required /></label><button class="button-primary self-end" type="submit" :disabled="savePointSaving || !savePointLabel.trim()">Save snapshot</button></form>
                    <ul v-if="current.save_points.length" class="mt-4 divide-y divide-stone-200 text-sm dark:divide-stone-800"><li v-for="point in current.save_points" :key="point.id" class="flex items-center justify-between gap-3 py-2"><span><strong>{{ point.label }}</strong> <span class="text-xs text-stone-500">{{ point.created_at }}</span></span><button type="button" class="button-secondary" @click="restoreSavePoint(point.id, point.label)">Restore</button></li></ul>
                    <p v-else class="mt-3 text-sm text-stone-500">No save points yet. Use one before a large multiclass experiment.</p>
                </section>
            </div>

            <BuildReportPanel :report="report" />
        </main>
    </AppShell>
</template>
