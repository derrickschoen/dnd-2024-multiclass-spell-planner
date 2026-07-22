<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { parseEligibleSpellResponse } from '@/inertia-boundary';
import type { EligibleSpell } from '@/types';

const props = defineProps<{
    characterId: number;
    slotId: number;
    modelValue: string | null;
    disabled?: boolean;
    invalid?: boolean;
}>();
const emit = defineEmits<{ select: [spell: EligibleSpell]; open: [] }>();

const query = ref(props.modelValue ?? '');
const open = ref(false);
const loading = ref(false);
const options = ref<EligibleSpell[]>([]);
const activeIndex = ref(0);
const input = ref<HTMLInputElement | null>(null);
let requestSequence = 0;
let debounce: ReturnType<typeof setTimeout> | null = null;

const listboxId = computed(() => `spell-options-${props.slotId}`);
const activeId = computed(() => options.value[activeIndex.value] ? `spell-option-${props.slotId}-${activeIndex.value}` : undefined);

watch(() => props.modelValue, (value) => { query.value = value ?? ''; });

async function search(): Promise<void> {
    const sequence = ++requestSequence;
    loading.value = true;
    try {
        const response = await fetch(`/characters/${props.characterId}/slots/${props.slotId}/eligible-spells?q=${encodeURIComponent(query.value)}`, {
            headers: { Accept: 'application/json' },
        });
        const body = parseEligibleSpellResponse(await response.json());
        if (sequence === requestSequence) {
            options.value = body.spells;
            activeIndex.value = 0;
        }
    } finally {
        if (sequence === requestSequence) loading.value = false;
    }
}

function show(): void {
    if (props.disabled) return;
    open.value = true;
    emit('open');
    void search();
}

function onInput(): void {
    open.value = true;
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(() => { void search(); }, 140);
}

function choose(spell: EligibleSpell): void {
    query.value = spell.name;
    open.value = false;
    emit('select', spell);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!open.value) show();
        else activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && options.value[activeIndex.value]) {
        event.preventDefault();
        choose(options.value[activeIndex.value]!);
    } else if (event.key === 'Escape') {
        open.value = false;
        query.value = props.modelValue ?? '';
    }
}

function closeLater(): void {
    window.setTimeout(() => { open.value = false; query.value = props.modelValue ?? ''; }, 120);
}

defineExpose({ focus: async () => { await nextTick(); input.value?.focus(); show(); } });
</script>

<template>
    <div class="relative min-w-48">
        <input
            ref="input"
            v-model="query"
            class="field w-full py-1.5 text-sm"
            :class="invalid ? 'border-amber-500' : ''"
            type="text"
            role="combobox"
            autocomplete="off"
            :aria-label="`Spell selection for slot ${slotId}`"
            :aria-expanded="open"
            :aria-controls="listboxId"
            :aria-activedescendant="activeId"
            :aria-invalid="invalid"
            :disabled="disabled"
            placeholder="Search eligible spells…"
            @focus="show"
            @input="onInput"
            @keydown="onKeydown"
            @blur="closeLater"
        />
        <div v-if="open" :id="listboxId" role="listbox" class="absolute z-30 mt-1 max-h-64 w-80 overflow-auto rounded-md border border-stone-300 bg-white p-1 shadow-lg dark:border-stone-700 dark:bg-stone-900">
            <p v-if="loading" class="px-3 py-2 text-xs text-stone-500">Searching eligible spells…</p>
            <p v-else-if="!options.length" class="px-3 py-2 text-xs text-stone-500">No eligible spells match this search.</p>
            <button
                v-for="(spell, index) in options"
                :id="`spell-option-${slotId}-${index}`"
                :key="spell.id"
                type="button"
                role="option"
                :aria-selected="index === activeIndex"
                class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-violet-50 focus:bg-violet-50 focus:outline-none dark:hover:bg-stone-800 dark:focus:bg-stone-800"
                :class="index === activeIndex ? 'bg-violet-50 dark:bg-stone-800' : ''"
                @mousedown.prevent="choose(spell)"
            >
                <span class="font-medium">{{ spell.name }}</span>
                <span class="ml-2 text-xs text-stone-500">L{{ spell.level }} · {{ spell.school }} · {{ spell.edition }}</span>
                <span v-if="spell.ritual" class="ml-2 text-xs">Ritual</span>
                <span v-if="spell.concentration" class="ml-2 text-xs">Concentration</span>
            </button>
        </div>
    </div>
</template>
