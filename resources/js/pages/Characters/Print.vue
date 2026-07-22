<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Ability, CastingMode, RulesEdition } from '@/types';

type Spell = {
    spell_version_id: number;
    spell_identity_id: number;
    name: string;
    edition: RulesEdition;
    level: number;
    school: string;
    casting_time: string | null;
    action_type: string | null;
    range: string | null;
    duration: string | null;
    concentration: boolean;
    ritual: boolean;
    components: string | null;
    attack_modes: string[];
    save_abilities: string[];
    casting_mode: CastingMode;
    spellcasting_ability: Ability | null;
    attack_bonus: number | null;
    save_dc: number | null;
    description: string | null;
};

type WizardEntry = {
    spellbook_entry_id: number;
    spell_name: string;
    active?: boolean;
};

type PrintableSpellList = {
    variant: 'reference' | 'full';
    text_status: 'not_requested' | 'unavailable' | 'partial' | 'available';
    character: {
        id: number;
        name: string;
        character_level: number;
        proficiency_bonus: number;
    };
    source_groups: Array<{
        source: string;
        ability: Ability | null;
        attack_bonus: number | null;
        save_dc: number | null;
        spells: Spell[];
    }>;
    unprepared_sections: Array<{
        class_name: string;
        title: string;
        ability: Ability | null;
        max_level: number;
        cantrip_note: string;
        spells: Spell[];
    }>;
    wizard: {
        spellbook: WizardEntry[];
        prepared: WizardEntry[];
        ritual_only: WizardEntry[];
        explanation: string;
    };
};

defineProps<{ spellList: PrintableSpellList }>();

const signed = (value: number | null) => value === null ? '—' : `${value >= 0 ? '+' : ''}${value}`;
const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const level = (value: number) => value === 0 ? 'Cantrip' : `Level ${value}`;
const abilities = (values: string[]) => values.map((value) => value.slice(0, 3).toUpperCase()).join('/');
const printPage = () => window.print();
</script>

<template>
    <Head :title="`${spellList.character.name} spell list`" />
    <div class="print-page min-h-screen bg-white text-stone-950" :data-variant="spellList.variant">
        <div class="print-controls border-b border-stone-300 bg-stone-100 px-4 py-3">
            <div class="mx-auto flex max-w-7xl flex-wrap items-end gap-3">
                <a :href="`/characters/${spellList.character.id}`" class="button-secondary">Back to character</a>
                <form method="get" :action="`/characters/${spellList.character.id}/print`" class="flex items-end gap-2">
                    <label class="text-sm font-medium" for="print-variant">
                        Print variant
                        <select id="print-variant" name="variant" class="field mt-1 min-w-52" :value="spellList.variant">
                            <option value="reference">Reference sheet (no spell text)</option>
                            <option value="full">Full reference (with spell text)</option>
                        </select>
                    </label>
                    <button type="submit" class="button-secondary">Change variant</button>
                </form>
                <button type="button" class="button-primary ml-auto" @click="printPage">Print</button>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-5 py-6">
            <header class="print-header border-b-2 border-stone-950 pb-3">
                <p class="text-xs font-semibold uppercase tracking-widest">{{ spellList.variant === 'full' ? 'Full spell reference' : 'Spell reference sheet' }}</p>
                <h1 class="text-2xl font-bold">{{ spellList.character.name }}</h1>
                <p class="text-sm">Character level {{ spellList.character.character_level }} · Proficiency bonus +{{ spellList.character.proficiency_bonus }}</p>
            </header>

            <aside
                v-if="spellList.variant === 'full' && spellList.text_status === 'unavailable'"
                class="text-notice mt-4 border-2 border-stone-950 p-3 text-sm"
                role="status"
                data-testid="text-unavailable"
            >
                <strong>Spell descriptions are not installed.</strong>
                This full reference includes all spell facts, but no description text. Run <code>php artisan catalog:import --with-text</code> where the local Tier 2 files are available, then print again.
            </aside>
            <aside
                v-else-if="spellList.variant === 'full' && spellList.text_status === 'partial'"
                class="text-notice mt-4 border-2 border-stone-950 p-3 text-sm"
                role="status"
                data-testid="text-partial"
            >
                <strong>Some spell descriptions are unavailable.</strong> Missing text is identified on the affected spells.
            </aside>

            <section v-if="spellList.wizard.spellbook.length" class="print-section mt-5" aria-labelledby="wizard-states-heading">
                <h2 id="wizard-states-heading" class="border-b border-stone-950 pb-1 text-lg font-bold">Wizard spellbook states</h2>
                <p class="mt-2 text-sm leading-snug">{{ spellList.wizard.explanation }}</p>
                <div class="wizard-states mt-3 grid grid-cols-3 gap-4 text-sm">
                    <article>
                        <h3 class="font-bold">Spellbook · {{ spellList.wizard.spellbook.length }}</h3>
                        <ul class="mt-1 list-disc pl-5">
                            <li v-for="entry in spellList.wizard.spellbook" :key="entry.spellbook_entry_id">
                                {{ entry.spell_name }}<template v-if="entry.active === false"> (unavailable — removed from catalog)</template>
                            </li>
                        </ul>
                    </article>
                    <article>
                        <h3 class="font-bold">Prepared · {{ spellList.wizard.prepared.length }}</h3>
                        <ul class="mt-1 list-disc pl-5"><li v-for="entry in spellList.wizard.prepared" :key="entry.spellbook_entry_id">{{ entry.spell_name }}</li></ul>
                    </article>
                    <article>
                        <h3 class="font-bold">Ritual-only · {{ spellList.wizard.ritual_only.length }}</h3>
                        <ul class="mt-1 list-disc pl-5"><li v-for="entry in spellList.wizard.ritual_only" :key="entry.spellbook_entry_id">{{ entry.spell_name }}</li></ul>
                    </article>
                </div>
            </section>

            <section v-for="group in spellList.source_groups" :key="group.source" class="print-section mt-6" :aria-labelledby="`source-${group.source.replaceAll(' ', '-')}`">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-950 pb-1">
                    <h2 :id="`source-${group.source.replaceAll(' ', '-')}`" class="text-lg font-bold">{{ group.source }}</h2>
                    <p class="text-xs">
                        <template v-if="group.ability">{{ group.ability.slice(0, 3).toUpperCase() }} · spell attack {{ signed(group.attack_bonus) }} · save DC {{ group.save_dc }}</template>
                        <template v-else>No spellcasting ability assigned</template>
                    </p>
                </div>
                <div class="spell-grid mt-3 grid gap-3" :class="spellList.variant === 'full' ? 'grid-cols-1' : 'sm:grid-cols-2'">
                    <article v-for="spell in group.spells" :key="`${spell.spell_version_id}:${spell.casting_mode}`" class="spell-card border border-stone-500 p-2.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h3 class="font-bold">{{ spell.name }}</h3>
                            <p class="text-xs">{{ level(spell.level) }} · {{ spell.school }}<template v-if="spell.edition !== '2024'"> · {{ spell.edition }}</template></p>
                        </div>
                        <dl class="spell-facts mt-1 grid grid-cols-2 gap-x-3 text-xs leading-snug">
                            <div><dt>Casting: </dt><dd>{{ spell.casting_time ?? '—' }}<template v-if="spell.action_type && spell.action_type !== spell.casting_time"> ({{ spell.action_type }})</template></dd></div>
                            <div><dt>Range: </dt><dd>{{ spell.range ?? '—' }}</dd></div>
                            <div><dt>Duration: </dt><dd>{{ spell.duration ?? '—' }}</dd></div>
                            <div><dt>Components: </dt><dd>{{ spell.components ?? '—' }}</dd></div>
                            <div><dt>Concentration: </dt><dd>{{ spell.concentration ? 'Yes' : 'No' }}</dd></div>
                            <div><dt>Ritual: </dt><dd>{{ spell.ritual ? 'Yes' : 'No' }}</dd></div>
                            <div v-if="spell.attack_bonus !== null"><dt>Spell attack: </dt><dd>{{ signed(spell.attack_bonus) }} to hit<template v-if="spell.attack_modes.length"> · {{ spell.attack_modes.map(title).join(', ') }}</template></dd></div>
                            <div v-if="spell.save_dc !== null"><dt>Saving throw: </dt><dd>DC {{ spell.save_dc }} · {{ abilities(spell.save_abilities) }}</dd></div>
                        </dl>
                        <p class="mt-1 text-xs"><strong>Access:</strong> {{ title(spell.casting_mode) }}<template v-if="spell.spellcasting_ability"> · {{ spell.spellcasting_ability.slice(0, 3).toUpperCase() }}</template></p>
                        <div v-if="spellList.variant === 'full'" class="spell-description mt-2 border-t border-stone-400 pt-2 text-sm leading-snug whitespace-pre-line">
                            <template v-if="spell.description">{{ spell.description }}</template>
                            <em v-else>Description unavailable.</em>
                        </div>
                    </article>
                </div>
            </section>

            <section v-for="section in spellList.unprepared_sections" :key="section.class_name" class="print-section unprepared-section mt-7" :aria-labelledby="`unprepared-${section.class_name}`">
                <div class="border-y-2 border-stone-950 py-2">
                    <h2 :id="`unprepared-${section.class_name}`" class="text-lg font-bold">{{ section.title }}</h2>
                    <p class="mt-1 text-xs">{{ section.cantrip_note }} Maximum preparable spell level: {{ section.max_level }}.</p>
                </div>
                <div class="spell-grid mt-3 grid gap-3" :class="spellList.variant === 'full' ? 'grid-cols-1' : 'sm:grid-cols-2'">
                    <article v-for="spell in section.spells" :key="spell.spell_version_id" class="spell-card border border-stone-500 p-2.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h3 class="font-bold">{{ spell.name }}</h3>
                            <p class="text-xs">{{ level(spell.level) }} · {{ spell.school }}</p>
                        </div>
                        <dl class="spell-facts mt-1 grid grid-cols-2 gap-x-3 text-xs leading-snug">
                            <div><dt>Casting: </dt><dd>{{ spell.casting_time ?? '—' }}<template v-if="spell.action_type && spell.action_type !== spell.casting_time"> ({{ spell.action_type }})</template></dd></div>
                            <div><dt>Range: </dt><dd>{{ spell.range ?? '—' }}</dd></div>
                            <div><dt>Duration: </dt><dd>{{ spell.duration ?? '—' }}</dd></div>
                            <div><dt>Components: </dt><dd>{{ spell.components ?? '—' }}</dd></div>
                            <div><dt>Concentration: </dt><dd>{{ spell.concentration ? 'Yes' : 'No' }}</dd></div>
                            <div><dt>Ritual: </dt><dd>{{ spell.ritual ? 'Yes' : 'No' }}</dd></div>
                            <div v-if="spell.attack_bonus !== null"><dt>Spell attack: </dt><dd>{{ signed(spell.attack_bonus) }} to hit<template v-if="spell.attack_modes.length"> · {{ spell.attack_modes.map(title).join(', ') }}</template></dd></div>
                            <div v-if="spell.save_dc !== null"><dt>Saving throw: </dt><dd>DC {{ spell.save_dc }} · {{ abilities(spell.save_abilities) }}</dd></div>
                        </dl>
                        <div v-if="spellList.variant === 'full'" class="spell-description mt-2 border-t border-stone-400 pt-2 text-sm leading-snug whitespace-pre-line">
                            <template v-if="spell.description">{{ spell.description }}</template>
                            <em v-else>Description unavailable.</em>
                        </div>
                    </article>
                </div>
            </section>
        </main>
    </div>
</template>

<style>
.spell-facts dt {
    display: inline;
    font-weight: 700;
}

.spell-facts dd {
    display: inline;
}

@page {
    margin: 0.5in;
}

@media print {
    html,
    body,
    #app,
    .print-page {
        background: #fff !important;
        color: #000 !important;
        font-size: 10.5pt;
    }

    .print-controls {
        display: none !important;
    }

    .print-page main {
        max-width: none !important;
        padding: 0 !important;
    }

    .print-header,
    .text-notice,
    .wizard-states article,
    .spell-card,
    .unprepared-section > div:first-child {
        break-inside: avoid-page;
        page-break-inside: avoid;
    }

    .print-section {
        break-before: auto;
        page-break-before: auto;
    }

    .print-section h2,
    .print-section h3 {
        break-after: avoid-page;
        page-break-after: avoid;
    }

    .spell-grid {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.16in;
    }

    .print-page[data-variant='full'] .spell-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .spell-description {
        font-size: 10pt;
        orphans: 3;
        widows: 3;
    }

    .wizard-states {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    * {
        border-color: #000 !important;
        box-shadow: none !important;
        text-shadow: none !important;
    }
}
</style>
