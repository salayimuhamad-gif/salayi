<script setup lang="ts">
defineProps<{ modelValue: boolean; label: string; description?: string; disabled?: boolean }>();
defineEmits<{ 'update:modelValue': [boolean] }>();
</script>

<template>
    <div class="flex items-start justify-between gap-4 py-3">
        <div class="min-w-0">
            <p class="text-sm font-medium text-ink">{{ label }}</p>
            <p v-if="description" class="mt-0.5 text-xs text-ink-muted">{{ description }}</p>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="label"
            :disabled="disabled"
            class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-calm
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent
                   focus-visible:ring-offset-2 focus-visible:ring-offset-surface
                   disabled:cursor-not-allowed disabled:opacity-40"
            :class="modelValue ? 'bg-brand' : 'bg-line'"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <!--
              start-0.5 / translate-x rather than left/right: under RTL the knob
              must travel the other way, and a physical property would send it
              off the wrong edge of the track.
            -->
            <span
                class="absolute top-0.5 start-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 ease-calm rtl:-scale-x-100"
                :class="modelValue ? 'translate-x-5 rtl:-translate-x-5' : 'translate-x-0'"
            />
        </button>
    </div>
</template>
