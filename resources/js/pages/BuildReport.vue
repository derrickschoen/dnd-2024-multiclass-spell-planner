<script setup lang="ts">
type SlotRow = { level: number; count: number };
type ClassRow = {
    name: string;
    subclass: string | null;
    class_level: number;
    spellcasting_ability: string | null;
    progression_type: string;
    prepared_count: number;
    max_preparable_level: number;
};
type AccessRoute = {
    spell_identity_id: number;
    spell_version_id: number;
    spell_name: string;
    spell_level: number;
    source_name: string;
    slot_key: string | null;
    casting_mode: string;
    spellcasting_ability: string | null;
    attack_bonus: number | null;
    save_dc: number | null;
};
type WizardEntry = {
    spellbook_entry_id: number;
    spell_name: string;
    level: number;
    active: boolean;
    acquisition?: string;
};
type DuplicateAssessment = {
    spell_identity_id: number;
    spell_name: string;
    category: 'wasteful' | 'redundant_intentional' | 'conflicting_version' | 'none';
    sources: string[];
    slots: string[];
    explanation: string;
};
type Report = {
    character: {
        name: string;
        character_level: number;
        proficiency_bonus: number;
        abilities: Record<string, number>;
    };
    caster: { caster_level: number; slots: SlotRow[] };
    classes: ClassRow[];
    preparation_callout: string;
    access_routes: AccessRoute[];
    wizard: {
        spellbook: WizardEntry[];
        prepared: WizardEntry[];
        ritual_only: WizardEntry[];
        explanation: string;
    };
    duplicate_assessments: DuplicateAssessment[];
};

defineProps<{ report: Report }>();

const titleCase = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const signed = (value: number | null) => (value === null ? '—' : `${value >= 0 ? '+' : ''}${value}`);
const spellLevel = (level: number) => (level === 0 ? 'Cantrip' : level < 0 ? 'Unknown' : `Level ${level}`);
</script>

<template>
    <main class="min-h-screen bg-stone-950 px-4 py-10 text-stone-100 sm:px-8">
        <div class="mx-auto max-w-7xl space-y-8">
            <header class="border-b border-amber-500/30 pb-6">
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-amber-400">Read-only build report</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">{{ report.character.name }}</h1>
                <p class="mt-2 text-stone-400">Character level {{ report.character.character_level }} · Proficiency bonus +{{ report.character.proficiency_bonus }}</p>
            </header>

            <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6" aria-labelledby="abilities-heading">
                <h2 id="abilities-heading" class="sr-only">Ability scores</h2>
                <article v-for="(score, ability) in report.character.abilities" :key="ability" class="rounded-lg border border-stone-800 bg-stone-900 p-4">
                    <p class="text-xs uppercase tracking-wider text-stone-500">{{ ability.slice(0, 3) }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ score }}</p>
                </article>
            </section>

            <section class="grid gap-6 lg:grid-cols-[1fr_2fr]">
                <article class="rounded-xl border border-stone-800 bg-stone-900 p-6">
                    <h2 class="text-lg font-semibold">Shared spell slots</h2>
                    <p class="mt-1 text-sm text-stone-400">Multiclass caster level {{ report.caster.caster_level }}</p>
                    <table class="mt-5 w-full text-left text-sm">
                        <thead class="text-stone-500"><tr><th class="pb-2">Spell level</th><th class="pb-2 text-right">Slots</th></tr></thead>
                        <tbody>
                            <tr v-for="slot in report.caster.slots" :key="slot.level" class="border-t border-stone-800">
                                <td class="py-2">{{ slot.level }}</td><td class="py-2 text-right font-semibold">{{ slot.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </article>

                <article class="rounded-xl border border-stone-800 bg-stone-900 p-6">
                    <h2 class="text-lg font-semibold">Class preparation limits</h2>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-stone-500"><tr><th class="pb-2">Class</th><th class="pb-2">Ability</th><th class="pb-2 text-right">Maximum spell level</th></tr></thead>
                            <tbody>
                                <tr v-for="row in report.classes" :key="row.name" class="border-t border-stone-800">
                                    <td class="py-2 font-medium">{{ row.name }} {{ row.class_level }}</td>
                                    <td class="py-2">{{ row.spellcasting_ability ? titleCase(row.spellcasting_ability) : '—' }}</td>
                                    <td class="py-2 text-right">{{ spellLevel(row.max_preparable_level) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <aside class="rounded-xl border border-amber-400/50 bg-amber-400/10 p-5" data-testid="preparation-callout">
                <h2 class="font-semibold text-amber-300">Slots possessed are not spells unlocked</h2>
                <p class="mt-2 text-sm leading-6 text-amber-100/90">{{ report.preparation_callout }}</p>
            </aside>

            <section class="rounded-xl border border-stone-800 bg-stone-900 p-6">
                <h2 class="text-lg font-semibold">Spell access routes</h2>
                <p class="mt-1 text-sm text-stone-400">Every castable route is shown with the source and the math used at the table.</p>
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left text-sm">
                        <thead class="text-stone-500"><tr><th class="pb-2">Spell</th><th class="pb-2">Source</th><th class="pb-2">Access</th><th class="pb-2">Ability</th><th class="pb-2 text-right">Attack</th><th class="pb-2 text-right">Save DC</th></tr></thead>
                        <tbody>
                            <tr v-for="route in report.access_routes" :key="`${route.spell_version_id}:${route.source_name}:${route.slot_key ?? route.casting_mode}`" class="border-t border-stone-800">
                                <td class="py-3"><span class="font-medium text-white">{{ route.spell_name }}</span><span class="ml-2 text-xs text-stone-500">{{ spellLevel(route.spell_level) }}</span></td>
                                <td class="py-3">{{ route.source_name }}</td>
                                <td class="py-3">{{ titleCase(route.casting_mode) }}</td>
                                <td class="py-3">{{ route.spellcasting_ability ? titleCase(route.spellcasting_ability) : '—' }}</td>
                                <td class="py-3 text-right">{{ signed(route.attack_bonus) }}</td>
                                <td class="py-3 text-right">{{ route.save_dc ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-stone-800 bg-stone-900 p-6">
                <h2 class="text-lg font-semibold">Wizard spellbook access</h2>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-stone-400">{{ report.wizard.explanation }}</p>
                <div class="mt-5 grid gap-5 md:grid-cols-3">
                    <article>
                        <h3 class="text-sm font-semibold text-stone-300">Spellbook · {{ report.wizard.spellbook.length }}</h3>
                        <ul class="mt-2 space-y-1 text-sm"><li v-for="entry in report.wizard.spellbook" :key="entry.spellbook_entry_id">{{ entry.spell_name }} <span v-if="!entry.active" class="text-amber-300">(unavailable — removed from catalog)</span></li></ul>
                    </article>
                    <article>
                        <h3 class="text-sm font-semibold text-emerald-300">Prepared · {{ report.wizard.prepared.length }}</h3>
                        <ul class="mt-2 space-y-1 text-sm"><li v-for="entry in report.wizard.prepared" :key="entry.spellbook_entry_id">{{ entry.spell_name }}</li></ul>
                    </article>
                    <article>
                        <h3 class="text-sm font-semibold text-sky-300">Ritual-only · {{ report.wizard.ritual_only.length }}</h3>
                        <ul class="mt-2 space-y-1 text-sm"><li v-for="entry in report.wizard.ritual_only" :key="entry.spellbook_entry_id">{{ entry.spell_name }}</li></ul>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-stone-800 bg-stone-900 p-6">
                <h2 class="text-lg font-semibold">Duplicate assessment</h2>
                <div class="mt-4 space-y-3">
                    <article v-for="item in report.duplicate_assessments" :key="item.spell_identity_id" class="rounded-lg border border-stone-800 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-medium">{{ item.spell_name }}</h3>
                            <span class="rounded-full bg-stone-800 px-2 py-0.5 text-xs" :data-category="item.category">{{ titleCase(item.category) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-stone-400">{{ item.explanation }}</p>
                        <p class="mt-1 text-xs text-stone-500">Sources: {{ item.sources.join(', ') }}<template v-if="item.slots.length"> · Slots: {{ item.slots.join(', ') }}</template></p>
                    </article>
                </div>
            </section>
        </div>
    </main>
</template>
