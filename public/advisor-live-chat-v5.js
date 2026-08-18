(() => {
    'use strict';

    const STATE_KEY = '__myHawlerAdvisorLiveChatV5';
    const REQUEST_TIMEOUT_MS = 25000;
    const COPY = {
        ckb: {
            'advisor.chat.you': 'تۆ',
            'advisor.chat.assistant': 'ڕاوێژکار',
            'advisor.chat.typing': 'ڕاوێژکارەکە وەڵام دەنووسێت…',
            'advisor.chat.send_failed': 'کێشەیەکی کاتی ڕوویدا و وەڵامەکە نەگەیشت. دووبارە هەوڵ بدە.',
            'advisor.chat.not_answered': 'وەڵام نەدراوەتەوە',
            'advisor.chat.complete_hint': 'ئەنجامەکان لە ناو گفتوگۆکەدا پیشان دراون.',
            'advisor.chat.start_over': 'گفتوگۆی نوێ',
            'advisor.chat.enter_hint': 'بۆ ناردن Enter دابگرە، بۆ هێڵێکی نوێ Shift + Enter.',
        },
        ar: {
            'advisor.chat.you': 'أنت',
            'advisor.chat.assistant': 'المستشار',
            'advisor.chat.typing': 'المستشار يكتب…',
            'advisor.chat.send_failed': 'صار خلل مؤقت وما وصل الرد. جرّب مرة ثانية.',
            'advisor.chat.not_answered': 'لم تتم الإجابة',
            'advisor.chat.complete_hint': 'النتائج موجودة داخل المحادثة.',
            'advisor.chat.start_over': 'محادثة جديدة',
            'advisor.chat.enter_hint': 'اضغط Enter للإرسال، وShift + Enter لسطر جديد.',
        },
        en: {
            'advisor.chat.you': 'You',
            'advisor.chat.assistant': 'Advisor',
            'advisor.chat.typing': 'The advisor is typing…',
            'advisor.chat.send_failed': 'Something went wrong and the reply did not arrive. Please try again.',
            'advisor.chat.not_answered': 'Not answered',
            'advisor.chat.complete_hint': 'Your results are shown in the conversation.',
            'advisor.chat.start_over': 'New conversation',
            'advisor.chat.enter_hint': 'Press Enter to send, or Shift + Enter for a new line.',
        },
    };

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
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = user ? '●' : '✦';
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
        const timer = window.setInterval(() => {
            message.content.textContent = frames[frame % frames.length];
            frame += 1;
        }, 340);

        return { item: message.item, stop: () => window.clearInterval(timer) };
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

    function renderQuickReplies(section, replies, onSelect, locale) {
        clearQuickReplies(section);
        if (!Array.isArray(replies) || replies.length === 0) return;

        const wrapper = document.createElement('div');
        wrapper.dataset.advisorQuickReplies = 'true';
        wrapper.setAttribute('aria-label', locale === 'en' ? 'Quick replies' : 'وەڵامە خێراکان');
        wrapper.style.display = 'flex';
        wrapper.style.flexWrap = 'wrap';
        wrapper.style.gap = '0.5rem';
        wrapper.style.marginTop = '0.75rem';

        replies.forEach((reply) => {
            const label = String(reply?.label || reply?.value || '').trim();
            const value = String(reply?.value || label).trim();
            if (!label || !value) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.advisorQuickReply = 'true';
            button.className = 'mh-lux-btn mh-lux-btn-secondary';
            button.style.minHeight = '2.5rem';
            button.style.padding = '0.5rem 0.85rem';
            button.style.borderRadius = '999px';
            button.style.fontSize = '0.875rem';
            button.textContent = label;
            button.addEventListener('click', (event) => onSelect(event, value), true);
            wrapper.appendChild(button);
        });

        const actionRow = section.querySelector('div.mt-3');
        if (actionRow) section.insertBefore(wrapper, actionRow);
        else section.appendChild(wrapper);
    }

    function showComplete(section, locale) {
        const textarea = section.querySelector('textarea');
        const sendButton = section.querySelector('button:not([data-advisor-quick-reply])');
        if (textarea) textarea.hidden = true;
        if (sendButton) sendButton.hidden = true;
        section.querySelector('label')?.remove();
        section.querySelector('[data-advisor-live-error]')?.remove();
        section.querySelector('[data-advisor-enter-hint]')?.remove();
        clearQuickReplies(section);

        if (section.querySelector('[data-advisor-complete]')) return;

        const wrapper = document.createElement('div');
        wrapper.dataset.advisorComplete = 'true';

        const hint = document.createElement('p');
        hint.className = 'text-sm text-ink-muted';
        hint.textContent = translation('advisor.chat.complete_hint', locale);
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
        section.appendChild(wrapper);
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
        const locale = String(latestProps?.conversation_locale || uiLocale());
        const transcript = document.querySelector('ul[data-testid="advisor-transcript"], ul.space-y-5');
        renderRecommendations(transcript, latestProps?.recommendations, locale);

        const legacyForm = Array.from(document.querySelectorAll('form')).find((candidate) =>
            /\/advisor\/recommend(?:$|\?)/.test(candidate.getAttribute('action') || '')
        );

        if (legacyForm instanceof HTMLFormElement && legacyForm.dataset.advisorCompleteVersion !== '5') {
            legacyForm.dataset.advisorCompleteVersion = '5';
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

        if (!(button instanceof HTMLButtonElement) || button.dataset.advisorResetVersion === '5') return;

        button.dataset.advisorResetVersion = '5';
        button.textContent = translation('advisor.chat.start_over', locale);
        button.addEventListener('click', resetWithNavigation, true);
        const hint = actionSection.querySelector('p');
        if (hint) hint.textContent = translation('advisor.chat.complete_hint', locale);
    }

    function enhance() {
        enhanceCompleteOnly();
        const textarea = document.querySelector('textarea[id^="advisor-answer-"]');
        if (!(textarea instanceof HTMLTextAreaElement) || textarea.dataset.advisorLiveVersion === '5') return;

        const section = textarea.closest('section');
        const button = section?.querySelector('button');
        const list = ensureTranscript(textarea);
        if (!section || !(button instanceof HTMLButtonElement) || !list) return;

        textarea.dataset.advisorLiveVersion = '5';
        textarea.enterKeyHint = 'send';
        const state = {
            busy: false,
            complete: Boolean(latestProps?.complete),
            locale: String(latestProps?.conversation_locale || uiLocale()),
        };
        window[STATE_KEY] = state;
        appendInitialPrompt(list, section);
        renderRecommendations(list, latestProps?.recommendations, state.locale);
        addEnterHint(section, textarea, state.locale);

        const oldLabel = section.querySelector('label');
        if (oldLabel) oldLabel.classList.add('sr-only');
        scrollToLatest(list, false);

        async function send(event, selectedText = null) {
            event?.preventDefault();
            event?.stopPropagation();
            event?.stopImmediatePropagation?.();

            const text = String(selectedText ?? textarea.value).trim();
            if (!text || state.busy || state.complete) return;

            state.busy = true;
            button.disabled = true;
            textarea.disabled = true;
            setQuickRepliesDisabled(section, true);
            clearError(section);

            const localId = `local-${Date.now()}`;
            const userLocale = inputLocale(text, state.locale);
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
                state.locale = String(payload.conversation_locale || state.locale);
                await typeMessage(list, payload.assistant_message);
                renderRecommendations(list, payload.recommendations, state.locale);

                const hint = section.querySelector('[data-advisor-enter-hint]');
                if (hint) hint.textContent = translation('advisor.chat.enter_hint', state.locale);
                if (state.complete) {
                    showComplete(section, state.locale);
                } else {
                    renderQuickReplies(section, payload.quick_replies, send, state.locale);
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
                button.disabled = state.complete;
                textarea.disabled = state.complete;
                setQuickRepliesDisabled(section, state.busy || state.complete);
                if (!state.complete) textarea.focus();
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


        if (state.complete) {
            showComplete(section, state.locale);
        } else {
            renderQuickReplies(section, latestProps?.quick_replies, send, state.locale);
        }
    }

    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('inertia:success', rememberPage);
    document.addEventListener('inertia:navigate', rememberPage);
    document.addEventListener('inertia:finish', () => window.setTimeout(enhance, 0));
    window.setTimeout(enhance, 0);
})();
