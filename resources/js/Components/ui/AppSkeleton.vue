<script setup lang="ts">
withDefaults(defineProps<{ lines?: number }>(), { lines: 3 });
</script>

<template>
    <!--
      Loading state. motion-safe: means the pulse is removed entirely under
      prefers-reduced-motion rather than merely slowed — a pulsing block is a
      vestibular trigger, and app.css already neutralises the duration.
    -->
    <div class="space-y-3" role="status" aria-live="polite" :aria-label="'loading'">
        <div
            v-for="n in lines"
            :key="n"
            class="h-4 rounded bg-surface-sunken motion-safe:animate-pulse"
            :style="{ width: `${100 - n * 8}%` }"
        />
        <span class="sr-only">Loading</span>
    </div>
</template>
