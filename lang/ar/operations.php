<?php

declare(strict_types=1);

return [
    'audit' => [
        'append_only_notice' => 'هذا السجل للإضافة فقط — لا يمكن تعديله أو حذفه',
        'action' => 'الإجراء',
        'severity' => 'الأهمية',
        'subject' => 'الموضوع',
        'actor' => 'المنفذ',
        'system' => 'النظام',
        'context' => 'التفاصيل',
        'request_id' => 'معرف الطلب',
        'empty' => 'لا توجد سجلات',
        'empty_hint' => 'ستظهر الإجراءات الإدارية هنا',
    ],
    'health' => [
        'queue' => 'طابور المهام',
        'queue_empty' => 'الطابور فارغ',
        'queue_backed_up' => 'الطابور متراكم — قد يكون العامل متوقفاً',
        'failed_jobs' => 'المهام الفاشلة',
        'failures_notice' => 'فشلت مهمة. ذلك العمل لم يُنفذ إطلاقاً',
        'no_heartbeat' => 'لا يوجد نبض مسجل',
        'data_quality' => 'فجوات جودة البيانات',
        'data_quality_hint' => 'هذه لا تسبب أخطاء لكنها لا تعمل بصمت',
    ],
    'gaps' => [
        'projects_published_without_source' => 'مشاريع منشورة بلا مصدر',
        'projects_without_geometry' => 'مشاريع بلا موقع',
        'projects_never_verified' => 'مشاريع منشورة غير موثقة',
        'places_without_source' => 'أماكن بلا مصدر',
        'areas_without_boundary' => 'مناطق بلا حدود',
        'stale_nearby_snapshots' => 'حسابات قريبة قديمة',
    ],
];
