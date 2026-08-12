<script setup lang="ts">
withDefaults(defineProps<{ lines?: number }>(), { lines: 3 });
</script>

<template>
    <!--
      Loading state: the token skeleton with a transform-only shimmer sweep
      (public.css .mh-skeleton). The global reduced-motion rule clamps the
      sweep to a static sunken block — no pulsing, no vestibular trigger.
      Skeletons render only while data is genuinely loading; absence of data
      renders an honest empty state instead (R19).
    -->
    <div class="space-y-3" role="status" aria-live="polite" :aria-label="'loading'">
        <div
            v-for="n in lines"
            :key="n"
            class="mh-skeleton h-4"
            :style="{ width: `${100 - n * 8}%` }"
        />
        <span class="sr-only">Loading</span>
    </div>
</template>
