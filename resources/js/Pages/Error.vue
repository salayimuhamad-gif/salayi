<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';

const props = defineProps<{ status: number }>();

const { current } = useLocale();

/*
 * Never echo the framework's exception message. In production the status code
 * is all the visitor gets; the detail lives in the log, correlated by the
 * X-Request-Id header that RecordAuditContext sets.
 */
const messages: Record<string, Record<number, string>> = {
    ckb: {
        401: 'پێویستە بچیتە ژوورەوە',
        403: 'ڕێگەپێدانت نییە',
        404: 'ئەم پەڕەیە نەدۆزرایەوە',
        419: 'دانیشتنەکەت بەسەرچووە',
        429: 'داواکاریی زۆر زۆرە',
        500: 'هەڵەیەکی ناوخۆیی ڕوویدا',
        503: 'سیستەم لە دۆخی چاککردندایە',
    },
    ar: {
        401: 'يجب تسجيل الدخول',
        403: 'ليس لديك صلاحية',
        404: 'الصفحة غير موجودة',
        419: 'انتهت الجلسة',
        429: 'طلبات كثيرة جدًا',
        500: 'حدث خطأ داخلي',
        503: 'النظام قيد الصيانة',
    },
    en: {
        401: 'You need to sign in',
        403: 'You do not have permission',
        404: 'This page was not found',
        419: 'Your session expired',
        429: 'Too many requests',
        500: 'An internal error occurred',
        503: 'The system is under maintenance',
    },
};

const message = computed(
    () => messages[current.value]?.[props.status] ?? messages.en[props.status] ?? 'Error',
);
</script>

<template>
    <Head :title="String(status)" />

    <div class="flex min-h-full items-center justify-center px-4">
        <div class="text-center">
            <p class="numeral font-display text-5xl font-bold text-brand">{{ status }}</p>
            <p class="mt-3 text-ink-muted">{{ message }}</p>
            <Link href="/" class="mt-6 inline-block text-sm text-brand underline">
                Mulkihawler
            </Link>
        </div>
    </div>
</template>
