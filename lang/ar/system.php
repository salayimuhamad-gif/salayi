<?php

declare(strict_types=1);

return [
    'title' => 'إعدادات النظام',
    'intro' => 'إدارة التطبيق والبريد والخرائط وتيليغرام ومزوّد الذكاء الاصطناعي من شاشة محمية واحدة.',
    'environment_not_writable' => 'ملف .env غير قابل للكتابة. لا يمكن حفظ الإعدادات قبل تصحيح صلاحيات الملف.',
    'general' => [
        'title' => 'التطبيق',
        'description' => 'تُكتب التغييرات في ملف .env ويُعاد بناء كاش إعدادات Laravel تلقائياً.',
        'app_name' => 'اسم التطبيق',
        'app_url' => 'رابط التطبيق',
        'timezone' => 'المنطقة الزمنية',
        'default_locale' => 'اللغة الافتراضية',
        'enabled_locales' => 'اللغات المفعلة',
        'queue_connection' => 'اتصال طابور المهام',
        'save' => 'حفظ إعدادات التطبيق',
    ],
    'integrations' => [
        'title' => 'التكاملات ومفاتيح API',
        'description' => 'هذا القسم متاح للسوبر أدمن فقط. القيم السرية الحالية لا تُرسل أبداً إلى المتصفح.',
        'secret_hint' => 'اترك حقل السر فارغاً للاحتفاظ بالقيمة الحالية. فعّل خيار الحذف فقط عندما تريد إزالة القيمة فعلاً.',
        'configured' => 'مُعدّ',
        'not_configured' => 'غير مُعدّ',
        'new_secret' => 'قيمة جديدة',
        'clear_secret' => 'حذف القيمة المخزنة',
        'save' => 'حفظ التكاملات',
    ],
    'mail' => [
        'title' => 'بريد SMTP',
        'description' => 'خدمة إرسال رسائل الحساب والنظام.',
        'host' => 'مضيف SMTP',
        'port' => 'منفذ SMTP',
        'username' => 'اسم مستخدم SMTP',
        'password' => 'كلمة مرور SMTP',
        'scheme' => 'نوع التشفير',
        'from_address' => 'عنوان المرسل',
        'from_name' => 'اسم المرسل',
    ],
    'maps' => [
        'title' => 'الخرائط',
        'description' => 'اختر MapLibre أو Google Maps وأدخل إعدادات المزوّد.',
        'provider' => 'مزوّد الخريطة',
        'style_url' => 'رابط نمط MapLibre',
        'google_key' => 'مفتاح Google Maps API',
    ],
    'telegram' => [
        'title' => 'تيليغرام',
        'description' => 'بيانات البوت لتسجيل الدخول والإشعارات والتحقق من Webhook.',
        'username' => 'اسم مستخدم البوت',
        'token' => 'توكن البوت',
        'webhook_secret' => 'سر Webhook',
    ],
    'ai' => [
        'title' => 'مزوّد الذكاء الاصطناعي',
        'description' => 'واجهة متوافقة مع OpenAI للميزات الاختيارية المعتمدة على الذكاء الاصطناعي.',
        'provider' => 'المزوّد',
        'base_url' => 'الرابط الأساسي',
        'api_key' => 'مفتاح API',
        'model' => 'الموديل',
        'fallback_model' => 'الموديل الاحتياطي',
        'timeout' => 'مهلة الاتصال بالثواني',
        'monthly_limit' => 'حد التكلفة الشهري بالدولار',
    ],
    'messages' => [
        'saved' => 'تم حفظ الإعدادات وإعادة بناء كاش الإعدادات.',
        'no_changes' => 'لم تتغير أي إعدادات.',
        'write_failed' => 'تعذّر حفظ الإعدادات. راجع سجل السيرفر وصلاحيات ملف .env.',
    ],
    'validation' => [
        'default_locale_enabled' => 'يجب أن تكون اللغة الافتراضية ضمن اللغات المفعلة.',
        'maplibre_style_required' => 'رابط نمط MapLibre مطلوب عند اختيار MapLibre.',
        'google_key_required' => 'مفتاح Google Maps API مطلوب عند اختيار Google.',
        'ai_base_url_required' => 'الرابط الأساسي مطلوب عند تفعيل مزوّد الذكاء الاصطناعي.',
        'ai_model_required' => 'اسم الموديل مطلوب عند تفعيل مزوّد الذكاء الاصطناعي.',
        'ai_key_required' => 'مفتاح AI API مطلوب عند تفعيل مزوّد الذكاء الاصطناعي.',
    ],
    'flags' => [
        'market.intelligence' => [
            'label' => 'معلومات السوق',
            'description' => 'صفحات أسعار السوق والإحصاءات العامة',
        ],
        'market.indices' => [
            'label' => 'مؤشرات السوق',
            'description' => 'مؤشرات الأسعار المنشورة في صفحات السوق العامة',
        ],
        'map.explorer' => [
            'label' => 'مستكشف الخريطة',
            'description' => 'الخريطة العامة في ‎/map: المشاريع والمناطق والأماكن',
        ],
        'map.investment' => [
            'label' => 'خريطة الاستثمار',
            'description' => 'خريطة الاستثمار العامة في ‎/invest: مشاريع معتمدة بالأسعار والاتجاهات',
        ],
        'places.database' => [
            'label' => 'قاعدة الأماكن',
            'description' => 'الأماكن وفئاتها، على الخريطة العامة وفي لوحة الإدارة',
        ],
        'geography.areas' => [
            'label' => 'ملفات المناطق',
            'description' => 'صفحات ملفات المناطق العامة في ‎/areas',
        ],
        'projects.wizard' => [
            'label' => 'معالج المشاريع',
            'description' => 'معالج إنشاء المشاريع خطوة بخطوة في لوحة الإدارة',
        ],
        'content.news' => [
            'label' => 'الأخبار',
            'description' => 'قسم الأخبار العام في ‎/news',
        ],
        'advisor.residential' => [
            'label' => 'المستشار — سكني',
            'description' => 'المستشار الذكي العام للبحث السكني',
        ],
        'advisor.investment' => [
            'label' => 'المستشار — استثمار',
            'description' => 'إرشادات الاستثمار في المستشار الذكي',
        ],
        'advisor.market' => [
            'label' => 'المستشار — السوق',
            'description' => 'أسئلة السوق في المستشار الذكي',
        ],
        'advisor.voice' => [
            'label' => 'المستشار — صوت',
            'description' => 'الإدخال الصوتي للمستشار الذكي',
        ],
        'lifestyle.matching' => [
            'label' => 'مطابقة نمط الحياة',
            'description' => 'مطابقة المشاريع حسب نمط الحياة في المستشار',
        ],
        'companies.portal' => [
            'label' => 'بوابة الشركات',
            'description' => 'حسابات الشركات وصفحاتها وقسم الشركات في الإدارة',
        ],
        'companies.branches' => [
            'label' => 'فروع الشركات',
            'description' => 'إدارة الفروع داخل بوابة الشركات',
        ],
        'marketplace.offers' => [
            'label' => 'السوق المفتوح',
            'description' => 'العروض العامة في ‎/offers وإدارة السوق',
        ],
        'marketplace.owner_listings' => [
            'label' => 'إعلانات المالكين',
            'description' => 'إعلانات ينشئها مالكو العقارات بأنفسهم',
        ],
        'advertising' => [
            'label' => 'الإعلانات',
            'description' => 'الحملات الإعلانية والمواضع',
        ],
        'portfolio' => [
            'label' => 'المحفظة',
            'description' => 'محافظ العقارات الشخصية للأعضاء',
        ],
        'alerts.telegram' => [
            'label' => 'تنبيهات تيليجرام',
            'description' => 'إشعارات التنبيه الصادرة عبر تيليجرام',
        ],
        'alerts.email' => [
            'label' => 'تنبيهات البريد',
            'description' => 'إشعارات التنبيه الصادرة عبر البريد الإلكتروني',
        ],
        'alerts.push' => [
            'label' => 'تنبيهات الدفع',
            'description' => 'إشعارات الويب الفورية الصادرة',
        ],
        'pwa' => [
            'label' => 'تطبيق ويب تقدمي',
            'description' => 'سلوك التطبيق القابل للتثبيت ودعم العمل دون اتصال',
        ],
        'analytics.product' => [
            'label' => 'تحليلات المنتج',
            'description' => 'تحليلات استخدام مجهولة الهوية',
        ],
        'imports.ai_assist' => [
            'label' => 'مساعد الاستيراد الذكي',
            'description' => 'مساعدة ذكية أثناء استيراد الأسعار',
        ],
        'translations.ai_suggest' => [
            'label' => 'اقتراحات الترجمة الذكية',
            'description' => 'ترجمات مقترحة بالذكاء الاصطناعي للمراجعين',
        ],
        'public.reviews' => [
            'label' => 'التقييمات العامة',
            'description' => 'التقييمات والمراجعات العامة على المشاريع',
        ],
        'partner.api' => [
            'label' => 'واجهة الشركاء',
            'description' => 'واجهة برمجة التطبيقات الخارجية للشركاء',
        ],
    ],
];
