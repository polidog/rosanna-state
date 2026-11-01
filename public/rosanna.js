/**
 * Rosanna client. 依存なし、ビルドなし。
 *
 * JavaScript なしでも動く HTML の上に、部分更新、defer、Snapshot の永続化と
 * バージョン照合を載せる (docs/spec/02-state-and-actions.md の 9 節)。
 *
 * innerHTML に入れるのは、ページ自身の URL か、同一オリジンの defer の prefix 配下から
 * 返ったサーバー描画の HTML だけである。template の中で解析するので <script> は動かない。
 */
(function () {
    'use strict';

    // =========================================================================
    // DOM に触らない部分。tests/js から確かめる
    // =========================================================================

    const DEFAULT_DEFER_PREFIX = '/_defer';
    const SNAPSHOT_PREFIX = 'rosanna:snapshot:';
    const DEFER_LS_PREFIX = 'rosanna:defer:';
    const DEFER_LS_VERSION_KEY = DEFER_LS_PREFIX + '__version__';
    const DEFER_CACHE_VERSION = '1';
    const DEFER_CACHE_MAX = 64;
    const INPUT_DEBOUNCE_MS = 300;

    // JS-17 共有端末で次の利用者に渡してはいけないものを含む断片の目印
    const SESSION_BOUND_SELECTOR = [
        '[data-rosanna-form]',
        'input[name="_rosanna_csrf"]',
        'input[name="_rosanna_snapshot"]',
        '[data-rosanna-snapshot]',
    ].join(',');

    function parseJsonObject(text) {
        if (typeof text !== 'string') return null;
        let value;
        try {
            value = JSON.parse(text);
        } catch {
            return null;
        }
        return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
    }

    /**
     * AC-20 `{"action":"<json文字列>","sig":"..."}` を読む。
     * Relayer のトークンのように JSON にならない値は null (JS-8)。
     */
    function parseEnvelope(value) {
        const envelope = parseJsonObject(value);
        if (envelope === null || typeof envelope.action !== 'string') return null;
        const action = parseJsonObject(envelope.action);
        if (action === null) return null;
        return { actionJson: envelope.action, action, sig: envelope.sig ?? null };
    }

    function envelopeVersion(value) {
        const envelope = parseEnvelope(value);
        return envelope !== null && Number.isInteger(envelope.action.version)
            ? envelope.action.version
            : null;
    }

    function snapshotVersion(snapshotJson) {
        const snapshot = parseJsonObject(snapshotJson);
        const memo = snapshot === null ? null : snapshot.memo;
        return memo !== null && typeof memo === 'object' && Number.isInteger(memo.version)
            ? memo.version
            : null;
    }

    /** JS-8 比べられないものは止めない */
    function isEnvelopeStale(envelopeValue, mirroredSnapshot) {
        const formVersion = envelopeVersion(envelopeValue);
        const mirrorVersion = snapshotVersion(mirroredSnapshot);
        return formVersion !== null && mirrorVersion !== null && formVersion < mirrorVersion;
    }

    /**
     * JS-25 Memory と Snapshot はセッションを始めない。クラスのコンポーネントの action は storageType を
     * 持たず (RT-5.3) サーバーがレジストリで決める (AC-11) ので、Snapshot のラッパーでなければ Session と見る。
     */
    function isSessionEnvelope(value, isSnapshotInstance = false) {
        const envelope = parseEnvelope(value);
        if (envelope === null) return false;
        const { storageType } = envelope.action;
        return storageType === 'session' || (storageType == null && !isSnapshotInstance);
    }

    /**
     * JS-22 ページの更新の後に restore で戻すか。バージョン 0 は操作されていないので、描画の値を正とする。
     * after の方が新しければ、サーバーが状態を持っている (SnapshotBehavior::Session など)。
     */
    function needsRestoreAfterRefresh(before, after) {
        if (typeof before !== 'string' || typeof after !== 'string' || before === after) return false;
        const beforeVersion = snapshotVersion(before);
        const afterVersion = snapshotVersion(after);
        return beforeVersion !== null && afterVersion !== null && beforeVersion > 0 && afterVersion <= beforeVersion;
    }

    /** AC-21 restore のエンベロープ。AC-9 により action の JSON 自体は署名の対象でない */
    function buildRestoreEnvelope(instanceId, restoreSig) {
        return JSON.stringify({
            action: JSON.stringify({
                type: 'restore',
                payload: {},
                componentId: instanceId,
                storageType: 'snapshot',
            }),
            sig: restoreSig ?? null,
        });
    }

    function snapshotKey(pathname, instanceId) {
        return SNAPSHOT_PREFIX + pathname + '#' + instanceId;
    }

    /** VR-6 種類が不明なら navigate と同じにする。「リロードで初期値に戻る」を崩さないため */
    function mirrorPolicy(navigationType, persisted) {
        return persisted || navigationType === 'back_forward' ? 'keep' : 'clear';
    }

    /** JS-4 同一オリジンの行き先だけ返す */
    function sameOriginRedirect(url, pageHref) {
        try {
            const target = new URL(url, pageHref);
            return target.origin === new URL(pageHref).origin ? target.href : null;
        } catch {
            return null;
        }
    }

    function normalizeDeferPrefix(configured) {
        const prefix = typeof configured === 'string' && configured !== '' ? configured : DEFAULT_DEFER_PREFIX;
        return prefix.replace(/\/+$/, '');
    }

    /**
     * JS-16 同一オリジンで prefix 配下なら解決済みの URL を返す。
     * prefix が `/` だけのときはオリジン全体を許すことになるので拒否する。
     */
    function resolveDeferUrl(url, configuredPrefix, pageHref) {
        const prefix = normalizeDeferPrefix(configuredPrefix);
        if (prefix === '' || prefix[0] !== '/') return null;
        let parsed;
        try {
            parsed = new URL(url, pageHref);
        } catch {
            return null;
        }
        if (parsed.origin !== new URL(pageHref).origin) return null;
        return parsed.pathname.startsWith(prefix + '/') ? parsed.href : null;
    }

    function isHtmlContentType(contentType) {
        return /^text\/html\s*(;|$)/i.test(contentType || '');
    }

    /** defer の名前は URL の最後のセグメント。prefix を知らなくても引けるようにする */
    function deferUrlMatchesName(url, name) {
        const path = String(url).split(/[?#]/, 1)[0];
        const last = path.split('/').filter(Boolean).pop() ?? '';
        try {
            return decodeURIComponent(last) === name;
        } catch {
            return last === name;
        }
    }

    function parseReloadTargets(value) {
        return (value || '').split(/[\s,]+/).filter(Boolean);
    }

    function parseCacheTtlMs(value) {
        const seconds = Number(value);
        return Number.isInteger(seconds) && seconds > 0 ? seconds * 1000 : 0;
    }

    /**
     * JS-7 (README 438-445 行) と JS-8a。部分更新を越えて残る欄の値を決める。
     *
     * - current: 応答を当てる直前の値
     * - renderedBefore / renderedAfter: マークアップが示す値の前後
     * - sentValue: 送信時の値。送信時に控えていなければ undefined
     * - inSentForm: 送信したフォームの欄か
     * - isSource: 送信の引き金になった欄か
     * - keepSent: 古い action の描き直しなので、送信した欄を保つか
     */
    function chooseFieldValue({ current, renderedBefore, renderedAfter, sentValue, inSentForm, isSource, keepSent }) {
        if (sentValue !== undefined && sentValue !== current) return current;
        if (keepSent && (inSentForm || isSource)) return current;
        if (renderedAfter !== renderedBefore) return renderedAfter;
        return inSentForm ? renderedAfter : current;
    }

    /**
     * JS-9、JS-10 照合に使う写し。sessionStorage が使えなければページ内のメモリで同じ確認をする。
     * 書けなかったときは古い値が読まれないように消す。
     */
    function createSnapshotMirror(getStorage) {
        const memory = new Map();
        const storage = () => {
            try {
                return getStorage() || null;
            } catch {
                return null;
            }
        };
        return {
            read(key) {
                const store = storage();
                if (store) {
                    try {
                        const value = store.getItem(key);
                        if (value !== null) return value;
                    } catch {
                        // メモリへ
                    }
                }
                return memory.has(key) ? memory.get(key) : null;
            },
            write(key, value) {
                memory.set(key, value);
                const store = storage();
                if (!store) return;
                try {
                    store.setItem(key, value);
                } catch {
                    try {
                        store.removeItem(key);
                    } catch {
                        // メモリで続ける
                    }
                }
            },
            remove(key) {
                memory.delete(key);
                const store = storage();
                if (!store) return;
                try {
                    store.removeItem(key);
                } catch {
                    // 消せなくてもページは止めない
                }
            },
            removeAll(prefix) {
                for (const key of memory.keys()) {
                    if (key.startsWith(prefix)) memory.delete(key);
                }
                const store = storage();
                if (!store) return;
                try {
                    storageKeys(store, prefix).forEach((key) => store.removeItem(key));
                } catch {
                    // 消せなくてもページは止めない
                }
            },
        };
    }

    function storageKeys(store, prefix) {
        const keys = [];
        for (let i = 0; i < store.length; i++) {
            const key = store.key(i);
            if (key !== null && key.startsWith(prefix)) keys.push(key);
        }
        return keys;
    }

    /** JS-18 ページ内のキャッシュ。古い順に捨てる */
    function createLruCache(max) {
        const entries = new Map();
        return {
            get(key) {
                if (!entries.has(key)) return undefined;
                const value = entries.get(key);
                entries.delete(key);
                entries.set(key, value);
                return value;
            },
            set(key, value) {
                entries.delete(key);
                if (entries.size >= max) entries.delete(entries.keys().next().value);
                entries.set(key, value);
            },
            delete(key) {
                entries.delete(key);
            },
            clear() {
                entries.clear();
            },
            keys() {
                return [...entries.keys()];
            },
        };
    }

    /**
     * JS-18 localStorage の段。上限を超えたら保存の古い順に捨て、バージョンが変われば全部消す。
     * 古い版のタブが後から書くことがあるので、バージョンは項目ごとにも持つ。
     */
    function createDeferStore(getStorage, now = Date.now, max = DEFER_CACHE_MAX, version = DEFER_CACHE_VERSION) {
        const storage = () => {
            try {
                const store = getStorage();
                if (!store) return null;
                const probe = DEFER_LS_PREFIX + '__probe__';
                store.setItem(probe, '1');
                store.removeItem(probe);
                return store;
            } catch {
                return null;
            }
        };
        const entryKeys = (store) => storageKeys(store, DEFER_LS_PREFIX).filter((key) => key !== DEFER_LS_VERSION_KEY);
        const readRecord = (store, key) => {
            const record = parseJsonObject(store.getItem(key));
            return record !== null && record.v === version && typeof record.html === 'string' ? record : null;
        };
        const makeRoom = (store) => {
            const records = [];
            for (const key of entryKeys(store)) {
                const record = readRecord(store, key);
                if (record === null) {
                    store.removeItem(key);
                } else {
                    records.push({ key, storedAt: typeof record.storedAt === 'number' ? record.storedAt : 0 });
                }
            }
            records.sort((a, b) => a.storedAt - b.storedAt);
            for (let i = 0; i < records.length - max + 1; i++) {
                store.removeItem(records[i].key);
            }
            return records;
        };

        const api = {
            reconcile() {
                const store = storage();
                if (!store) return;
                try {
                    if (store.getItem(DEFER_LS_VERSION_KEY) !== version) api.clear();
                } catch {
                    // localStorage なしで動く
                }
            },
            read(url, maxAgeMs = 0) {
                const store = storage();
                if (!store) return null;
                const key = DEFER_LS_PREFIX + url;
                try {
                    if (store.getItem(key) === null) return null;
                    const record = readRecord(store, key);
                    const expired = record !== null && maxAgeMs > 0
                        && now() - (typeof record.storedAt === 'number' ? record.storedAt : 0) > maxAgeMs;
                    if (record === null || expired) {
                        store.removeItem(key);
                        return null;
                    }
                    return record.html;
                } catch {
                    return null;
                }
            },
            write(url, html) {
                const store = storage();
                if (!store) return;
                const key = DEFER_LS_PREFIX + url;
                const record = JSON.stringify({ v: version, html, storedAt: now() });
                try {
                    makeRoom(store);
                    store.setItem(key, record);
                } catch {
                    // 容量が足りないときは最も古いものを捨てて 1 回だけやり直す
                    try {
                        const oldest = makeRoom(store)[0];
                        if (oldest) store.removeItem(oldest.key);
                        store.setItem(key, record);
                    } catch {
                        // メモリの段だけで続ける
                    }
                }
            },
            remove(url) {
                const store = storage();
                if (!store) return;
                try {
                    store.removeItem(DEFER_LS_PREFIX + url);
                } catch {
                    // 消せなくてもページは止めない
                }
            },
            clear() {
                const store = storage();
                if (!store) return;
                try {
                    storageKeys(store, DEFER_LS_PREFIX).forEach((key) => store.removeItem(key));
                    store.setItem(DEFER_LS_VERSION_KEY, version);
                } catch {
                    // 消せなくてもページは止めない
                }
            },
            urls() {
                const store = storage();
                if (!store) return [];
                try {
                    return entryKeys(store).map((key) => key.slice(DEFER_LS_PREFIX.length));
                } catch {
                    return [];
                }
            },
        };
        return api;
    }

    // ブラウザでは `module` を見ない。id="module" の要素が window に見えるため
    if (typeof document === 'undefined') {
        if (typeof module === 'object' && module !== null) {
            module.exports = {
                DEFER_CACHE_VERSION,
                SESSION_BOUND_SELECTOR,
                buildRestoreEnvelope,
                chooseFieldValue,
                createDeferStore,
                createLruCache,
                createSnapshotMirror,
                deferUrlMatchesName,
                envelopeVersion,
                isEnvelopeStale,
                isHtmlContentType,
                isSessionEnvelope,
                mirrorPolicy,
                needsRestoreAfterRefresh,
                parseCacheTtlMs,
                parseEnvelope,
                parseReloadTargets,
                resolveDeferUrl,
                sameOriginRedirect,
                snapshotKey,
                snapshotVersion,
            };
        }
        return;
    }

    // =========================================================================
    // 公開 API の入れ物
    // =========================================================================

    // <form id="Rosanna"> のように要素が window に見えていたら使わない
    const existing = window.Rosanna;
    const Rosanna = Object.prototype.toString.call(existing) === '[object Object]' ? existing : {};
    window.Rosanna = Rosanna;

    function startElement(event) {
        if (event.target instanceof Element) return event.target;
        return event.target && event.target.parentElement ? event.target.parentElement : null;
    }

    function parseHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content;
    }

    // =========================================================================
    // コンポーネントとロック (JS-2、JS-6)
    // =========================================================================

    function findInstance(id) {
        return document.querySelector('[data-rosanna="' + CSS.escape(id) + '"]');
    }

    // フォームはアプリの <form> の中だとラッパーの外に置かれるので、持っている ID で探す
    function componentOf(form) {
        const field = form.querySelector('input[name="_rosanna_component"]');
        const closest = form.closest('[data-rosanna]');
        if (!field || field.value === '') return closest;
        if (closest && closest.getAttribute('data-rosanna') === field.value) return closest;
        return findInstance(field.value);
    }

    // disabled はフォーカスを奪い、aria-busy は親の差分適用で消えるので、ロックに使わない
    const locked = new WeakSet();
    const inFlight = new WeakMap();
    let activeLocks = 0;
    const idleWaiters = [];

    function whenIdle() {
        return activeLocks === 0 ? Promise.resolve() : new Promise((resolve) => idleWaiters.push(resolve));
    }

    function enclosingInstance(el) {
        return el.parentElement ? el.parentElement.closest('[data-rosanna]') : null;
    }

    function busyRelative(component) {
        for (let el = component; el; el = enclosingInstance(el)) {
            if (locked.has(el)) return el;
        }
        for (const descendant of component.querySelectorAll('[data-rosanna]')) {
            if (locked.has(descendant)) return descendant;
        }
        return null;
    }

    function lock(component, submission) {
        if (!locked.has(component)) activeLocks++;
        locked.add(component);
        if (submission) inFlight.set(component, submission);
        component.setAttribute('aria-busy', 'true');
    }

    function unlock(component) {
        if (locked.delete(component)) activeLocks--;
        inFlight.delete(component);
        component.removeAttribute('aria-busy');
        flushResends(component);
        if (activeLocks === 0) idleWaiters.splice(0).forEach((resolve) => resolve());
    }

    // =========================================================================
    // 欄の値
    // =========================================================================

    const NON_USER_INPUT_TYPES = new Set(['hidden', 'submit', 'button', 'reset', 'image']);

    function isUserField(el) {
        if (el.nodeName === 'INPUT') return !NON_USER_INPUT_TYPES.has(el.type);
        return el.nodeName === 'TEXTAREA' || el.nodeName === 'SELECT';
    }

    function isCheckable(el) {
        return el.nodeName === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio');
    }

    function optionValues(options) {
        return JSON.stringify(options.map((option) => option.value));
    }

    function fieldValue(el) {
        if (isCheckable(el)) return String(el.checked);
        if (el.nodeName === 'SELECT') return optionValues([...el.selectedOptions]);
        return el.value;
    }

    // マークアップが示す値。利用者の操作を含まない
    function fieldRendered(el) {
        if (isCheckable(el)) return String(el.defaultChecked);
        if (el.nodeName === 'SELECT') return optionValues([...el.options].filter((option) => option.defaultSelected));
        return el.type === 'file' ? '' : el.defaultValue;
    }

    function setFieldValue(el, value) {
        if (isCheckable(el)) {
            el.checked = value === 'true';
        } else if (el.nodeName === 'SELECT') {
            const selected = JSON.parse(value);
            for (const option of el.options) option.selected = selected.includes(option.value);
        } else {
            el.value = value;
        }
    }

    function captureFields(component, form, source) {
        const candidates = [...component.querySelectorAll('input,textarea,select'), ...form.elements];
        if (source) candidates.push(source);
        const sent = new Map();
        for (const el of candidates) {
            if (isUserField(el)) sent.set(el, fieldValue(el));
        }
        return sent;
    }

    // =========================================================================
    // 差分適用 (JS-7)
    // =========================================================================

    // 要素を置き換えず差分を当てるので、フォーカス、選択範囲、IME の変換が残る。
    // 新しい共有フォームは ID が変わる (RT-4.4) ので、ここで別の要素として入れ替わる
    // JS-22 ページの更新で初期値を経由させると、描画された値の変化として入力中の欄が消える
    const heldForRestore = new Set();

    const MORPH_IDENTITY = ['id', 'name', 'type', 'data-rosanna', 'data-rosanna-defer-url'];
    const FOCUSABLE = 'a[href],button:not([disabled]),select:not([disabled]),textarea:not([disabled]),'
        + 'input:not([disabled]):not([type="hidden"]),[tabindex]';

    function canMorph(live, next) {
        if (live.nodeType !== next.nodeType || live.nodeName !== next.nodeName) return false;
        if (live.nodeType !== Node.ELEMENT_NODE) return true;
        if (live.nodeName === 'TEMPLATE') return false;
        return MORPH_IDENTITY.every((name) => live.getAttribute(name) === next.getAttribute(name));
    }

    function morphAttributes(live, next) {
        for (const attr of [...live.attributes]) {
            if (!next.hasAttributeNS(attr.namespaceURI, attr.localName)) live.removeAttributeNode(attr);
        }
        // setAttribute は `@click` のように解析は通る名前を受け付けないので、ノードを複製する
        for (const attr of [...next.attributes]) {
            if (live.getAttributeNS(attr.namespaceURI, attr.localName) !== attr.value) {
                live.setAttributeNode(attr.cloneNode());
            }
        }
    }

    function fieldStateBefore(el, submission) {
        return {
            current: fieldValue(el),
            renderedBefore: fieldRendered(el),
            sentValue: submission && submission.sent.has(el) ? submission.sent.get(el) : undefined,
            inSentForm: Boolean(submission) && el.form === submission.form,
            isSource: Boolean(submission) && el === submission.source,
            keepSent: Boolean(submission) && submission.keepFields,
        };
    }

    function morphNode(live, next, submission) {
        if (live.nodeType !== Node.ELEMENT_NODE) {
            if (live.nodeValue !== next.nodeValue) live.nodeValue = next.nodeValue;
            return;
        }
        if (heldForRestore.has(live)) return;
        // 同じ URL の defer は解決済みか取得中で、応答のプレースホルダより新しい。更新は reloadDefer で行う
        if (live.hasAttribute('data-rosanna-defer-url')) return;

        const before = isUserField(live) ? fieldStateBefore(live, submission) : null;
        morphAttributes(live, next);
        morphChildren(live, next, submission);
        if (before !== null) {
            const value = chooseFieldValue({ ...before, renderedAfter: fieldRendered(live) });
            // 代入するとキャレットが動くので、違うときだけ書く
            if (fieldValue(live) !== value) setFieldValue(live, value);
        }
    }

    function morphTarget(next, cursor, byId) {
        if (next.nodeType !== Node.ELEMENT_NODE) {
            return cursor && canMorph(cursor, next) ? cursor : null;
        }
        if (next.id) {
            const keyed = byId.get(next.id);
            return keyed && canMorph(keyed, next) ? keyed : null;
        }
        for (let node = cursor; node; node = node.nextSibling) {
            if (node.nodeType === Node.ELEMENT_NODE && canMorph(node, next)) return node;
        }
        return null;
    }

    function morphChildren(liveParent, nextParent, submission) {
        const byId = new Map();
        for (const el of liveParent.children) {
            if (el.id && !byId.has(el.id)) byId.set(el.id, el);
        }
        const wantedIds = new Set([...nextParent.children].map((el) => el.id).filter(Boolean));
        const isWanted = (node) => node.nodeType === Node.ELEMENT_NODE && wantedIds.has(node.id);

        let cursor = liveParent.firstChild;
        for (const next of [...nextParent.childNodes]) {
            const target = morphTarget(next, cursor, byId);
            if (!target) {
                liveParent.insertBefore(next, cursor);
                continue;
            }
            // 移動するとフォーカスが外れるので、対象を動かさず手前のものを消す
            while (cursor && cursor !== target && !isWanted(cursor)) {
                const dropped = cursor;
                cursor = cursor.nextSibling;
                dropped.remove();
            }
            if (cursor === target) {
                cursor = cursor.nextSibling;
            } else {
                liveParent.insertBefore(target, cursor);
            }
            if (target.id) byId.delete(target.id);
            morphNode(target, next, submission);
        }
        while (cursor) {
            const dropped = cursor;
            cursor = cursor.nextSibling;
            dropped.remove();
        }
    }

    // フォーカスのある要素が消えると <body> に移り、次の Enter が何もしなくなる
    function restoreFocus(component, focused, focusIndex) {
        const active = document.activeElement;
        if (active === focused || (active && active !== document.body)) return;
        const focusable = [...component.querySelectorAll(FOCUSABLE)];
        const target = focused.isConnected ? focused : focusable[Math.min(focusIndex, focusable.length - 1)];
        if (target) target.focus({ preventScroll: true });
    }

    function applyPartial(component, html, submission = null) {
        const content = parseHtml(html);

        // SN-12
        const update = content.querySelector('[data-rosanna-snapshot-update]');
        if (update) {
            component.setAttribute('data-rosanna-snapshot', update.value);
            update.remove();
        }

        morphInstance(component, content, submission);
        rememberSnapshots(component);
    }

    function morphInstance(component, nextParent, submission) {
        const active = document.activeElement;
        const focused = active && active !== component && component.contains(active) ? active : null;
        const focusIndex = focused ? [...component.querySelectorAll(FOCUSABLE)].indexOf(focused) : -1;

        morphChildren(component, nextParent, submission);

        if (focused) restoreFocus(component, focused, focusIndex);
    }

    // =========================================================================
    // Snapshot の写しと永続化 (JS-8〜JS-15、VR-6)
    // =========================================================================

    const mirror = createSnapshotMirror(() => window.sessionStorage);

    function navigationType() {
        try {
            const [entry] = performance.getEntriesByType('navigation');
            return entry ? entry.type : 'navigate';
        } catch {
            return 'navigate';
        }
    }

    let mirrorMode = mirrorPolicy(navigationType(), false);
    // このページで写しの扱いを決めたインスタンス。defer で後から現れるものも同じ規則に通す
    const adopted = new Set();

    function instanceKey(wrapper) {
        return snapshotKey(location.pathname, wrapper.getAttribute('data-rosanna'));
    }

    // JS-15 persist の種類によらず、照合の写しは sessionStorage に置く。ここは保存値の置き場所
    function persistStore(wrapper) {
        const kind = wrapper.getAttribute('data-rosanna-persist');
        if (kind !== 'sessionStorage' && kind !== 'localStorage') return null;
        try {
            return window[kind] || null;
        } catch {
            return null;
        }
    }

    function readPersisted(wrapper) {
        const store = persistStore(wrapper);
        if (!store) return null;
        try {
            return store.getItem(instanceKey(wrapper));
        } catch {
            return null;
        }
    }

    function writePersisted(wrapper, value) {
        const store = persistStore(wrapper);
        if (!store) return;
        try {
            store.setItem(instanceKey(wrapper), value);
        } catch {
            // 容量切れでもページの中の値で動き続ける
        }
    }

    function removePersisted(wrapper) {
        const store = persistStore(wrapper);
        if (!store) return;
        try {
            store.removeItem(instanceKey(wrapper));
        } catch {
            // 消せなくてもページは止めない
        }
    }

    function snapshotWrappers(root) {
        const wrappers = [...root.querySelectorAll('[data-rosanna][data-rosanna-snapshot]')];
        if (root instanceof Element && root.matches('[data-rosanna][data-rosanna-snapshot]')) wrappers.unshift(root);
        return wrappers;
    }

    // JS-9 部分更新の後の DOM が正しい。子孫も親の再描画で変わるので一緒に写す
    function rememberSnapshots(root) {
        for (const wrapper of snapshotWrappers(root)) {
            const value = wrapper.getAttribute('data-rosanna-snapshot');
            mirror.write(instanceKey(wrapper), value);
            writePersisted(wrapper, value);
            adopted.add(wrapper.getAttribute('data-rosanna'));
        }
    }

    /**
     * VR-6 初めて見るインスタンスの写しを読み込みの種類に従って整える。
     * 鍵を一括で消さないのは、defer の中の persist のインスタンスは読み込み時点で DOM になく、
     * persist の有無が分からないため。
     *
     * @returns {string[]} 保存値との照合が要るインスタンスの ID (JS-12)
     */
    function adoptInstances() {
        const persisted = [];
        for (const wrapper of snapshotWrappers(document)) {
            const id = wrapper.getAttribute('data-rosanna');
            if (adopted.has(id)) continue;
            adopted.add(id);
            const key = instanceKey(wrapper);
            const hasPersist = wrapper.hasAttribute('data-rosanna-persist');
            if (mirrorMode === 'clear' && !hasPersist) mirror.remove(key);
            if (mirror.read(key) === null) mirror.write(key, wrapper.getAttribute('data-rosanna-snapshot'));
            if (hasPersist) persisted.push(id);
        }
        return persisted;
    }

    // JS-13 同じ値で毎回やり直さないように捨てる。写しが残ると次のクリックがまた止まるので今の値に戻す
    function discardSaved(wrapper) {
        removePersisted(wrapper);
        mirror.write(instanceKey(wrapper), wrapper.getAttribute('data-rosanna-snapshot'));
    }

    // AC-21、JS-3、JS-14、HD-9、HD-11
    function buildRestoreData(component, snapshot) {
        const id = component.getAttribute('data-rosanna');
        const data = new FormData();
        data.set('_rosanna_component', id);
        data.set('_rosanna_action', buildRestoreEnvelope(id, component.getAttribute('data-rosanna-restore-sig')));
        data.set('_rosanna_snapshot', snapshot);
        const ownField = (name) => formFieldOf(component, name);
        const csrf = ownField('_rosanna_csrf')
            || document.querySelector('form[data-rosanna-form] input[name="_rosanna_csrf"]');
        if (csrf) data.set('_rosanna_csrf', csrf.value);
        const deferQuery = ownField('_rosanna_defer_query');
        if (deferQuery) data.set('_rosanna_defer_query', deferQuery.value);
        const defer = ownField('_rosanna_defer');
        if (defer) data.set('_rosanna_defer', defer.value);
        setDescendantSnapshots(data, component);
        return data;
    }

    // そのインスタンスを対象にするフォームの欄。フォームはラッパーの外にもある
    function formFieldOf(component, name) {
        for (const form of document.querySelectorAll('form[data-rosanna-form]')) {
            if (componentOf(form) !== component) continue;
            const field = form.querySelector('input[name="' + name + '"]');
            if (field) return field;
        }
        return null;
    }

    function setDescendantSnapshots(data, component) {
        data.delete('_rosanna_snapshots[]');
        for (const descendant of component.querySelectorAll('[data-rosanna-snapshot]')) {
            data.append('_rosanna_snapshots[]', descendant.getAttribute('data-rosanna-snapshot'));
        }
    }

    // HD-16 応答の差分適用より先に受ける。適用が defer のキャッシュから古いフォームを戻さないように
    async function postPartial(data) {
        const response = await fetch(location.href, {
            method: 'POST',
            headers: { 'X-Rosanna-Partial': '1' },
            body: data,
        });
        if (response.headers.get('X-Rosanna-Session-Started') === '1') requestRefresh();
        return response;
    }

    // JS-12、JS-13
    async function restoreInstance(id) {
        const component = findInstance(id);
        if (!component) return;
        const saved = readPersisted(component);
        if (!saved || saved === component.getAttribute('data-rosanna-snapshot')) return;
        if (busyRelative(component)) return;

        lock(component, null);
        try {
            const response = await postPartial(buildRestoreData(component, saved));
            if (response.redirected || !response.ok) {
                discardSaved(component);
                return;
            }
            applyPartial(component, await response.text());
            processDeferred(component);
        } catch {
            // JS-13 ネットワークの失敗では保存値を残す
        } finally {
            unlock(component);
        }
    }

    // 親の応答が子を描き直すので、順番に送る
    async function restoreInOrder(ids) {
        for (const id of ids) {
            await restoreInstance(id);
        }
    }

    function restoreSnapshots(root) {
        const ids = [...(root || document).querySelectorAll('[data-rosanna][data-rosanna-persist]')]
            .map((el) => el.getAttribute('data-rosanna'));
        return restoreInOrder(ids);
    }

    // JS-11
    function forgetSnapshots(root) {
        for (const wrapper of snapshotWrappers(root || document)) {
            removePersisted(wrapper);
            mirror.remove(instanceKey(wrapper));
        }
        if (root) return;
        mirror.removeAll(SNAPSHOT_PREFIX);
        try {
            storageKeys(window.localStorage, SNAPSHOT_PREFIX).forEach((key) => window.localStorage.removeItem(key));
        } catch {
            // localStorage がなければ消すものもない
        }
    }

    Rosanna.restoreSnapshots = restoreSnapshots;
    Rosanna.forgetSnapshots = forgetSnapshots;

    // =========================================================================
    // 送信 (JS-1〜JS-6、JS-8、JS-8a)
    // =========================================================================

    const WITH_VALUE = 'data-rosanna-with-value';

    function buildSubmitData(form, component, { submitter, source, actionValue }) {
        const data = submitter ? new FormData(form, submitter) : new FormData(form);
        if (typeof actionValue === 'string') data.set('_rosanna_action', actionValue);
        if (source && source.hasAttribute(WITH_VALUE) && source.name
            && (!isCheckable(source) || source.checked)) {
            data.set(source.name, source.value);
        }
        // フォームの欄があれば優先する。古いフォームは自分のスナップショットと組で送る (VR-5、AC-17)
        const current = component.getAttribute('data-rosanna-snapshot');
        if (current !== null && !data.has('_rosanna_snapshot')) data.set('_rosanna_snapshot', current);
        setDescendantSnapshots(data, component);
        return data;
    }

    // JS-5 処理済みかもしれないので再送しない
    function reportFailure(form, component, detail) {
        const target = form.isConnected ? form : component;
        const notCancelled = target.dispatchEvent(new CustomEvent('rosanna:submit-error', {
            bubbles: true,
            cancelable: true,
            detail,
        }));
        if (!notCancelled) return;
        if (detail.response) {
            console.warn('[Rosanna] partial submit returned', detail.response.status, detail.response.statusText);
        } else {
            console.warn('[Rosanna] partial submit failed:', detail.error);
        }
        component.setAttribute('data-rosanna-error', '');
    }

    /**
     * JS-8a 差分適用の前に出す。キャンセルすると欄を保たず、JS-7 の順に従う。
     *
     * @returns {boolean} 欄を保つか
     */
    function announceStale(form, component, detail) {
        const target = form.isConnected ? form : component;
        return target.dispatchEvent(new CustomEvent('rosanna:stale', {
            bubbles: true,
            cancelable: true,
            detail: { component, form, ...detail },
        }));
    }

    function followRedirect(component, response) {
        // 待っている値はまだサーバーに届いていない。移動すると中断される。その応答がまた redirect する
        if (hasResends(component)) return;
        const target = sameOriginRedirect(response.url, location.href);
        if (target !== null) {
            location.href = target;
        } else {
            console.warn('[Rosanna] ignoring cross-origin redirect after action:', response.url);
            location.reload();
        }
    }

    // JS-8 送らず、写しで描き直す
    async function recoverFromStale(component, form, submission, mirrored) {
        submission.keepFields = announceStale(form, component, { origin: 'client', snapshot: mirrored });
        const response = await postPartial(buildRestoreData(component, mirrored));
        if (response.redirected) {
            discardSaved(component);
            followRedirect(component, response);
            return;
        }
        if (!response.ok) {
            const rejected = () => {
                discardSaved(component);
                reportFailure(form, component, { response });
            };
            // 止めた時点で rosanna:stale は出している
            if (!recoverByRefresh(submission, response, rejected, { announced: true, restore: mirrored })) rejected();
            return;
        }
        applyPartial(component, await response.text(), submission);
        processDeferred(component);
    }

    async function applyResponse(component, form, submission, response) {
        if (response.redirected) {
            // JS-21 fetch は 303 のヘッダーを見せない。待っている値は移動の前に古いフォームで送られる
            if (submission.tokenless && hasResends(component)) requestRefresh();
            followRedirect(component, response);
            return;
        }
        if (!response.ok) {
            const rejected = () => reportFailure(form, component, { response });
            if (!recoverByRefresh(submission, response, rejected)) rejected();
            return;
        }
        failureRefreshTried = false;
        const html = await response.text();
        const stale = response.headers.get('X-Rosanna-Stale') === '1';
        if (stale) submission.keepFields = announceStale(form, component, { origin: 'server', response });

        // 差分適用でこのフォームが別のものになることがあるので先に読む。適用されなかった action では動かさない
        const reloadFrom = stale ? null : [form, submission.source].find(
            (el) => el && el.hasAttribute('data-rosanna-reload-defer'),
        );
        const reloadTargets = reloadFrom ? reloadFrom.getAttribute('data-rosanna-reload-defer') : null;

        applyPartial(component, html, submission);

        // JS-20 再走査より先に強制の取得を始めると、再走査は 1 つの取得に譲る
        if (reloadTargets !== null) dispatchReloadDefer(reloadTargets);
        processDeferred(component);
    }

    /**
     * request:
     * - trigger: 連打の判定に使う引き金。送信ボタンかイベントの要素、ボタンがなければフォーム
     * - submitter: FormData に渡す送信ボタン
     * - source: イベントの要素か送信ボタン。data-rosanna-reload-defer と with-value を読む
     * - event: data-rosanna-on-<event> のとき
     * - actionValue: data-rosanna-action-<event> の値
     */
    async function sendAction(form, request) {
        const component = componentOf(form);
        if (!component) return;

        // JS-24 古いフォームを送ると 403 になる
        if (refresh) {
            queueResend(REFRESH_HOLDER, form, request);
            return;
        }

        const holder = busyRelative(component) || sessionGate(form, component, request);
        if (holder) {
            queueResend(holder, form, request);
            return;
        }

        const submission = {
            component,
            form,
            trigger: request.trigger,
            source: request.source || null,
            sent: captureFields(component, form, request.source),
            keepFields: false,
            tokenless: false,
        };
        lock(component, submission);
        component.removeAttribute('data-rosanna-error');

        try {
            const data = buildSubmitData(form, component, request);
            const envelope = data.get('_rosanna_action');
            if (envelope === null) {
                console.warn('[Rosanna] no _rosanna_action to send for', form);
                return;
            }
            submission.tokenless = !data.get('_rosanna_csrf');
            if (submission.tokenless && isSessionEnvelope(envelope, component.hasAttribute('data-rosanna-snapshot'))) sessionStarter = component;
            const mirrored = component.hasAttribute('data-rosanna-snapshot')
                ? mirror.read(instanceKey(component))
                : null;
            if (mirrored !== null && isEnvelopeStale(envelope, mirrored)) {
                await recoverFromStale(component, form, submission, mirrored);
            } else {
                await applyResponse(component, form, submission, await postPartial(data));
            }
        } catch (error) {
            reportFailure(form, component, { error });
        } finally {
            if (sessionStarter === component) sessionStarter = null;
            unlock(component);
        }
    }

    /**
     * JS-25 Cookie のない 2 つの Session の action はそれぞれセッションを作り、後の Cookie が勝って
     * 先の更新が失われる。トークンのないフォームの送信が終わるまで、他の Session の action を待たせる。
     */
    let sessionStarter = null;

    function sessionGate(form, component, request) {
        if (!sessionStarter) return null;
        const envelope = buildSubmitData(form, component, request).get('_rosanna_action');
        return isSessionEnvelope(envelope, component.hasAttribute('data-rosanna-snapshot')) ? sessionStarter : null;
    }

    function onSubmit(event) {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        if (!form.matches('[data-rosanna-form]')) return;
        // JS-1 対象がなければ通常の送信に任せる。送信ボタンの action も届く
        if (!componentOf(form)) return;
        event.preventDefault();
        const submitter = event.submitter || null;
        sendAction(form, { trigger: submitter || form, submitter, source: submitter });
    }

    document.addEventListener('submit', onSubmit);

    // アプリの form の中のラッパーは、部分更新で DOM の上ではフォームが入れ子になる (RT-4.5)。
    // Chromium は入れ子のフォームの submit を外のフォームで止めるので、document まで泡立たない
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.parentElement && form.parentElement.closest('form')) onSubmit(event);
    }, true);

    // =========================================================================
    // 再送の待ち (JS-6)
    // =========================================================================

    // 固定値の action は描画時に値が決まっているので、後から送ると別の行に当たりかねない。捨てる。
    // with-value の action は送るときに値を読むので、最後の値を完了後に 1 回送る。
    const resends = new WeakMap();
    let composing = null;

    function hasResends(component) {
        const queue = resends.get(component);
        return Boolean(queue && queue.size > 0);
    }

    // フォームは共有されるので、フォームでは区別しない。引き金と値で見る
    function isRepeat(submission, form, trigger) {
        if (!submission || submission.trigger !== trigger) return false;
        return [trigger, ...form.elements].every(
            (el) => !submission.sent.has(el) || submission.sent.get(el) === fieldValue(el),
        );
    }

    function controlSelector(el) {
        if (el.id) return '[id="' + CSS.escape(el.id) + '"]';
        const selector = el.tagName.toLowerCase() + '[name="' + CSS.escape(el.name) + '"]';
        return isCheckable(el) ? selector + '[value="' + CSS.escape(el.value) + '"]' : selector;
    }

    function queueResend(holder, form, request) {
        const { trigger, event, submitter } = request;
        const withValue = event ? trigger.hasAttribute(WITH_VALUE) : form.hasAttribute(WITH_VALUE);
        if (!withValue || isRepeat(inFlight.get(holder), form, trigger)) return;

        if (!resends.has(holder)) resends.set(holder, new Map());
        const queue = resends.get(holder);
        if (event) {
            queue.set(event + ':' + controlSelector(trigger), { event, trigger });
        } else {
            queue.set(form, { form, submitter });
        }
    }

    // 差分適用で要素が置き換わると、入力した値は古い要素にしかない
    function liveTrigger(trigger, event, root) {
        if (trigger.isConnected) return trigger;
        const scope = root && root.isConnected ? root : document;
        const current = scope.querySelector(controlSelector(trigger) + '[data-rosanna-on-' + event + ']');
        if (current) setFieldValue(current, fieldValue(trigger));
        return current;
    }

    function flushResends(component) {
        const queue = resends.get(component);
        if (!queue) return;
        for (const [key, entry] of queue) {
            queue.delete(key);
            if (entry.form) {
                const form = entry.form.isConnected
                    ? entry.form
                    : (entry.form.id ? document.getElementById(entry.form.id) : null);
                if (form) {
                    const submitter = entry.submitter && entry.submitter.form === form ? entry.submitter : null;
                    sendAction(form, { trigger: submitter || form, submitter, source: submitter });
                }
            } else {
                const trigger = liveTrigger(entry.trigger, entry.event, component);
                // 変換中なら compositionend が最後の値を送る
                if (trigger && trigger !== composing) fireTrigger(trigger, entry.event);
            }
            if (locked.has(component)) return;
        }
    }

    // =========================================================================
    // data-rosanna-on-<event> (RT-4.1)
    // =========================================================================

    function fireTrigger(trigger, event) {
        const id = trigger.getAttribute('data-rosanna-on-' + event);
        const form = id ? document.getElementById(id) : null;
        if (!form || !form.matches('[data-rosanna-form]')) {
            console.warn('[Rosanna] no action form found for', trigger);
            return;
        }
        const actionValue = trigger.getAttribute('data-rosanna-action-' + event);
        if (!componentOf(form)) {
            // JS-1 共有フォームは action を持たないので、通常の送信の前に入れる
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_rosanna_action';
            field.value = actionValue ?? '';
            form.append(field);
            form.submit();
            return;
        }
        sendAction(form, { trigger, source: trigger, event, actionValue });
    }

    // 行の中のリンクやチェックボックスのクリックは、行ではなくその要素のもの
    const INTERACTIVE = 'a[href],button,input,select,textarea,label';

    document.addEventListener('click', (event) => {
        const start = startElement(event);
        const trigger = start ? start.closest('[data-rosanna-on-click]') : null;
        if (!trigger) return;
        const interactive = start.closest(INTERACTIVE);
        if (interactive && interactive !== trigger && trigger.contains(interactive)) return;
        if (trigger.matches('a[href]')) {
            // 新しいタブで開く操作は残す
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
        }
        fireTrigger(trigger, 'click');
    });

    document.addEventListener('change', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-rosanna-on-change]') : null;
        if (trigger) fireTrigger(trigger, 'change');
    });

    const pendingInputs = new Map();

    function cancelPendingInput(trigger) {
        clearTimeout(pendingInputs.get(trigger));
        pendingInputs.delete(trigger);
    }

    function scheduleInput(trigger) {
        cancelPendingInput(trigger);
        const root = trigger.closest('[data-rosanna]');
        pendingInputs.set(trigger, setTimeout(() => {
            pendingInputs.delete(trigger);
            const current = trigger.hasAttribute(WITH_VALUE)
                ? liveTrigger(trigger, 'input', root)
                : (trigger.isConnected ? trigger : null);
            if (current) fireTrigger(current, 'input');
        }, INPUT_DEBOUNCE_MS));
    }

    function inputTrigger(event) {
        return event.target instanceof Element ? event.target.closest('[data-rosanna-on-input]') : null;
    }

    document.addEventListener('input', (event) => {
        if (event.isComposing) return;
        const trigger = inputTrigger(event);
        if (trigger) scheduleInput(trigger);
    });

    // 変換中は値が確定していないうえ、要素を差し替えると変換が切れる
    document.addEventListener('compositionstart', (event) => {
        if (!(event.target instanceof Element)) return;
        composing = event.target;
        const trigger = inputTrigger(event);
        if (trigger && pendingInputs.has(trigger)) cancelPendingInput(trigger);
    });

    document.addEventListener('compositionend', (event) => {
        composing = null;
        const trigger = inputTrigger(event);
        if (trigger) scheduleInput(trigger);
    });

    // =========================================================================
    // defer (JS-16〜JS-20)
    // =========================================================================

    const deferMemory = createLruCache(DEFER_CACHE_MAX);
    const deferStore = createDeferStore(() => window.localStorage);
    const deferStates = new WeakMap();
    // JS-23 解決済みの defer の URL。解決すると文書からプレースホルダが消えるものがある
    const resolvedDefers = new Set();

    function deferState(el) {
        if (!deferStates.has(el)) deferStates.set(el, { generation: 0, running: false, controller: null, epoch: 0 });
        return deferStates.get(el);
    }

    function fragmentHoldsSessionData(content) {
        return content.querySelector(SESSION_BOUND_SELECTOR) !== null;
    }

    // data-rosanna-defer-name があるものはラッパーを残し、後から取得し直せるようにする
    function placeFragment(el, fragment) {
        resolvedDefers.add(el.getAttribute('data-rosanna-defer-url'));
        if (el.hasAttribute('data-rosanna-defer-name')) {
            el.replaceChildren(fragment);
            el.removeAttribute('aria-busy');
            el.setAttribute('data-rosanna-defer-loaded', '');
            processDeferred(el);
        } else {
            processDeferred(fragment);
            el.replaceWith(fragment);
        }
        restoreInOrder(adoptInstances());
    }

    function readCachedFragment(el, url) {
        const remembered = deferMemory.get(url);
        if (remembered) return remembered.cloneNode(true);
        if (!el.hasAttribute('data-rosanna-defer-cache')) return null;

        const html = deferStore.read(url, parseCacheTtlMs(el.getAttribute('data-rosanna-defer-cache-ttl')));
        if (html === null) return null;
        const content = parseHtml(html);
        // JS-17 版の古いタブや手で書かれた値でも、トークンを持つ断片は使わない
        if (fragmentHoldsSessionData(content)) {
            deferStore.remove(url);
            return null;
        }
        deferMemory.set(url, content.cloneNode(true));
        return content;
    }

    function rememberFragment(el, url, html, content) {
        deferMemory.set(url, content.cloneNode(true));
        if (!el.hasAttribute('data-rosanna-defer-cache')) return;
        if (fragmentHoldsSessionData(content)) {
            console.warn('[Rosanna] not persisting defer fragment: it contains a form or session data', url);
            return;
        }
        deferStore.write(url, html);
    }

    function isUsableDeferResponse(response, url) {
        if (!response.ok) {
            // フォールバックが出たままになるので、設定の誤りが分かるように出す
            console.warn('[Rosanna] defer fetch returned', response.status, response.statusText, url);
            return false;
        }
        if (sameOriginRedirect(response.url, location.href) === null) {
            console.warn('[Rosanna] refusing defer response: redirected to another origin', response.url);
            return false;
        }
        if (!isHtmlContentType(response.headers.get('content-type'))) {
            console.warn('[Rosanna] refusing defer response: Content-Type is not text/html', url);
            return false;
        }
        return true;
    }

    // JS-19 force は reloadDefer だけが使う。前の取得を中断して置き換える。それ以外は実行中の取得に譲る
    async function loadDefer(el, { force = false } = {}) {
        const url = el.getAttribute('data-rosanna-defer-url');
        if (!url) return;

        // キャッシュより先に確かめる。キャッシュの鍵を汚されても出さない
        const resolved = resolveDeferUrl(url, Rosanna.deferPrefix, location.href);
        if (resolved === null) {
            console.warn('[Rosanna] refusing defer fetch: URL is not under the same-origin defer prefix', url);
            return;
        }
        if (el.hasAttribute('data-rosanna-defer-loaded')) return;

        const state = deferState(el);
        if (state.running && !force) return;

        const generation = ++state.generation;
        const isLatest = () => state.generation === generation;
        if (state.controller) state.controller.abort();
        state.controller = null;
        state.running = true;
        state.epoch = refreshEpoch;
        const startedAt = refreshEpoch;

        try {
            const cached = readCachedFragment(el, url);
            if (cached) {
                placeFragment(el, cached);
                return;
            }

            el.setAttribute('aria-busy', 'true');
            const controller = new AbortController();
            state.controller = controller;
            const response = await fetch(resolved, {
                method: 'GET',
                headers: { 'X-Rosanna-Defer': '1' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!isUsableDeferResponse(response, url)) return;

            const html = await response.text();
            const content = parseHtml(html);
            // 新しい取得に負けても、この URL の断片としては正しいのでキャッシュには入れる。
            // ただしセッションができる前に取得したフォームは古い (JS-23)
            if (startedAt === refreshEpoch || !fragmentHoldsSessionData(content)) rememberFragment(el, url, html, content);
            if (isLatest()) placeFragment(el, content);
        } catch (error) {
            if (!error || error.name !== 'AbortError') {
                console.warn('[Rosanna] defer fetch failed:', error, url);
            }
        } finally {
            if (isLatest()) {
                state.running = false;
                state.controller = null;
                el.removeAttribute('aria-busy');
            }
        }
    }

    function processDeferred(root) {
        for (const el of (root || document).querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded])')) {
            loadDefer(el);
        }
    }

    function clearDeferCache(target) {
        if (target === undefined || target === null || target === '') {
            deferMemory.clear();
            deferStore.clear();
            return;
        }
        const matches = (url) => url === target || deferUrlMatchesName(url, target);
        deferMemory.keys().filter(matches).forEach((url) => deferMemory.delete(url));
        deferStore.urls().filter(matches).forEach((url) => deferStore.remove(url));
    }

    /** @returns {number} 取得し直した領域の数。名前の書き間違いが分かるように返す */
    function reloadDefer(target) {
        const everything = target === undefined || target === null || target === '';
        let count = 0;
        for (const el of document.querySelectorAll('[data-rosanna-defer-name][data-rosanna-defer-url]')) {
            const url = el.getAttribute('data-rosanna-defer-url');
            if (!everything && el.getAttribute('data-rosanna-defer-name') !== target
                && url !== target && !deferUrlMatchesName(url, target)) {
                continue;
            }
            clearDeferCache(url);
            el.removeAttribute('data-rosanna-defer-loaded');
            count++;
            loadDefer(el, { force: true });
        }
        return count;
    }

    function dispatchReloadDefer(value) {
        const targets = parseReloadTargets(value);
        if (targets.length === 0) {
            reloadDefer();
        } else {
            targets.forEach((target) => reloadDefer(target));
        }
    }

    // JS-20 フォームの中の操作は、部分更新の成功の後に送信の経路で動く
    document.addEventListener('click', (event) => {
        const start = startElement(event);
        const trigger = start ? start.closest('[data-rosanna-reload-defer]') : null;
        if (!trigger || trigger.tagName === 'FORM') return;
        if (trigger.closest('[data-rosanna-form]')) return;
        if (trigger.form && trigger.form.matches('[data-rosanna-form]')) return;
        if (trigger.hasAttribute('data-rosanna-on-click')) return;
        if (trigger.tagName === 'A') event.preventDefault();
        dispatchReloadDefer(trigger.getAttribute('data-rosanna-reload-defer'));
    });

    Rosanna.clearDeferCache = clearDeferCache;
    Rosanna.reloadDefer = reloadDefer;

    // =========================================================================
    // ページの更新 (JS-21〜JS-26)
    // =========================================================================

    const REFRESH_HOLDER = {};
    // 実行中の更新。{ forced, recoveries }
    let refresh = null;
    // セッションができた回数。これより前に始めた defer の取得はトークンを持たない
    let refreshEpoch = 0;
    let failureRefreshTried = false;

    /** 呼ぶ側はロックの中にいる。実行はロックがすべて外れた後 */
    function requestRefresh(recovery = null) {
        if (!refresh) {
            refresh = { forced: false, recoveries: [] };
            refreshEpoch++;
            forgetSessionFragments();
            restartOutdatedDefers();
            runRefresh(refresh);
        }
        if (recovery) {
            refresh.recoveries.push(recovery);
        } else {
            refresh.forced = true;
        }
    }

    /**
     * JS-26 ヘッダーを受け取れなかった古いフォームの拒否を、1 回だけ更新で受ける。
     *
     * @returns {boolean} 引き受けたか。false なら呼ぶ側が失敗として扱う
     */
    function recoverByRefresh(submission, response, rejected, { announced = false, restore = null } = {}) {
        if (!submission.tokenless || (response.status !== 403 && response.status !== 400)) return false;
        if (failureRefreshTried && !refresh) return false;
        failureRefreshTried = true;
        requestRefresh({ submission, response, rejected, announced, restore });
        return true;
    }

    // JS-23 localStorage にはフォームがないので (JS-17)、メモリの段だけ見る
    function forgetSessionFragments() {
        for (const url of deferMemory.keys()) {
            if (fragmentHoldsSessionData(deferMemory.get(url))) deferMemory.delete(url);
        }
    }

    function restartOutdatedDefers() {
        for (const el of document.querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded])')) {
            const state = deferStates.get(el);
            if (state && state.running && state.epoch !== refreshEpoch) loadDefer(el, { force: true });
        }
    }

    async function fetchPageForRefresh() {
        const response = await fetch(location.href, {
            method: 'GET',
            // 匿名のページがブラウザのキャッシュから返ると、トークンのないフォームのままになる
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        });
        // 別の URL のページは署名の scope が違う (SG-5)。同じオリジンでも差分を当てない
        if (!response.ok || response.redirected || !isHtmlContentType(response.headers.get('content-type'))) {
            throw new Error('unusable response: ' + response.status + ' ' + response.url);
        }
        return parseHtml(await response.text());
    }

    async function fetchDeferForRefresh(placeholder, url) {
        const resolved = resolveDeferUrl(url, Rosanna.deferPrefix, location.href);
        if (resolved === null) return null;
        try {
            const response = await fetch(resolved, {
                method: 'GET',
                headers: { 'X-Rosanna-Defer': '1' },
                credentials: 'same-origin',
            });
            if (!isUsableDeferResponse(response, url)) return null;
            const html = await response.text();
            const content = parseHtml(html);
            rememberFragment(placeholder, url, html, content);
            return content;
        } catch (error) {
            console.warn('[Rosanna] defer fetch failed:', error, url);
            return null;
        }
    }

    /**
     * 解決済みの defer を応答の中で解決しておく。ライブの DOM と同じ形になるので、中のインスタンスも ID で
     * 差分を当てられ、入力と Snapshot の状態が残る。取得に失敗すればプレースホルダのままで、既存の取得に任せる。
     */
    async function fillResolvedDefers(content) {
        const seen = new Set();
        for (;;) {
            const pending = [...content.querySelectorAll('[data-rosanna-defer-url]')].filter(
                (el) => !seen.has(el) && resolvedDefers.has(el.getAttribute('data-rosanna-defer-url')),
            );
            if (pending.length === 0) return;
            await Promise.all(pending.map(async (el) => {
                seen.add(el);
                const url = el.getAttribute('data-rosanna-defer-url');
                const remembered = deferMemory.get(url);
                const fragment = remembered ? remembered.cloneNode(true) : await fetchDeferForRefresh(el, url);
                if (!fragment) return;
                if (el.hasAttribute('data-rosanna-defer-name')) {
                    el.replaceChildren(fragment);
                    el.setAttribute('data-rosanna-defer-loaded', '');
                } else {
                    el.replaceWith(fragment);
                }
            }));
        }
    }

    function csrfTokenIn(content) {
        for (const field of content.querySelectorAll('input[name="_rosanna_csrf"]')) {
            if (field.value !== '') return field.value;
        }
        return null;
    }

    /**
     * HD-9 別の defer の中のインスタンスは ID が重なるので、ID と文書の中の出現順で見分ける。
     * 入れ子のラッパーも数えないと、応答とライブで順番がずれる。
     *
     * @returns {Map<Element, string>}
     */
    function instanceSlots(root) {
        const counts = new Map();
        const slots = new Map();
        for (const wrapper of root.querySelectorAll('[data-rosanna]')) {
            const id = wrapper.getAttribute('data-rosanna');
            const occurrence = counts.get(id) || 0;
            counts.set(id, occurrence + 1);
            // ID に制御文字は入らない (Action::isValidInstanceId)
            slots.set(wrapper, id + '\0' + occurrence);
        }
        return slots;
    }

    function instanceAt(slot) {
        const [id, occurrence] = slot.split('\0');
        return document.querySelectorAll('[data-rosanna="' + CSS.escape(id) + '"]')[Number(occurrence)] || null;
    }

    function pageSnapshots() {
        const slots = instanceSlots(document);
        const snapshots = new Map();
        for (const wrapper of snapshotWrappers(document)) {
            snapshots.set(slots.get(wrapper), wrapper.getAttribute('data-rosanna-snapshot'));
        }
        return snapshots;
    }

    // ラッパーは host が属性を足していることがあるので、Rosanna の属性だけ揃える
    function syncWrapperAttributes(live, next) {
        for (const name of ['data-rosanna-snapshot', 'data-rosanna-restore-sig', 'data-rosanna-persist']) {
            const value = next.getAttribute(name);
            if (value === null) {
                live.removeAttribute(name);
            } else if (live.getAttribute(name) !== value) {
                live.setAttribute(name, value);
            }
        }
    }

    function outerForms(root) {
        return [...root.querySelectorAll('form[data-rosanna-form][id]')].filter((form) => !form.closest('[data-rosanna]'));
    }

    function formComponentId(form) {
        const field = form.querySelector('input[name="_rosanna_component"]');
        return field ? field.value : null;
    }

    // RT-4.5 アプリの form の中のラッパーのフォームはその form の後ろにあり、どのラッパーの差分にも入らない
    function syncOuterForms(content, appliedIds) {
        const stale = outerForms(document).filter((form) => appliedIds.has(formComponentId(form)));
        for (const next of outerForms(content)) {
            const id = formComponentId(next);
            if (!appliedIds.has(id)) continue;
            const anchor = stale.find((form) => formComponentId(form) === id);
            if (anchor) {
                anchor.before(next);
            } else {
                const wrapper = findInstance(id);
                (wrapper.closest('form') || wrapper).after(next);
            }
        }
        stale.forEach((form) => form.remove());
    }

    function submissionFor(live, recoveries) {
        const recovery = recoveries.find(({ submission }) => live === submission.component || live.contains(submission.component));
        return recovery ? recovery.submission : null;
    }

    /** @returns {Map<string, Element>} restore で戻すインスタンス (instanceSlots) と、失敗したときに当てる描画 */
    function applyRefresh(content, before, recoveries) {
        const wrappers = [...content.querySelectorAll('[data-rosanna]')];
        // 親の差分で応答のノードがライブに移るので、応答の順番は先に決める
        const slots = instanceSlots(content);
        const appliedIds = new Set();
        const held = new Map();
        for (const next of wrappers) {
            const slot = slots.get(next);
            const live = instanceAt(slot);
            if (!live) continue;
            appliedIds.add(next.getAttribute('data-rosanna'));
            if (needsRestoreAfterRefresh(before.get(slot), next.getAttribute('data-rosanna-snapshot'))) {
                heldForRestore.add(live);
                held.set(slot, next.cloneNode(true));
                // JS-22 新しいセッションの署名でないと restore が通らない
                const signature = next.getAttribute('data-rosanna-restore-sig');
                if (signature !== null) live.setAttribute('data-rosanna-restore-sig', signature);
            }
        }

        // 親の差分が子にも届くので、外側とだけ対応を取る。defer の領域は差分が入らない (morphNode) ので中も取る
        const outermost = wrappers.filter((next) => {
            const boundary = next.parentElement ? next.parentElement.closest('[data-rosanna],[data-rosanna-defer-url]') : null;
            return !boundary || !boundary.matches('[data-rosanna]');
        });
        for (const next of outermost) {
            const live = instanceAt(slots.get(next));
            // 親の差分で挿入された領域の中は、応答のノードがそのままライブになっている
            if (!live || live === next || heldForRestore.has(live)) continue;
            syncWrapperAttributes(live, next);
            morphInstance(live, next, submissionFor(live, recoveries));
        }
        syncOuterForms(content, appliedIds);
        return held;
    }

    function releaseHolds(component) {
        for (const wrapper of [...heldForRestore]) {
            if (component.contains(wrapper)) heldForRestore.delete(wrapper);
        }
    }

    // JS-22 戻せなければ描画の値を当てる。写しが残っていれば、次のクリックが JS-8 で最新を描く
    async function restoreHeld(component, snapshot, next, token) {
        const data = buildRestoreData(component, snapshot);
        if (token !== null) data.set('_rosanna_csrf', token);

        let rejected = false;
        lock(component, null);
        try {
            const response = await postPartial(data);
            if (!response.redirected && response.ok) {
                const html = await response.text();
                releaseHolds(component);
                applyPartial(component, html);
                processDeferred(component);
                return;
            }
            rejected = true;
        } catch {
            // 通信の失敗では写しと保存値を残す (JS-13)
        } finally {
            unlock(component);
        }

        heldForRestore.delete(component);
        syncWrapperAttributes(component, next);
        morphInstance(component, next, null);
        if (rejected) discardSaved(component);
        processDeferred(component);
    }

    async function restoreHeldInstances(held, before, token) {
        for (const [slot, next] of held) {
            const component = instanceAt(slot);
            // 親の restore の応答で描き直されたものは終わっている
            if (component && heldForRestore.has(component)) {
                await restoreHeld(component, before.get(slot), next, token);
            }
        }
    }

    // JS-9 ただし写しの方が新しければ残す。戻す操作の後のページと、restore に失敗したインスタンス
    function rememberAfterRefresh() {
        for (const wrapper of snapshotWrappers(document)) {
            const value = wrapper.getAttribute('data-rosanna-snapshot');
            const mirrored = mirror.read(instanceKey(wrapper));
            adopted.add(wrapper.getAttribute('data-rosanna'));
            if (mirrored !== null && snapshotVersion(mirrored) > snapshotVersion(value)) continue;
            mirror.write(instanceKey(wrapper), value);
            writePersisted(wrapper, value);
        }
    }

    function finishRefresh() {
        heldForRestore.clear();
        refresh = null;
        flushResends(REFRESH_HOLDER);
    }

    async function applyFetchedPage(current, before) {
        const content = await fetchPageForRefresh();
        await fillResolvedDefers(content);

        const token = csrfTokenIn(content);
        const { recoveries } = current;
        const slots = instanceSlots(document);
        if (token === null && !current.forced) {
            // JS-26 セッションがないなら古いフォームではない。描き直しても同じ失敗になる
            recoveries.forEach(({ rejected }) => rejected());
            return;
        }
        for (const { submission, response, announced, restore } of recoveries) {
            if (!announced) {
                submission.keepFields = announceStale(submission.form, submission.component, { origin: 'server', response });
            }
            if (restore !== null) before.set(slots.get(submission.component), restore);
        }

        const held = applyRefresh(content, before, recoveries);
        processDeferred();
        await restoreHeldInstances(held, before, token);
        rememberAfterRefresh();
    }

    async function runRefresh(current) {
        // 送信中の応答はセッションの前の描画かもしれない。先に適用が済むのを待ち、その状態を覚える
        await whenIdle();
        try {
            await applyFetchedPage(current, pageSnapshots());
        } catch (error) {
            console.warn('[Rosanna] page refresh failed, reloading:', error);
            // refresh を残す。読み込み直すまで古いフォームを送らない
            location.reload();
            return;
        }
        finishRefresh();
    }

    // =========================================================================
    // 起動
    // =========================================================================

    deferStore.reconcile();

    function boot() {
        processDeferred();
        restoreInOrder(adoptInstances());
    }

    // VR-6 bfcache の復帰ではスクリプトが動き直さない。写しを残して照合する
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        mirrorMode = mirrorPolicy(navigationType(), true);
        boot();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
