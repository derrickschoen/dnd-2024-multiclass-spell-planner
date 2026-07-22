import { ref, computed } from 'vue';
import { defineStore } from 'pinia';
import { parseMutationResponse, responseMessage } from '@/inertia-boundary';
import type { CharacterCommand, Workspace } from '@/types';

interface MutationResponse {
    inverse: CharacterCommand;
    revision: number;
    workspace: Workspace;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export const useCharacterStore = defineStore('character', () => {
    const workspace = ref<Workspace | null>(null);
    const undoStack = ref<CharacterCommand[]>([]);
    const redoStack = ref<CharacterCommand[]>([]);
    const saving = ref(false);
    const error = ref<string | null>(null);
    const stale = ref(false);

    const canUndo = computed(() => undoStack.value.length > 0 && !saving.value);
    const canRedo = computed(() => redoStack.value.length > 0 && !saving.value);

    function initialize(initial: Workspace): void {
        workspace.value = initial;
        undoStack.value = [];
        redoStack.value = [];
        error.value = null;
        stale.value = false;
    }

    async function post(command: CharacterCommand): Promise<MutationResponse> {
        if (!workspace.value) throw new Error('The character workspace is not initialized.');
        const response = await fetch(`/characters/${workspace.value.report.character.id}/mutations`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                operation_uuid: crypto.randomUUID(),
                expected_revision: workspace.value.revision,
                command,
            }),
        });
        const body: unknown = await response.json();
        if (!response.ok) {
            if (response.status === 409) stale.value = true;
            throw new Error(responseMessage(body, 'The change could not be saved.'));
        }
        return parseMutationResponse(body);
    }

    async function execute(command: CharacterCommand): Promise<void> {
        if (saving.value) return;
        saving.value = true;
        error.value = null;
        try {
            const response = await post(command);
            workspace.value = response.workspace;
            undoStack.value.push(response.inverse);
            redoStack.value = [];
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : 'The change could not be saved.';
        } finally {
            saving.value = false;
        }
    }

    async function undo(): Promise<void> {
        if (!canUndo.value) return;
        const command = undoStack.value.pop();
        if (!command) return;
        saving.value = true;
        error.value = null;
        try {
            const response = await post(command);
            workspace.value = response.workspace;
            redoStack.value.push(response.inverse);
        } catch (caught) {
            undoStack.value.push(command);
            error.value = caught instanceof Error ? caught.message : 'Undo failed.';
        } finally {
            saving.value = false;
        }
    }

    async function redo(): Promise<void> {
        if (!canRedo.value) return;
        const command = redoStack.value.pop();
        if (!command) return;
        saving.value = true;
        error.value = null;
        try {
            const response = await post(command);
            workspace.value = response.workspace;
            undoStack.value.push(response.inverse);
        } catch (caught) {
            redoStack.value.push(command);
            error.value = caught instanceof Error ? caught.message : 'Redo failed.';
        } finally {
            saving.value = false;
        }
    }

    return { workspace, saving, error, stale, canUndo, canRedo, initialize, execute, undo, redo };
});
