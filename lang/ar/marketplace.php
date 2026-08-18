<?php

declare(strict_types=1);

return [
    'create' => 'إنشاء عرض',
    'empty' => 'لا توجد عروض',
    'empty_hint' => 'ستظهر العروض هنا',
    'awaiting_moderation' => 'بانتظار المراجعة',
    'awaiting_hint' => 'لا يمكن النشر دون اعتماد المراجع',
    'published_offers' => 'منشور',
    'queue_only' => 'طابور المراجعة فقط',
    'no_company' => 'بلا شركة',
    'history' => 'سجل الحالة',
    'as_moderator' => 'كمراجع',

    'public' => [
        'title' => 'العروض',
        'search' => 'ابحث بالاسم...',
        'budget' => 'أعلى ميزانية',
        'none' => 'لا توجد عروض',
        'none_hint' => 'غيّر عوامل التصفية',
        'terms' => 'الشروط',
    ],

    'fields' => [
        'title_ckb' => 'العنوان (كردي)',
        'title_en' => 'العنوان (إنجليزي)',
        'offer_type' => 'نوع العرض',
        'company_hint' => '⚠ تعني شركة غير موثقة',
        'precision' => 'دقة الموقع',
        'details' => 'التفاصيل',
        'price' => 'السعر',
        'rooms' => 'عدد الغرف',
        'sponsored_hint' => 'الدفع لا يشتري موضعاً طبيعياً',
    ],
    'precision' => [
        'exact' => 'دقيق',
        'approximate' => 'تقريبي',
        'area_only' => 'المنطقة فقط',
    ],

    'statuses' => [
        'draft' => 'مسودة',
        'submitted' => 'مُرسل',
        'under_review' => 'قيد المراجعة',
        'changes_requested' => 'مطلوب تعديلات',
        'approved' => 'معتمد',
        'scheduled' => 'مجدول',
        'published' => 'منشور',
        'paused' => 'موقوف مؤقتاً',
        'expired' => 'منتهي',
        'rejected' => 'مرفوض',
        'archived' => 'مؤرشف',
    ],
    'offer_types' => [
        'sale' => 'بيع',
        'rent' => 'إيجار',
        'investment' => 'استثمار',
        'pre_launch' => 'ما قبل الإطلاق',
    ],
    'sponsorship' => [
        'sponsored' => 'مُموّل',
        'paid_placement' => 'موضع مدفوع',
        'organic_results' => 'النتائج الطبيعية',
        'sponsored_section' => 'قسم الإعلانات',
        'disclosure_notice' => 'هذه النتائج مدفوعة ومفصولة عن النتائج الطبيعية',
        'not_ranked_by_payment' => 'النتائج الطبيعية لا تُرتب بالدفع',
    ],
    'location_precision' => [
        'exact' => 'موقع دقيق',
        'approximate' => 'موقع تقريبي',
        'area_only' => 'المنطقة فقط',
    ],
    'errors' => [
        'project_not_eligible' => 'هذا المشروع ليس لشركتك',
        'owner_required' => 'يجب تحديد شركة',
        'exact_needs_coordinates' => 'الموقع الدقيق يتطلب إحداثيات',
        'illegal_transition' => 'لا يمكن إجراء هذا التغيير',
        'moderator_required' => 'المراجع فقط يمكنه ذلك',
        'missing_disclosure' => 'يجب الإفصاح عن الإعلان',
        'offer_under_moderation' => 'هذا العرض قيد المراجعة ولا يمكن تعديله',
    ],
];
