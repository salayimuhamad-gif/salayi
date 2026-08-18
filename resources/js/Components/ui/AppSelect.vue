<script setup lang="ts">
import { computed, useId } from 'vue';

withDefaults(defineProps<{
    modelValue: string | number | null;
    label: string;
    options: Array<{ value: string | number; label: string; depth?: number }>;
    error?: string | null;
    placeholder?: string;
    required?: boolean;
}>(), { error: null, placeholder: undefined, required: false });

defineEmits<{ 'update:modelValue': [string] }>();

const id = useId();
const errorId = computed(() => `${id}-error`);
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium text-ink">
            {{ label }}
            <span v-if="required" class="text-negative" aria-hidden="true">*</span>
        </label>

        <select
            :id="id"
            :value="modelValue ?? ''"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? errorId : undefined"
            class="min-h-11 w-full rounded-field border bg-surface-raised px-3 py-2.5 text-base text-ink
                   transition-colors focus:outline-none focus:ring-2 focus:ring-accent
                   focus:ring-offset-1 focus:ring-offset-surface"
            :class="error ? 'border-negative' : 'border-line-strong focus:border-accent'"
            @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option v-if="placeholder" value="">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">
                <!-- Non-breaking spaces indent the area hierarchy; a select
                     cannot nest, so depth is shown rather than structured. -->
                {{ '\u00A0'.repeat((option.depth ?? 0) * 2) + option.label }}
            </option>
        </select>

        <p v-if="error" :id="errorId" role="alert" class="text-xs text-negative">{{ error }}</p>
    </div>
</template>
