(() => {
    'use strict';

    const STATE_KEY = '__myHawlerAdvisorLiveChatV8';
    const REQUEST_TIMEOUT_MS = 25000;

    /*
     * v7: the person on their own turns (spec §4.2). The page publishes the
     * signed-in user's processed avatar — public URL or initials material —
     * in a JSON island; read once here, used by makeAvatar below. Absent
     * island (anonymous surface, older page) degrades to v6's dot.
     */
    function readUserAvatar() {
        const island = document.querySelector('script[data-advisor-user-avatar]');
        if (!island) return null;
        try {
            const parsed = JSON.parse(island.textContent || 'null');
            if (!parsed || typeof parsed !== 'object') return null;
            return {
                thumb: typeof parsed.thumb === 'string' ? parsed.thumb : null,
                initials: typeof parsed.initials === 'string' ? parsed.initials : '',
            };
        } catch (_) {
            return null;
        }
    }
    const USER_AVATAR = readUserAvatar();

    function avatarTint(initials) {
        let hash = 0;
        for (const ch of initials) hash = (hash * 31 + ch.codePointAt(0)) % 360;
        return 'hsl(' + hash + ', 45%, 38%)';
    }
    const COPY = {
        ckb: {
            'advisor.chat.you': 'تۆ',
            'advisor.chat.assistant': 'ڕاوێژکار',
            'advisor.chat.typing': 'ڕاوێژکارەکە وەڵام دەنووسێت…',
            'advisor.chat.send_failed': 'کێشەیەکی کاتی ڕوویدا و وەڵامەکە نەگەیشت. دووبارە هەوڵ بدە.',
            'advisor.chat.not_answered': 'وەڵام نەدراوەتەوە',
            'advisor.chat.complete_hint': 'ئەنجامەکان لە ناو گفتوگۆکەدا پیشان دراون.',
            'advisor.chat.followup_hint': 'ئەنجامەکان لە سەرەوە دیارن — دەتوانیت بەردەوام بیت: پرسیار بکە، بەراورد بکە، یان داواکارییەکەت بگۆڕە.',
            'advisor.chat.start_over': 'گفتوگۆی نوێ',
            'advisor.chat.enter_hint': 'بۆ ناردن Enter دابگرە، بۆ هێڵێکی نوێ Shift + Enter.',
            'advisor.chat.quick_title': 'هەڵبژاردە خێراکان',
            'advisor.chat.live_ai': 'AI چالاکە',
        },
        ar: {
            'advisor.chat.you': 'أنت',
            'advisor.chat.assistant': 'المستشار',
            'advisor.chat.typing': 'المستشار يكتب…',
            'advisor.chat.send_failed': 'صار خلل مؤقت وما وصل الرد. جرّب مرة ثانية.',
            'advisor.chat.not_answered': 'لم تتم الإجابة',
            'advisor.chat.complete_hint': 'النتائج موجودة داخل المحادثة.',
            'advisor.chat.followup_hint': 'النتائج ظاهرة فوق — تكدر تكمل: اسأل، قارن، أو غيّر طلبك.',
            'advisor.chat.start_over': 'محادثة جديدة',
            'advisor.chat.enter_hint': 'اضغط Enter للإرسال، وShift + Enter لسطر جديد.',
            'advisor.chat.quick_title': 'خيارات سريعة',
            'advisor.chat.live_ai': 'AI مباشر',
        },
        en: {
            'advisor.chat.you': 'You',
            'advisor.chat.assistant': 'Advisor',
            'advisor.chat.typing': 'The advisor is typing…',
            'advisor.chat.send_failed': 'Something went wrong and the reply did not arrive. Please try again.',
            'advisor.chat.not_answered': 'Not answered',
            'advisor.chat.complete_hint': 'Your results are shown in the conversation.',
            'advisor.chat.followup_hint': 'Your results are above — keep going: ask questions, compare, or change your request.',
            'advisor.chat.start_over': 'New conversation',
            'advisor.chat.enter_hint': 'Press Enter to send, or Shift + Enter for a new line.',
            'advisor.chat.quick_title': 'Quick choices',
            'advisor.chat.live_ai': 'Live AI',
        },
    };

    const ICONS = {
        home: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>',
        growth: '<path d="M4 18 10 12l4 4 6-8"/><path d="M14 8h6v6"/>',
        wallet: '<path d="M4 7.5h14a2 2 0 0 1 2 2V18H4a2 2 0 0 1-2-2V7.5a3.5 3.5 0 0 1 3.5-3.5H17"/><path d="M15 12h5"/><circle cx="16" cy="12" r=".6"/>',
        apartment: '<path d="M5 21V4h10v17"/><path d="M15 9h4v12"/><path d="M8 7h1M12 7h1M8 11h1M12 11h1M8 15h1M12 15h1"/>',
        villa: '<path d="m3 11 9-7 9 7"/><path d="M5 10v11h14V10"/><path d="M8 14h3v3H8zM14 13h3v8h-3z"/>',
        house: '<path d="m3 11 9-7 9 7"/><path d="M5 10v11h14V10"/><path d="M9 21v-6h6v6"/>',
        land: '<path d="m4 17 5-10 5 10 3-6 3 6"/><path d="M3 20h18"/><circle cx="17" cy="5" r="2"/>',
        location: '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        briefcase: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M3 12h18M10 12v2h4v-2"/>',
        check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16.5 8"/>',
        close: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
        family: '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 20c.5-4 2.5-6 5.5-6s5 2 5.5 6M14 15c3-.5 5 1 6 4"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/>',
        cash: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 9H5v1m13-1h1v1M6 15H5v-1m13 1h1v-1"/>',
        card: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        split: '<path d="M5 5v3c0 3 2 4 5 4h9"/><path d="m16 9 3 3-3 3"/><path d="M5 19v-3c0-2 1-3 3-4"/>',
        shield: '<path d="M12 3 20 6v6c0 5-3 8-8 10-5-2-8-5-8-10V6l8-3Z"/><path d="m9 12 2 2 4-5"/>',
        balance: '<path d="M12 3v18M5 6h14M7 6l-4 7h8L7 6Zm10 0-4 7h8l-4-7ZM8 21h8"/>',
        bolt: '<path d="m13 2-8 12h6l-1 8 9-13h-6V2Z"/>',
        key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3m-6 0 3 3"/>',
        sparkles: '<path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2L12 3Z"/><path d="m5 14 .8 2.2L8 17l-2.2.8L5 20l-.8-2.2L2 17l2.2-.8L5 14Zm14-2 .6 1.4L21 14l-1.4.6L19 16l-.6-1.4L17 14l1.4-.6L19 12Z"/>',
        robot: '<rect x="5" y="7" width="14" height="11" rx="4"/><path d="M12 4v3M9 21h6M8 18v3m8-3v3"/><circle cx="12" cy="3" r="1"/><circle cx="9.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="14.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><path d="M9.5 15h5"/>',
    };

    function createIcon(name, className = '') {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.className.baseVal = className;
        svg.innerHTML = ICONS[name] || ICONS.sparkles;
        return svg;
    }

    function injectStyles() {
        if (document.getElementById('mh-advisor-v6-styles')) return;
        const style = document.createElement('style');
        style.id = 'mh-advisor-v6-styles';
        style.textContent = `
            .mh-ai-live-wrap{position:relative;display:grid;place-items:center;width:3.75rem;height:3.75rem;flex:0 0 auto;isolation:isolate}
            .mh-ai-live-wrap>svg{position:relative;z-index:2;width:3.5rem;height:3.5rem;filter:drop-shadow(0 10px 18px rgb(var(--mh-accent) / .18));animation:mh-ai-float 3.2s ease-in-out infinite}
            .mh-ai-live-ring,.mh-ai-live-ring::after{position:absolute;inset:.2rem;border:1px solid rgb(var(--mh-accent) / .55);border-radius:999px;content:"";animation:mh-ai-ring 2.2s ease-out infinite}
            .mh-ai-live-ring::after{inset:-.45rem;opacity:.55;animation-delay:1.1s}
            .mh-ai-live-wrap.is-thinking>svg{animation:mh-ai-think .72s ease-in-out infinite alternate}
            .mh-ai-live-wrap.is-thinking .mh-ai-live-ring{animation-duration:.9s}
            .mh-ai-live-badge{display:inline-flex;align-items:center;gap:.38rem;margin-top:.55rem;padding:.28rem .62rem;border:1px solid rgb(var(--mh-positive) / .28);border-radius:999px;background:rgb(var(--mh-positive) / .08);color:rgb(var(--mh-positive));font-size:.72rem;font-weight:700;line-height:1}
            .mh-ai-live-badge-dot{width:.42rem;height:.42rem;border-radius:999px;background:currentColor;box-shadow:0 0 0 0 rgb(var(--mh-positive) / .45);animation:mh-ai-dot 1.7s ease-out infinite}
            .mh-advisor-choice-wrap{margin-top:.9rem}
            .mh-advisor-choice-title{display:flex;align-items:center;gap:.45rem;margin-bottom:.65rem;color:rgb(var(--mh-ink-muted));font-size:.75rem;font-weight:700}
            .mh-advisor-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.62rem}
            .mh-advisor-choice{display:flex;align-items:center;gap:.7rem;min-height:4.3rem;padding:.72rem .78rem;border:1px solid rgb(var(--mh-line));border-radius:1rem;background:linear-gradient(145deg,rgb(var(--mh-surface-raised)),rgb(var(--mh-surface-sunken)));color:rgb(var(--mh-ink));text-align:start;box-shadow:0 8px 22px rgb(0 0 0 / .05);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease,background .18s ease}
            .mh-advisor-choice:hover{transform:translateY(-2px);border-color:rgb(var(--mh-accent) / .75);box-shadow:0 12px 28px rgb(var(--mh-accent) / .12)}
            .mh-advisor-choice:focus-visible{outline:2px solid rgb(var(--mh-accent));outline-offset:2px}
            .mh-advisor-choice:disabled{opacity:.55;cursor:not-allowed;transform:none}
            .mh-advisor-choice-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;flex:0 0 auto;border-radius:.85rem;background:rgb(var(--mh-accent) / .12);color:rgb(var(--mh-accent));box-shadow:inset 0 0 0 1px rgb(var(--mh-accent) / .14)}
            .mh-advisor-choice-icon svg{width:1.42rem;height:1.42rem}
            .mh-advisor-choice-label{font-size:.84rem;font-weight:700;line-height:1.45}
            .mh-mini-robot{width:1.25rem;height:1.25rem;color:rgb(var(--mh-accent));animation:mh-ai-mini 2.8s ease-in-out infinite}
            @media(min-width:720px){.mh-advisor-choice-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
            @media(max-width:420px){.mh-advisor-choice{min-height:3.85rem;padding:.62rem}.mh-advisor-choice-icon{width:2.25rem;height:2.25rem}.mh-advisor-choice-label{font-size:.78rem}}
            @keyframes mh-ai-float{0%,100%{transform:translateY(0) rotate(-1deg)}50%{transform:translateY(-5px) rotate(1deg)}}
            @keyframes mh-ai-think{from{transform:translateY(-2px) rotate(-2deg) scale(1)}to{transform:translateY(-5px) rotate(2deg) scale(1.03)}}
            @keyframes mh-ai-ring{0%{transform:scale(.82);opacity:.75}100%{transform:scale(1.25);opacity:0}}
            @keyframes mh-ai-dot{0%{box-shadow:0 0 0 0 rgb(var(--mh-positive) / .5)}100%{box-shadow:0 0 0 7px rgb(var(--mh-positive) / 0)}}
            @keyframes mh-ai-mini{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}
            @media(prefers-reduced-motion:reduce){.mh-ai-live-wrap>svg,.mh-ai-live-ring,.mh-ai-live-ring::after,.mh-ai-live-badge-dot,.mh-mini-robot{animation:none!important}.mh-advisor-choice{transition:none}}
        `;
        document.head.appendChild(style);
    }

    function enhanceLiveAvatar() {
        /*
         * v8: containment. This enhancement decorates the ADVISOR page's own
         * header, but the finder below takes the first <header> with an <h1>
         * anywhere — which on every admin page wrapped the sidebar toggle's
         * icon in the live ring and pushed the "AI live" badge into the
         * chrome (overflowing the 360/390 layouts and skewing pointer
         * coordinates), and decorated any public detail header the same way.
         * The gate is the advisor page's own root marker — deliberately NOT
         * the user-avatar island, which renders only for signed-in visitors:
         * anonymous advisor sessions must keep the ring and badge unchanged.
         */
        if (!document.querySelector('[data-advisor-chat]')) return;

        const header = Array.from(document.querySelectorAll('header')).find((node) => node.querySelector('h1'));
        if (!header) return;

        let wrap = header.querySelector('[data-mh-ai-live-wrap]');
        if (!wrap) {
            const avatar = header.querySelector('svg');
            if (!avatar) return;
            wrap = document.createElement('span');
            wrap.dataset.mhAiLiveWrap = 'true';
            wrap.className = 'mh-ai-live-wrap';
            avatar.replaceWith(wrap);
            wrap.appendChild(avatar);
            const ring = document.createElement('span');
            ring.className = 'mh-ai-live-ring';
            ring.setAttribute('aria-hidden', 'true');
            wrap.appendChild(ring);
        }

        const textColumn = header.querySelector('h1')?.parentElement;
        if (textColumn && !textColumn.querySelector('[data-mh-live-badge]')) {
            const badge = document.createElement('span');
            badge.dataset.mhLiveBadge = 'true';
            badge.className = 'mh-ai-live-badge';
            const dot = document.createElement('span');
            dot.className = 'mh-ai-live-badge-dot';
            dot.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.textContent = translation('advisor.chat.live_ai', uiLocale());
            badge.append(dot, text);
            textColumn.appendChild(badge);
        }
    }

    function setAvatarThinking(active) {
        document.querySelector('[data-mh-ai-live-wrap]')?.classList.toggle('is-thinking', Boolean(active));
    }

    function sanitizeTranscript() {
        document.querySelectorAll('ul.space-y-5 > li').forEach((item) => {
            const content = item.querySelector('p.whitespace-pre-line');
            const text = content?.textContent || '';
            if (/required_question|known_criteria|visitor_message|prompt_version/i.test(text)) {
                item.remove();
                return;
            }

            const footer = Array.from(item.querySelectorAll('div')).find((node) =>
                node.classList.contains('border-t') && node.querySelector('.mh-lux-chip')
            );
            if (footer) {
                const chips = Array.from(footer.querySelectorAll('.mh-lux-chip'));
                const firstText = chips[0]?.textContent?.trim() || '';
                const internalLabel = /scenario|سيناريو|سیناریۆ|required|fact|حقيقة|ڕاستی|analysis|تحليل|شیکردنەوە/i.test(firstText);
                if (chips.length > 1 || internalLabel) chips[0]?.remove();
                if (!footer.querySelector('.mh-lux-chip')) footer.remove();
            }
        });
    }

    let latestProps = readInitialProps();

    function readInitialProps() {
        const app = document.getElementById('app');

        try {
            return JSON.parse(app?.getAttribute('data-page') || '{}').props || {};
        } catch {
            return {};
        }
    }

    function rememberPage(event) {
        const props = event?.detail?.page?.props;
        if (props && typeof props === 'object') latestProps = props;
        window.setTimeout(enhance, 0);
    }

    function uiLocale() {
        if (/^\/ar(?:\/|$)/.test(window.location.pathname)) return 'ar';
        if (/^\/en(?:\/|$)/.test(window.location.pathname)) return 'en';
        return 'ckb';
    }

    function translation(key, locale = uiLocale()) {
        return latestProps?.translations?.[key] || COPY[locale]?.[key] || COPY[uiLocale()]?.[key] || key;
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function endpoint(suffix) {
        const path = window.location.pathname.replace(/\/$/, '');
        return path.replace(/\/advisor$/, `/advisor/${suffix}`);
    }

    function inputLocale(content, fallback = uiLocale()) {
        if (/[A-Za-z]/.test(content) && !/[\u0600-\u06ff]/.test(content)) {
            return /\b(slaw|sllaw|choni|hawler|shuqe|xanu)\b/i.test(content) ? 'ckb' : 'en';
        }

        if (/[ەڕڵۆێڤ]/u.test(content)) return 'ckb';

        if (/[\u0600-\u06ff]/.test(content)) {
            const arabicSignals = /(أريد|اريد|ميزاني|ميزانية|شقة|عقار|استثمار|للسكن|للاستثمار|أربيل|اربيل|دولار|السلام|مرحبا|شلون|وين)/u;
            const soraniSignals = /(دەوێ|دەتەوێ|بودجە|شوقە|هەولێر|وەبەرهێنان|نیشتەجێبوون|پارەدان|ناوچە)/u;
            if (arabicSignals.test(content)) return 'ar';
            if (soraniSignals.test(content)) return 'ckb';
            return fallback;
        }

        return fallback;
    }

    function messageDirection(locale, content) {
        return locale === 'en' || (!locale && inputLocale(content) === 'en') ? 'ltr' : 'rtl';
    }

    function makeAvatar(user) {
        const avatar = document.createElement('span');
        avatar.className = 'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-line '
            + (user ? 'bg-surface-sunken' : 'mh-lux-field');

        if (user && USER_AVATAR && USER_AVATAR.thumb) {
            // The processed 64px thumbnail — a public URL to this server's
            // re-encode, never a filesystem path. The wrapper stays visible
            // to assistive tech; the image itself is decorative because the
            // adjacent eyebrow already names the speaker in text.
            const img = document.createElement('img');
            img.src = USER_AVATAR.thumb;
            img.alt = '';
            img.loading = 'lazy';
            img.className = 'h-full w-full object-cover';
            avatar.appendChild(img);
            avatar.setAttribute('aria-hidden', 'true');
        } else if (user && USER_AVATAR && USER_AVATAR.initials) {
            avatar.textContent = USER_AVATAR.initials;
            avatar.style.backgroundColor = avatarTint(USER_AVATAR.initials);
            avatar.style.color = '#fff';
            avatar.classList.add('text-xs', 'font-semibold', 'select-none');
            avatar.setAttribute('aria-hidden', 'true');
        } else if (user) {
            avatar.textContent = '●';
            avatar.setAttribute('aria-hidden', 'true');
        } else {
            avatar.appendChild(createIcon('robot', 'mh-mini-robot'));
            avatar.setAttribute('aria-hidden', 'true');
        }

        return avatar;
    }

    function makeMessage(message, empty = false) {
        const user = message.role === 'user';
        const item = document.createElement('li');
        item.className = `flex gap-3${user ? ' flex-row-reverse' : ''}`;
        item.dataset.liveMessageId = String(message.id || '');
        item.appendChild(makeAvatar(user));

        const body = document.createElement('div');
        body.className = `min-w-0 flex-1${user ? ' flex flex-col items-end' : ''}`;

        const role = document.createElement('p');
        role.className = 'mh-lux-eyebrow mb-1.5';
        role.textContent = user
            ? translation('advisor.chat.you', message.locale)
            : translation('advisor.chat.assistant', message.locale);
        body.appendChild(role);

        const bubble = document.createElement('div');
        bubble.className = 'max-w-full rounded-panel px-4 py-3 '
            + (user ? 'bg-surface-sunken' : 'border border-line bg-surface-raised');

        const content = document.createElement('p');
        content.className = 'whitespace-pre-line text-sm leading-relaxed text-ink';
        content.dir = messageDirection(message.locale, message.content || '');
        content.textContent = empty ? '' : (message.content || '');
        bubble.appendChild(content);
        body.appendChild(bubble);
        item.appendChild(body);

        return { item, body, content };
    }

    function makeTyping(locale) {
        const message = makeMessage({ id: 'typing', role: 'assistant', content: '', locale }, true);
        message.item.dataset.advisorTyping = 'true';
        message.item.setAttribute('role', 'status');
        message.content.textContent = '•••';
        message.content.setAttribute('aria-label', translation('advisor.chat.typing', locale));
        let frame = 0;
        const frames = ['•', '••', '•••'];
        setAvatarThinking(true);
        const timer = window.setInterval(() => {
            message.content.textContent = frames[frame % frames.length];
            frame += 1;
        }, 340);

        return {
            item: message.item,
            stop: () => {
                window.clearInterval(timer);
                setAvatarThinking(false);
            },
        };
    }

    function ensureTranscript(textarea) {
        const column = textarea.closest('.min-w-0.space-y-5') || textarea.parentElement?.parentElement;
        let list = column?.querySelector('ul.space-y-5');

        if (!list && column) {
            list = document.createElement('ul');
            list.className = 'space-y-5';
            list.dataset.advisorLiveTranscript = 'true';
            const composer = textarea.closest('section');
            column.insertBefore(list, composer || null);
        }

        return list;
    }

    function scrollToLatest(list, smooth = true) {
        list?.lastElementChild?.scrollIntoView({
            behavior: smooth && !window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto',
            block: 'end',
        });
    }

    async function typeMessage(list, message) {
        const node = makeMessage(message, true);
        list.appendChild(node.item);
        const full = message.content || '';

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || full.length < 12) {
            node.content.textContent = full;
            scrollToLatest(list, false);
            return;
        }

        const chunk = Math.max(1, Math.ceil(full.length / 80));

        for (let position = chunk; position <= full.length + chunk; position += chunk) {
            node.content.textContent = full.slice(0, Math.min(position, full.length));
            if (position % (chunk * 7) === 0) scrollToLatest(list, false);
            await new Promise((resolve) => window.setTimeout(resolve, 15));
        }

        scrollToLatest(list, true);
    }

    function updateProgress(payload) {
        const bar = document.querySelector('[role="progressbar"]');
        if (!bar) return;

        const progress = Number(payload.progress || 0);
        bar.setAttribute('aria-valuenow', String(progress));
        const fill = bar.firstElementChild;
        if (fill instanceof HTMLElement) fill.style.width = `${progress}%`;

        const footer = bar.closest('footer');
        const percent = footer?.querySelector('.numeral.text-xs');
        if (percent) percent.textContent = `${progress}%`;

        const panel = bar.closest('section');
        const rows = panel?.querySelectorAll('ul > li') || [];
        payload.summary?.forEach((row, index) => {
            const item = rows[index];
            if (!item) return;
            const textNodes = item.querySelectorAll('span.text-sm');
            const value = textNodes[textNodes.length - 1];
            if (value) value.textContent = row.answered
                ? String(row.value ?? '')
                : translation('advisor.chat.not_answered', payload.conversation_locale || uiLocale());
        });
    }

    function updateAvailability(payload) {
        if (typeof payload.ai_available !== 'boolean') return;
        const heading = document.querySelector('h1');
        const status = heading?.parentElement?.querySelector('p.mt-1');
        if (!status) return;
        status.classList.toggle('text-positive', payload.ai_available);
        status.classList.toggle('text-caution', !payload.ai_available);
    }

    function recommendationList(items, className) {
        if (!Array.isArray(items) || items.length === 0) return null;

        const list = document.createElement('ul');
        list.className = 'mt-3 space-y-1 text-xs';

        items.forEach((text) => {
            const row = document.createElement('li');
            row.className = className;
            row.textContent = `• ${String(text)}`;
            list.appendChild(row);
        });

        return list;
    }

    function renderRecommendations(transcript, recommendations, locale) {
        if (!transcript || !recommendations || typeof recommendations !== 'object') return;

        transcript.querySelector('[data-advisor-recommendations]')?.remove();

        const item = document.createElement('li');
        item.dataset.advisorRecommendations = 'true';
        item.className = 'w-full';

        const panel = document.createElement('section');
        panel.className = 'mh-lux-panel border border-line bg-surface-raised p-5';
        panel.dir = locale === 'en' ? 'ltr' : 'rtl';

        const heading = document.createElement('h2');
        heading.className = 'font-display text-lg font-bold text-ink';
        heading.textContent = String(recommendations.title || '');
        panel.appendChild(heading);

        if (recommendations.subtitle) {
            const subtitle = document.createElement('p');
            subtitle.className = 'mt-1 text-sm leading-relaxed text-ink-muted';
            subtitle.textContent = String(recommendations.subtitle);
            panel.appendChild(subtitle);
        }

        const projects = Array.isArray(recommendations.items) ? recommendations.items : [];

        if (projects.length > 0) {
            const grid = document.createElement('div');
            grid.className = 'mt-4';
            grid.style.display = 'grid';
            grid.style.gridTemplateColumns = 'repeat(auto-fit, minmax(240px, 1fr))';
            grid.style.gap = '0.75rem';

            projects.forEach((project) => {
                const card = document.createElement('article');
                card.className = 'rounded-card border border-line bg-surface-sunken px-4 py-4';

                const badge = document.createElement('p');
                badge.className = 'mh-lux-eyebrow text-accent';
                badge.textContent = String(project.fit_label || '');
                card.appendChild(badge);

                const name = document.createElement('h3');
                name.className = 'mt-2 font-display text-base font-bold text-ink';
                name.textContent = String(project.name || '');
                card.appendChild(name);

                const metaParts = [project.area, project.type, project.developer].filter(Boolean);
                if (metaParts.length > 0) {
                    const meta = document.createElement('p');
                    meta.className = 'mt-1 text-xs leading-relaxed text-ink-muted';
                    meta.textContent = metaParts.map(String).join(' · ');
                    card.appendChild(meta);
                }

                if (project.price_label) {
                    const price = document.createElement('p');
                    price.className = 'mt-3 text-sm font-bold text-ink';
                    price.textContent = String(project.price_label);
                    card.appendChild(price);
                }

                const reasons = recommendationList(project.reasons, 'text-positive');
                if (reasons) card.appendChild(reasons);

                const differences = recommendationList(project.differences, 'text-caution');
                if (differences) card.appendChild(differences);

                if (project.url) {
                    const link = document.createElement('a');
                    link.href = String(project.url);
                    link.className = 'mh-lux-btn mh-lux-btn-primary mt-4 w-full';
                    link.textContent = String(recommendations.button_label || '');
                    card.appendChild(link);
                }

                grid.appendChild(card);
            });

            panel.appendChild(grid);
        }

        item.appendChild(panel);
        transcript.appendChild(item);
        scrollToLatest(transcript, true);
    }

    function showError(section, message) {
        let error = section.querySelector('[data-advisor-live-error]');
        if (!error) {
            error = document.createElement('p');
            error.dataset.advisorLiveError = 'true';
            error.className = 'mt-1.5 text-xs text-negative';
            error.setAttribute('role', 'alert');
            section.insertBefore(error, section.querySelector('div.mt-3'));
        }
        error.textContent = message;
    }

    function clearError(section) {
        section.querySelector('[data-advisor-live-error]')?.remove();
    }

    function addEnterHint(section, textarea, locale) {
        if (section.querySelector('[data-advisor-enter-hint]')) return;
        const hint = document.createElement('p');
        hint.dataset.advisorEnterHint = 'true';
        hint.className = 'mt-1.5 text-xs text-ink-faint';
        hint.textContent = translation('advisor.chat.enter_hint', locale);
        textarea.insertAdjacentElement('afterend', hint);
    }

    function clearQuickReplies(section) {
        section.querySelector('[data-advisor-quick-replies]')?.remove();
    }

    function setQuickRepliesDisabled(section, disabled) {
        section.querySelectorAll('[data-advisor-quick-reply]').forEach((button) => {
            if (button instanceof HTMLButtonElement) button.disabled = disabled;
        });
    }

    function renderQuickReplies(section, replies, onSelect, locale, slot = null) {
        clearQuickReplies(section);
        if (!Array.isArray(replies) || replies.length === 0) return;

        const wrapper = document.createElement('div');
        wrapper.dataset.advisorQuickReplies = 'true';
        wrapper.className = 'mh-advisor-choice-wrap';
        wrapper.setAttribute('aria-label', translation('advisor.chat.quick_title', locale));

        const title = document.createElement('p');
        title.className = 'mh-advisor-choice-title';
        title.appendChild(createIcon('sparkles', 'h-4 w-4'));
        const titleText = document.createElement('span');
        titleText.textContent = translation('advisor.chat.quick_title', locale);
        title.appendChild(titleText);
        wrapper.appendChild(title);

        const grid = document.createElement('div');
        grid.className = 'mh-advisor-choice-grid';
        wrapper.appendChild(grid);

        replies.forEach((reply, index) => {
            const label = String(reply?.label || reply?.value || '').trim();
            const value = String(reply?.value || label).trim();
            if (!label || !value) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.advisorQuickReply = 'true';
            button.dataset.advisorSlot = String(slot || '');
            button.className = 'mh-advisor-choice';

            const iconBox = document.createElement('span');
            iconBox.className = 'mh-advisor-choice-icon';
            iconBox.appendChild(createIcon(String(reply?.icon || iconForSlot(slot, index))));

            const text = document.createElement('span');
            text.className = 'mh-advisor-choice-label';
            text.textContent = label;

            button.append(iconBox, text);
            button.addEventListener('click', (event) => onSelect(event, value), true);
            grid.appendChild(button);
        });

        const actionRow = section.querySelector('div.mt-3');
        if (actionRow) section.insertBefore(wrapper, actionRow);
        else section.appendChild(wrapper);
    }

    function iconForSlot(slot, index) {
        const defaults = {
            purpose: ['home', 'growth'],
            budget: ['wallet'],
            property_type: ['apartment', 'villa', 'house', 'land'],
            area: ['location'],
            workplace: ['briefcase'],
            schools: ['check', 'close'],
            family: ['family', 'close'],
            timeframe: ['clock', 'clock', 'calendar'],
            payment: ['cash', 'card', 'split'],
            risk: ['shield', 'balance', 'bolt'],
            return_goal: ['key', 'growth'],
        };
        const items = defaults[String(slot || '')] || ['sparkles'];
        return items[index % items.length] || 'sparkles';
    }

    /*
     * The advisory panel replaces v7's showComplete(). The old function HID
     * the textarea and send button the moment `complete` was true — the
     * frontend half of the "9 questions then silence" defect. Intake
     * completeness now opens the follow-up stage instead: the composer stays
     * fully interactive, and this panel adds the send-to-team block and the
     * start-over control alongside it, once.
     */
    function showAdvisoryPanel(section, locale) {
        section.querySelector('[data-advisor-live-error]')?.remove();

        if (section.querySelector('[data-advisor-complete]')) return;

        const wrapper = document.createElement('div');
        wrapper.dataset.advisorComplete = 'true';
        wrapper.className = 'mt-4 border-t border-line pt-4';

        const hint = document.createElement('p');
        hint.className = 'text-sm text-ink-muted';
        hint.textContent = translation('advisor.chat.followup_hint', locale);
        wrapper.appendChild(hint);

        const form = document.createElement('form');
        form.method = 'post';
        form.action = endpoint('reset');
        form.className = 'mt-3';

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrfToken();
        form.appendChild(token);

        const reset = document.createElement('button');
        reset.type = 'submit';
        reset.className = 'mh-lux-btn mh-lux-btn-secondary';
        reset.textContent = translation('advisor.chat.start_over', locale);
        form.appendChild(reset);
        wrapper.appendChild(form);

        /*
         * v7: send-to-team (spec §7.3). Consent text, one action, and the
         * confirmation in place — the person never leaves the chat. The
         * strings resolve from the page's shared translations, so all three
         * locales come for free.
         */
        wrapper.appendChild(makeSubmitBlock(locale));
        section.appendChild(wrapper);
    }

    function makeSubmitBlock(locale) {
        const block = document.createElement('div');
        block.dataset.advisorSubmitLive = 'true';
        block.className = 'mt-5 border-t border-line pt-4';

        const alreadySubmitted = latestProps?.request_status != null;

        if (alreadySubmitted) {
            block.appendChild(makeSubmittedLine(locale));
            return block;
        }

        const title = document.createElement('p');
        title.className = 'text-sm font-medium text-ink';
        title.textContent = translation('advisor.submit.title', locale);
        block.appendChild(title);

        const label = document.createElement('label');
        label.className = 'mt-2 flex cursor-pointer items-start gap-3 text-sm text-ink-muted';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'mt-1 h-4 w-4 rounded border-line text-brand focus:ring-accent';
        const consentText = document.createElement('span');
        consentText.textContent = translation('advisor.submit.consent', locale);
        label.appendChild(checkbox);
        label.appendChild(consentText);
        block.appendChild(label);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mh-lux-btn mh-lux-btn-primary mt-3';
        button.disabled = true;
        button.textContent = translation('advisor.submit.button', locale);
        block.appendChild(button);

        const error = document.createElement('p');
        error.className = 'mt-2 text-sm text-negative';
        error.hidden = true;
        error.setAttribute('role', 'alert');
        block.appendChild(error);

        checkbox.addEventListener('change', () => { button.disabled = !checkbox.checked; });

        button.addEventListener('click', async () => {
            button.disabled = true;
            error.hidden = true;

            try {
                const response = await fetch(endpoint('request'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ consent: true }),
                });
                const payload = await response.json();

                if (response.ok && payload.ok === true) {
                    block.replaceChildren(makeSubmittedLine(locale));
                } else {
                    error.textContent = payload.reason === 'incomplete'
                        ? translation('advisor.submit.incomplete', locale)
                        : translation('advisor.chat.send_failed', locale);
                    error.hidden = false;
                    button.disabled = !checkbox.checked;
                }
            } catch (_) {
                error.textContent = translation('advisor.chat.send_failed', locale);
                error.hidden = false;
                button.disabled = !checkbox.checked;
            }
        });

        return block;
    }

    function makeSubmittedLine(locale) {
        const sent = document.createElement('p');
        sent.className = 'text-sm text-positive';
        sent.setAttribute('role', 'status');
        sent.textContent = translation('advisor.submit.sent', locale) + ' ';

        const link = document.createElement('a');
        // §6.6: derive the locale prefix from the page's own advisor path,
        // exactly as endpoint() does — /ar/advisor links to /ar/account/….
        link.href = window.location.pathname.replace(/\/advisor(?:\/.*)?$/, '/account/requests');
        link.className = 'font-medium underline underline-offset-2';
        link.textContent = translation('advisor.submit.view_requests', locale);
        sent.appendChild(link);

        return sent;
    }

    function sameAsLastMessage(list, content) {
        const last = list.lastElementChild?.querySelector('p.whitespace-pre-line');
        return last?.textContent?.trim() === String(content || '').trim();
    }

    function appendInitialPrompt(list, section) {
        const message = latestProps?.initial_message;

        if (message && !sameAsLastMessage(list, message.content)) {
            list.appendChild(makeMessage(message).item);
            return;
        }

        if (list.children.length === 0) {
            const question = section.querySelector('label')?.textContent?.trim();
            if (question) {
                list.appendChild(makeMessage({
                    id: 'initial-dom-question',
                    role: 'assistant',
                    content: question,
                    locale: uiLocale(),
                }).item);
            }
        }
    }

    function resetWithNavigation(event) {
        event?.preventDefault();
        event?.stopPropagation();
        event?.stopImmediatePropagation?.();

        const form = document.createElement('form');
        form.method = 'post';
        form.action = endpoint('reset');

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrfToken();
        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    }

    function enhanceCompleteOnly() {
        injectStyles();
        enhanceLiveAvatar();
        sanitizeTranscript();
        const locale = uiLocale();
        const transcript = document.querySelector('ul[data-testid="advisor-transcript"], ul.space-y-5');
        renderRecommendations(transcript, latestProps?.recommendations, locale);

        const legacyForm = Array.from(document.querySelectorAll('form')).find((candidate) =>
            /\/advisor\/recommend(?:$|\?)/.test(candidate.getAttribute('action') || '')
        );

        if (legacyForm instanceof HTMLFormElement && legacyForm.dataset.advisorCompleteVersion !== '6') {
            legacyForm.dataset.advisorCompleteVersion = '6';
            legacyForm.action = endpoint('reset');
            const button = legacyForm.querySelector('button');
            if (button) button.textContent = translation('advisor.chat.start_over', locale);
            const hint = legacyForm.parentElement?.querySelector('p');
            if (hint) hint.textContent = translation('advisor.chat.complete_hint', locale);
        }

        if (!latestProps?.complete || !transcript) return;

        const column = transcript.closest('.min-w-0.space-y-5') || transcript.parentElement?.parentElement;
        const actionSection = Array.from(column?.querySelectorAll('section') || []).find((section) =>
            !section.querySelector('textarea') && section.querySelector('button')
        );
        const button = actionSection?.querySelector('button');

        if (!(button instanceof HTMLButtonElement) || button.dataset.advisorResetVersion === '6') return;

        button.dataset.advisorResetVersion = '6';
        button.textContent = translation('advisor.chat.start_over', locale);
        button.addEventListener('click', resetWithNavigation, true);
        const hint = actionSection.querySelector('p');
        if (hint) hint.textContent = translation('advisor.chat.complete_hint', locale);
    }

    function enhance() {
        injectStyles();
        enhanceLiveAvatar();
        sanitizeTranscript();
        enhanceCompleteOnly();
        const textarea = document.querySelector('textarea[id^="advisor-answer-"]');
        if (!(textarea instanceof HTMLTextAreaElement) || textarea.dataset.advisorLiveVersion === '6') return;

        const section = textarea.closest('section');
        const button = section?.querySelector('button');
        const list = ensureTranscript(textarea);
        if (!section || !(button instanceof HTMLButtonElement) || !list) return;

        textarea.dataset.advisorLiveVersion = '6';
        textarea.enterKeyHint = 'send';
        const state = {
            busy: false,
            complete: Boolean(latestProps?.complete),
            locale: uiLocale(),
            slot: latestProps?.next_slot || null,
        };
        window[STATE_KEY] = state;
        appendInitialPrompt(list, section);
        updateProgress({
            progress: latestProps?.progress,
            summary: latestProps?.summary,
            conversation_locale: state.locale,
        });
        renderRecommendations(list, latestProps?.recommendations, state.locale);
        addEnterHint(section, textarea, state.locale);
        sanitizeTranscript();

        const oldLabel = section.querySelector('label');
        if (oldLabel) oldLabel.classList.add('sr-only');
        scrollToLatest(list, false);

        async function send(event, selectedText = null) {
            event?.preventDefault();
            event?.stopPropagation();
            event?.stopImmediatePropagation?.();

            // `state.complete` deliberately does NOT block a send: a complete
            // intake is the beginning of the advisory conversation.
            const text = String(selectedText ?? textarea.value).trim();
            if (!text || state.busy) return;

            state.busy = true;
            button.disabled = true;
            textarea.disabled = true;
            setQuickRepliesDisabled(section, true);
            clearError(section);

            const localId = `local-${Date.now()}`;
            const userLocale = state.locale;
            const user = makeMessage({ id: localId, role: 'user', content: text, locale: userLocale });
            list.appendChild(user.item);
            textarea.value = '';
            textarea.dispatchEvent(new Event('input', { bubbles: true }));

            const typing = makeTyping(userLocale);
            list.appendChild(typing.item);
            scrollToLatest(list, true);

            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

            try {
                // Submit like a normal Laravel form. This is more reliable on
                // shared hosting/WAF setups than a raw JSON body, and includes
                // the CSRF token in both the header and body.
                const body = new URLSearchParams();
                body.set('_token', csrfToken());
                body.set('message', text);
                body.set('locale', state.locale);

                const response = await fetch(endpoint('reply'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'follow',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.assistant_message) {
                    const serverMessage = payload.errors?.message?.[0]
                        || payload.message
                        || translation('advisor.chat.send_failed', userLocale);
                    const failure = new Error(serverMessage);
                    failure.httpStatus = response.status;
                    throw failure;
                }

                typing.stop();
                typing.item.remove();
                updateProgress(payload);
                updateAvailability(payload);
                state.complete = Boolean(payload.complete);
                state.locale = uiLocale();
                state.slot = payload.next_slot || null;
                await typeMessage(list, payload.assistant_message);
                sanitizeTranscript();
                renderRecommendations(list, payload.recommendations, state.locale);

                const hint = section.querySelector('[data-advisor-enter-hint]');
                if (hint) hint.textContent = translation('advisor.chat.enter_hint', state.locale);
                renderQuickReplies(section, payload.quick_replies, send, state.locale, state.slot);
                if (state.complete) {
                    showAdvisoryPanel(section, state.locale);
                }
            } catch (error) {
                typing.stop();
                typing.item.remove();
                user.body.classList.add('opacity-70');
                textarea.value = text;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                const status = Number(error?.httpStatus || 0);
                const suffix = status > 0 ? ` (HTTP ${status})` : '';
                showError(section, `${error?.message || translation('advisor.chat.send_failed', userLocale)}${suffix}`);
            } finally {
                window.clearTimeout(timeout);
                state.busy = false;
                button.disabled = false;
                textarea.disabled = false;
                setQuickRepliesDisabled(section, state.busy);
                textarea.focus();
            }
        }

        button.addEventListener('click', (event) => { void send(event); }, true);
        textarea.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                void send(event);
            }
        }, true);

        // Samsung Internet and some Android keyboards emit beforeinput instead
        // of a dependable keydown for the soft-keyboard Enter action.
        textarea.addEventListener('beforeinput', (event) => {
            if ((event.inputType === 'insertLineBreak' || event.inputType === 'insertParagraph')
                && !event.isComposing) {
                void send(event);
            }
        }, true);

        const form = textarea.closest('form');
        form?.addEventListener('submit', (event) => { void send(event); }, true);


        renderQuickReplies(section, latestProps?.quick_replies, send, state.locale, state.slot);
        if (state.complete) {
            showAdvisoryPanel(section, state.locale);
        }
    }

    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('inertia:success', rememberPage);
    document.addEventListener('inertia:navigate', rememberPage);
    document.addEventListener('inertia:finish', () => window.setTimeout(enhance, 0));
    window.setTimeout(enhance, 0);
})();
