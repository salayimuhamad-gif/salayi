<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(defineProps<{
    /*
     * Widened beyond `string` because numeric fields (unit counts, latitude)
     * legitimately model as `number | null`. A text input still emits a string;
     * coercing here keeps every call site from stringifying by hand.
     */
    modelValue: string | number | null;
    label: string;
    type?: string;
    error?: string | null;
    hint?: string | null;
    autocomplete?: string;
    required?: boolean;
    dir?: 'auto' | 'ltr' | 'rtl';
}>(), { type: 'text', error: null, hint: null, autocomplete: undefined, required: false, dir: 'auto' });

defineEmits<{ 'update:modelValue': [string] }>();

const displayValue = computed(() => (props.modelValue ?? '').toString());

const id = useId();
const errorId = computed(() => `${id}-error`);
const hintId = computed(() => `${id}-hint`);

/*
 * Email and password fields are forced LTR even inside a Sorani page. An email
 * address rendered right-to-left has its dots and @ reordered on screen, and a
 * user who cannot read back what they typed cannot tell a typo from a bug.
 */
const resolvedDir = computed(() =>
    props.dir !== 'auto' ? props.dir
    : ['email', 'password', 'url', 'tel'].includes(props.type) ? 'ltr' : undefined,
);

const describedBy = computed(() => {
    const ids: string[] = [];
    if (props.hint) ids.push(hintId.value);
    if (props.error) ids.push(errorId.value);
    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium text-ink">
            {{ label }}
            <span v-if="required" class="text-negative" aria-hidden="true">*</span>
        </label>

        <input
            :id="id"
            :type="type"
            :value="displayValue"
            :dir="resolvedDir"
            :autocomplete="autocomplete"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="w-full rounded-card border bg-surface-raised px-3 py-2.5 text-ink
                   placeholder:text-ink-faint transition-colors duration-150
                   focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1
                   focus:ring-offset-surface"
            :class="error ? 'border-negative' : 'border-line focus:border-brand'"
            @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >

        <p v-if="hint && !error" :id="hintId" class="text-xs text-ink-faint">{{ hint }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-xs text-negative">{{ error }}</p>
    </div>
</template>
