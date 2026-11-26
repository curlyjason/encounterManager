/*
 * Stat block card, shared by every view (side bar, expanded row, pop-up, own tab).
 *   StatBlock.load({ id } | { ref })                → data from api.php 'statblock'
 *   StatBlock.render(el, data, { view, onView, onClose, onChange })
 * Also handles adding, replacing and removing the monster's image.
 */
(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    async function post(url, body) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const json = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
        if (!res.ok || json.error) throw new Error(json.error || `HTTP ${res.status}`);
        return json;
    }

    const VIEWS = [
        ['side', 'Side bar', '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M10 2.5v11"/></svg>'],
        ['row', 'Expand the row', '<svg viewBox="0 0 16 16"><rect x="1.5" y="2" width="13" height="3" rx="1"/><rect x="1.5" y="7" width="13" height="7" rx="1"/></svg>'],
        ['pop', 'Pop-up', '<svg viewBox="0 0 16 16"><rect x="1.5" y="1.5" width="13" height="13" rx="1.5" opacity=".35"/><rect x="4" y="4" width="8" height="8" rx="1"/></svg>'],
        ['tab', 'New tab', '<svg viewBox="0 0 16 16"><path d="M9 2.5h4.5V7M13.5 2.5 7.5 8.5M12 9.5v4H2.5V4h4"/></svg>'],
    ];

    // ---- Markdown → HTML (showdown), with anything scripty stripped -------------------
    let converter = null;
    function mdToHtml(md) {
        if (!window.showdown) return `<pre>${esc(md)}</pre>`;
        converter ||= new window.showdown.Converter({ tables: true, strikethrough: true, simpleLineBreaks: false, openLinksInNewWindow: true });
        const tpl = document.createElement('template');
        tpl.innerHTML = converter.makeHtml(md);
        tpl.content.querySelectorAll('script, style, iframe, object, embed, form, link, meta').forEach((n) => n.remove());
        tpl.content.querySelectorAll('*').forEach((n) => [...n.attributes].forEach((a) => {
            if (/^on/i.test(a.name) || (/^(href|src)$/i.test(a.name) && /^\s*javascript:/i.test(a.value))) n.removeAttribute(a.name);
        }));
        // Links inside 5eADB stat blocks are relative to its pages: they wouldn't work here.
        tpl.content.querySelectorAll('a[href]').forEach((a) => {
            if (!/^https?:/i.test(a.getAttribute('href'))) a.replaceWith(...a.childNodes);
        });
        return tpl.innerHTML;
    }

    const sign = (n) => (n == null ? '–' : (n >= 0 ? '+' : '') + n);

    function viewsHtml(opts) {
        return opts.view === 'tab' ? '' : `<div class="sb-bar"><span class="sb-bar-label muted">Show as</span><div class="sb-views" role="group" aria-label="Show stat blocks as">
            ${VIEWS.map(([v, label, icon]) => `<button class="sb-view ${v === opts.view ? 'on' : ''}" data-view="${v}" title="${label}" aria-label="${label}" aria-pressed="${v === opts.view}">${icon}</button>`).join('')}
            </div><button class="sb-close" title="Close (Esc)" aria-label="Close">✕</button>
        </div>`;
    }

    function pcHtml(d, opts) {
        const hp = `${d.hp_now} / ${d.hp}${d.temp_hp ? ` +${d.temp_hp}` : ''}`;
        const who = [d.level ? `Level ${d.level}` : 'Level ?', d.player ? `played by ${d.player}` : ''].filter(Boolean).join(' · ');
        const links = [
            d.link ? `<a class="button primary" href="${esc(d.link)}" target="_blank" rel="noopener">Character sheet ↗</a>` : '',
            d.notion_url ? `<a class="button" href="${esc(d.notion_url)}" target="_blank" rel="noopener">Open in Notion ↗</a>` : '',
        ].join('');
        return `${viewsHtml(opts)}
        <header class="sb-head">
            <div class="sb-art"><span class="sb-type-icon" style="-webkit-mask-image:url('${esc(d.type_icon)}');mask-image:url('${esc(d.type_icon)}')" role="img" aria-label="Player character"></span></div>
            <div class="sb-title">
                <h2>${esc(d.name)} <span class="kind kind-pc">PC</span></h2>
                <div class="sb-sub muted">${esc(who)}</div>
                <div class="sb-stats">
                    <span><b>AC</b> ${esc(d.ac ?? '–')}</span>
                    <span><b>HP</b> ${esc(hp)}</span>
                    <span><b>Init</b> ${esc(sign(d.init))}</span>
                </div>
            </div>
        </header>
        <div class="sb-body sb-pc">
            ${d.conditions.length ? `<p class="sb-conds">${d.conditions.map((k) => `<span class="chip">${esc(k)}</span>`).join(' ')}</p>` : ''}
            ${d.notes ? `<p>${esc(d.notes)}</p>` : ''}
            ${links ? `<p class="sb-links">${links}</p>` : ''}
            <form class="sb-link-form">
                <label class="sb-field"><span>Character sheet link <span class="muted">(D&amp;D Beyond, a PDF, anything with a web address)</span></span>
                    <span class="sb-link-row"><input name="link" type="url" placeholder="https://…" value="${esc(d.link || '')}" autocomplete="off">
                    <button class="primary">Save</button></span></label>
                <p class="sb-art-error result bad" hidden></p>
            </form>
            ${!d.notion_url ? '<p class="muted small">Not linked to Notion. Pick the campaign and use <em>Refresh PCs</em> to link the party.</p>' : ''}
        </div>`;
    }

    function headHtml(d, opts) {
        const art = d.image
            ? `<img class="sb-img" src="${esc(d.image.url)}" alt="${esc(d.name)}">`
            : `<span class="sb-type-icon" style="-webkit-mask-image:url('${esc(d.type_icon)}');mask-image:url('${esc(d.type_icon)}')" role="img" aria-label="${esc(d.type || 'Monster')}"></span>`;
        const views = viewsHtml(opts);
        return `${views}
        <header class="sb-head">
            <div class="sb-art ${d.image ? 'has-img' : ''}">
                ${art}
                <button class="sb-art-btn" data-act="art">${d.image ? 'Change' : '+ Add art'}</button>
            </div>
            <div class="sb-title">
                <h2>${esc(d.name || 'Unknown')}${d.legendary ? ' <span class="kind kind-monster">LEG</span>' : ''}</h2>
                <div class="sb-sub muted">${esc([d.type, d.source].filter(Boolean).join(' · '))}</div>
                <div class="sb-stats">
                    <span><b>CR</b> ${esc(d.cr ?? '–')}</span>
                    <span><b>AC</b> ${esc(d.ac ?? '–')}</span>
                    <span><b>HP</b> ${esc(d.hp ?? '–')}</span>
                    <span><b>Init</b> ${esc(sign(d.init))}</span>
                </div>
            </div>
        </header>`;
    }

    function bodyHtml(d) {
        if (d.markdown) return `<div class="sb-body">${mdToHtml(d.markdown)}</div>`;
        const why = d.origin === 'notion'
            ? 'Notion only has this monster’s numbers.'
            : 'No full stat block for this one: it was typed in by hand, or its book isn’t in the 5e Artisanal Database.';
        return `<div class="sb-body sb-empty muted">${why}${d.link ? ` <a href="${esc(d.link)}" target="_blank" rel="noopener">Open its page ↗</a>` : ''}</div>`;
    }

    function footHtml(d) {
        const bits = [];
        if (d.image) {
            const i = d.image;
            const credit = [i.credit, i.license_label].filter(Boolean).join(' · ');
            bits.push(`<span>Art: ${esc(credit || 'no credit given')}${i.source ? ` · <a href="${esc(i.source)}" target="_blank" rel="noopener">source</a>` : ''}`
                + `${i.where === 'mine' && !i.shared ? ' · <em>only on this computer</em>' : ''}${i.pending ? ' · <em>not shared yet</em>' : ''}</span>`);
            if (i.where === 'mine' || window.SB_DEV) bits.push('<button class="linkish" data-act="remove-art">Remove art</button>');
        }
        if (d.link) bits.push(`<a href="${esc(d.link)}" target="_blank" rel="noopener">${d.origin === 'notion' ? 'Open in Notion' : 'Full page'} ↗</a>`);
        return bits.length ? `<footer class="sb-foot muted">${bits.join('<span class="sep">·</span>')}</footer>` : '';
    }

    // ---- Add / change art --------------------------------------------------------------
    let licenses = null;
    async function getLicenses() {
        if (!licenses) {
            const s = await post('images_api.php', { action: 'summary' });
            licenses = s.licenses;
            window.SB_GEN = s.gen;
            window.SB_DEV = s.dev;
            window.SB_CAN_EDIT = s.can_edit;
        }
        return licenses;
    }

    /** Shrink big pictures in the browser before upload (the server makes the final 512 px WebP). */
    function shrink(file) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                const max = 1024;
                const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                const c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * scale);
                c.height = Math.round(img.naturalHeight * scale);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(img.src);
                resolve(c.toDataURL('image/webp', 0.92));
            };
            img.onerror = () => reject(new Error('That file isn’t an image this browser can read'));
            img.src = URL.createObjectURL(file);
        });
    }

    /** A starting description for AI art, from the stat block's name and "Medium Undead, Neutral Evil" line. */
    function defaultPrompt(d) {
        const first = (d.markdown || '').split('\n').map((l) => l.trim()).find((l) => /^[*_]/.test(l)) || '';
        const what = first.replace(/[*_]/g, '').replace(/\s+/g, ' ').trim() || d.type || 'creature';
        return `A fantasy tabletop roleplaying game monster: ${d.name}, ${what}. `
            + 'Show the whole creature, in a dramatic painterly fantasy illustration with a simple dark background. '
            + 'No text, no lettering, no border, no frame.';
    }

    async function artForm(root, d, onSaved) {
        const lic = await getLicenses();
        const box = root.querySelector('.sb-artform');
        const saved = (() => { try { return JSON.parse(localStorage.getItem('em.artMeta') || '{}'); } catch (_) { return {}; } })();
        box.hidden = false;
        const gen = window.SB_GEN || {};
        box.innerHTML = `
            ${gen.configured ? `
            <div class="sb-gen">
                <label class="sb-field"><span>Generate with AI <span class="muted">(describe what to draw)</span></span>
                    <textarea name="prompt" rows="3" maxlength="2000">${esc(defaultPrompt(d))}</textarea></label>
                <div class="sb-gen-row">
                    <select name="quality" aria-label="Quality">${Object.entries(gen.qualities).map(([k, l]) =>
                        `<option value="${k}" ${k === gen.quality ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
                    <button type="button" class="primary" data-act="gen">Generate</button>
                </div>
                <p class="sb-gen-status small muted" hidden></p>
            </div>
            <div class="sb-or muted small">or use a picture you have</div>` : ''}
            <div class="sb-drop" tabindex="0">
                <div class="sb-drop-preview" hidden><img alt=""></div>
                <div class="sb-drop-text"><strong>Drop an image here</strong>, paste one (⌘V / Ctrl+V), or
                    <label class="linkish">choose a file<input type="file" accept="image/*" hidden></label></div>
            </div>
            <label class="sb-field">…or paste its web address
                <input name="url" type="url" placeholder="https://…" autocomplete="off"></label>
            <label class="sb-field">Where is it from?
                <select name="license" required>
                    <option value="">Pick one…</option>
                    ${Object.entries(lic).map(([k, l]) => `<option value="${k}">${esc(l.label)}</option>`).join('')}
                </select></label>
            <label class="sb-field"><span>Credit <span class="muted">(who made it)</span></span>
                <input name="credit" maxlength="200" autocomplete="off"></label>
            <label class="sb-field"><span>Source page <span class="muted">(optional)</span></span>
                <input name="source" type="url" placeholder="https://…" autocomplete="off"></label>
            <p class="sb-share-note small muted"></p>
            <p class="sb-art-error result bad" hidden></p>
            <div class="sb-art-actions">
                <a href="${esc(d.find_art)}" target="_blank" rel="noopener">Find art ↗</a>
                <span class="spacer"></span>
                <button type="button" data-act="art-cancel">Cancel</button>
                <button type="button" class="primary" data-act="art-save">Save</button>
            </div>`;
        const f = (n) => box.querySelector(`[name="${n}"]`);
        f('license').value = saved.license && lic[saved.license] ? saved.license : '';
        f('credit').value = saved.license && lic[saved.license]?.credit ? (saved.credit || '') : '';
        let dataUrl = null;

        const note = () => {
            const l = lic[f('license').value];
            box.querySelector('.sb-share-note').textContent = !l ? ''
                : !l.share ? 'Kept on this computer only. It never goes into the shared collection.'
                    : window.SB_DEV ? 'Goes into the shared collection in the repo (images/monsters). Commit and push to share it with everyone.'
                        : 'Shared with everyone once you send it in with “Share my images” on the Monster images page.';
            f('credit').required = !!l?.credit;
        };
        f('license').addEventListener('change', note);
        note();

        const preview = (src) => {
            const p = box.querySelector('.sb-drop-preview');
            p.hidden = !src;
            p.querySelector('img').src = src || '';
        };
        const takeFile = async (file) => {
            if (!file || !file.type.startsWith('image/')) return;
            try {
                dataUrl = await shrink(file);
                f('url').value = '';
                preview(dataUrl);
            } catch (e) { showErr(e.message); }
        };
        const showErr = (msg) => {
            const el = box.querySelector('.sb-art-error');
            el.hidden = !msg;
            el.textContent = msg || '';
        };
        const drop = box.querySelector('.sb-drop');
        drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('over'));
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('over');
            const file = e.dataTransfer.files?.[0];
            if (file) return takeFile(file);
            const url = e.dataTransfer.getData('text/uri-list') || e.dataTransfer.getData('text/plain');
            if (/^https?:\/\//i.test(url || '')) { f('url').value = url.trim().split('\n')[0]; dataUrl = null; preview(f('url').value); }
        });
        box.querySelector('input[type=file]').addEventListener('change', (e) => takeFile(e.target.files[0]));
        f('url').addEventListener('input', () => { dataUrl = null; preview(/^https?:\/\//i.test(f('url').value) ? f('url').value : null); });
        box.addEventListener('paste', (e) => {
            const item = [...(e.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
            if (item) { e.preventDefault(); takeFile(item.getAsFile()); }
        });
        drop.focus();

        // ---- Generate with AI: shows a preview; Save keeps it, Generate again replaces it.
        let genTimer = null;
        box.querySelector('[data-act="gen"]')?.addEventListener('click', async (e) => {
            const btn = e.target;
            const status = box.querySelector('.sb-gen-status');
            const started = Date.now();
            btn.disabled = true;
            btn.textContent = 'Generating…';
            status.hidden = false;
            showErr('');
            const tick = () => { status.textContent = `Drawing… ${Math.round((Date.now() - started) / 1000)}s (usually 15–60 seconds)`; };
            tick();
            genTimer = setInterval(tick, 1000);
            try {
                const r = await post('images_api.php', { action: 'generate', prompt: f('prompt').value, quality: f('quality').value });
                dataUrl = r.data;
                f('url').value = '';
                preview(dataUrl);
                f('license').value = 'ai';
                f('credit').value = `AI-generated with OpenAI ${r.model}`;
                f('source').value = '';
                note();
                status.textContent = 'Like it? Click Save. Not quite? Change the description and generate again.';
                btn.textContent = 'Generate again';
            } catch (err) {
                status.hidden = true;
                showErr(err.message);
                btn.textContent = 'Generate';
            } finally {
                clearInterval(genTimer);
                btn.disabled = false;
            }
        });

        box.querySelector('[data-act="art-cancel"]').onclick = () => { clearInterval(genTimer); box.hidden = true; box.innerHTML = ''; };
        box.querySelector('[data-act="art-save"]').onclick = async (e) => {
            const btn = e.target;
            const url = f('url').value.trim();
            if (!dataUrl && !url) return showErr('Drop, paste or link an image first.');
            if (!f('license').value) return showErr('Say where the image is from.');
            if (f('credit').required && !f('credit').value.trim()) return showErr('This license needs a credit: who made the image?');
            showErr('');
            btn.disabled = true;
            btn.textContent = 'Saving…';
            try {
                const res = await post('images_api.php', {
                    action: 'save', key: d.key, name: d.name, data: dataUrl, url: dataUrl ? '' : url,
                    license: f('license').value, credit: f('credit').value, source: f('source').value || (dataUrl ? '' : url),
                });
                try { localStorage.setItem('em.artMeta', JSON.stringify({ license: f('license').value, credit: f('credit').value })); } catch (_) {}
                onSaved(res.image);
            } catch (err) {
                showErr(err.message);
                btn.disabled = false;
                btn.textContent = 'Save';
            }
        };
    }

    // ---- Render --------------------------------------------------------------------------
    function render(el, d, opts = {}) {
        el.classList.add('sb');
        if (d.origin === 'pc') {
            el.innerHTML = pcHtml(d, opts);
            el.onclick = (e) => {
                const b = e.target.closest('button');
                if (!b || !el.contains(b)) return;
                if (b.dataset.view) return opts.onView?.(b.dataset.view);
                if (b.classList.contains('sb-close')) return opts.onClose?.();
            };
            el.querySelector('.sb-link-form').onsubmit = async (e) => {
                e.preventDefault();
                const err = e.target.querySelector('.sb-art-error');
                try {
                    const link = e.target.link.value.trim();
                    const payload = await post('api.php', { action: 'update_combatant', id: d.id, fields: { link } });
                    d.link = link || null;
                    render(el, d, opts);
                    opts.onSaved?.(payload);
                } catch (ex) {
                    err.hidden = false;
                    err.textContent = ex.message;
                }
            };
            return;
        }
        el.innerHTML = headHtml(d, opts) + '<div class="sb-artform" hidden></div>' + bodyHtml(d) + footHtml(d);
        el.dataset.source = d.source || '';
        hideTip(true);
        markSpells(el.querySelector('.sb-body'));
        const rerender = (image) => {
            d.image = image;
            render(el, d, opts);
            opts.onChange?.(d);
        };
        el.onclick = async (e) => {
            const b = e.target.closest('button');
            if (!b || !el.contains(b)) return;
            if (b.dataset.view) return opts.onView?.(b.dataset.view);
            if (b.classList.contains('sb-close')) return opts.onClose?.();
            if (b.dataset.act === 'art') {
                await getLicenses();
                if (!window.SB_CAN_EDIT) return alert('Adding images needs PHP’s GD extension with WebP support on this computer.');
                return artForm(el, d, rerender);
            }
            if (b.dataset.act === 'remove-art') {
                const shared = d.image?.where === 'shared';
                if (!confirm(shared
                    ? `Remove the ${d.name} image from the shared collection (images/monsters)? You'll need to commit the change.`
                    : `Remove your ${d.name} image?`)) return;
                try {
                    const r = await post('images_api.php', { action: 'delete', key: d.key });
                    rerender(r.image);
                } catch (err) { alert(err.message); }
            }
        };
        el.querySelector('.sb-img')?.addEventListener('click', (e) => {
            const big = document.createElement('div');
            big.className = 'sb-zoom';
            big.innerHTML = `<img src="${esc(e.target.src)}" alt="">`;
            big.onclick = () => big.remove();
            document.body.append(big);
        });
    }

    // ---- Spells: mark them in the stat block, and show a hover box like D&D Beyond -------
    let spellNames = null;
    function getSpellNames() {
        spellNames ||= post('api.php', { action: 'spell_names' }).then((r) => new Set(r.names)).catch(() => new Set());
        return spellNames;
    }
    const norm = (t) => t.toLowerCase().replace(/[’'‘]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();

    // "Cantrips (at will):", "1st level (4 slots):", "At Will:", "2/day each:", "3e/Day Each:"
    const LIST_LABEL = /(?:cantrips?|at[ -]will|\d+(?:st|nd|rd|th)[ -]level|\d+\s*e?\s*\/\s*day(?:\s+each)?|\d+\s+slots?)[^:\n]{0,30}:\s*/ig;
    // "works like the levitate spell", "as the counterspell spell"
    const THE_SPELL = /\b(?:the|a|an)\s+((?:[a-z’'-]+\s+){0,4}[a-z’'-]+)\s+spells?\b/ig;

    function spellRanges(text, names) {
        const found = [];
        const add = (start, word) => { if (word && names.has(norm(word))) found.push([start, start + word.length, word]); };
        let listSeen = false;
        let lineStart = 0;
        for (const line of text.split('\n')) {
            LIST_LABEL.lastIndex = 0;
            const m = LIST_LABEL.exec(line);
            if (m) {
                listSeen = true;
                const from = m.index + m[0].length;
                const rest = line.slice(from);
                const item = /[^,;]+/g;
                let it;
                while ((it = item.exec(rest))) {
                    // "detect thoughts (range 120 ft, desc: )": anything from a bracket on is notes, not the name.
                    const core = it[0].split('(')[0].replace(/\*|\.$/g, ' ').replace(/^\s*(?:and|or)\s+/i, '').trim();
                    if (core) add(lineStart + from + it.index + it[0].indexOf(core), core);
                }
            } else if (listSeen) {
                // Level Up puts one spell per line under the label.
                const core = line.replace(/\(.*?\)|\*/g, ' ').trim().replace(/[.,;]$/, '');
                if (core && core.length < 40) add(lineStart + line.indexOf(core), core);
            }
            THE_SPELL.lastIndex = 0;
            let t;
            while ((t = THE_SPELL.exec(line))) {
                const words = t[1].split(/\s+/);
                for (let k = words.length; k > 0; k--) {
                    const cand = words.slice(-k).join(' ');
                    if (names.has(norm(cand))) {
                        add(lineStart + t.index + t[0].indexOf(cand, t[0].indexOf(t[1])), cand);
                        break;
                    }
                }
            }
            lineStart += line.length + 1;
        }
        return found.sort((a, b) => a[0] - b[0]).filter((r, i, arr) => !i || r[0] >= arr[i - 1][1]);
    }

    async function markSpells(body) {
        if (!body) return;
        const names = await getSpellNames();
        if (!names.size) return;
        const tag = (el, name) => {
            el.classList.add('spell');
            el.dataset.spell = name;
            el.tabIndex = 0;
        };
        // Italic names (2024 SRD *Fireball*, Black Flag _detect magic_).
        body.querySelectorAll('em, i').forEach((em) => {
            const t = em.textContent.trim();
            if (!em.querySelector('*') && names.has(norm(t))) tag(em, t);
        });
        // Plain-text spell lists and "the X spell" mentions.
        const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
            acceptNode: (n) => (n.parentElement.closest('.spell, a, h1, h2, h3, h4, th') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => {
            const text = node.nodeValue;
            if (!/spell|cantrip|at will|\/day|level/i.test(text) && !/spell/i.test(node.parentElement.closest('li, p')?.textContent || '')) return;
            const ranges = spellRanges(text, names);
            if (!ranges.length) return;
            const frag = document.createDocumentFragment();
            let pos = 0;
            ranges.forEach(([a, b, word]) => {
                frag.append(text.slice(pos, a));
                const span = document.createElement('span');
                span.textContent = text.slice(a, b);
                tag(span, word);
                frag.append(span);
                pos = b;
            });
            frag.append(text.slice(pos));
            node.replaceWith(frag);
        });
    }

    const tip = document.createElement('div');
    tip.className = 'spell-tip';
    tip.hidden = true;
    tip.setAttribute('role', 'tooltip');
    const spellCache = new Map();
    let tipFor = null;
    let showTimer = null;
    let hideTimer = null;

    function placeTip(anchor) {
        const r = anchor.getBoundingClientRect();
        const w = Math.min(400, window.innerWidth - 24);
        tip.style.width = w + 'px';
        let left = Math.min(Math.max(12, r.left), window.innerWidth - w - 12);
        tip.style.left = left + 'px';
        tip.style.top = '0px';
        tip.style.maxHeight = '';
        const h = tip.offsetHeight;
        const below = window.innerHeight - r.bottom - 12;
        const above = r.top - 12;
        if (h <= below || below >= above) {
            tip.style.top = (r.bottom + 6) + 'px';
            tip.style.maxHeight = Math.max(160, below - 6) + 'px';
        } else {
            tip.style.maxHeight = Math.max(160, above - 6) + 'px';
            tip.style.top = Math.max(12, r.top - 6 - Math.min(h, above - 6)) + 'px';
        }
    }

    function levelLine(sp) {
        const school = sp.school || 'Spell';
        const lvl = sp.level === 0 ? `${school} cantrip` : `Level ${sp.level} ${school}`;
        return lvl + (sp.ritual ? ' (ritual)' : '');
    }

    function tipHtml(sp) {
        const dur = sp.concentration && !/concentration/i.test(sp.duration) ? `Concentration, ${sp.duration}` : sp.duration;
        const comp = sp.components + (sp.material ? ` (${sp.material.replace(/\.$/, '')})` : '');
        const rows = [['Casting Time', sp.casting_time], ['Range', sp.range], ['Components', comp], ['Duration', dur]]
            .filter(([, v]) => v).map(([k, v]) => `<div><b>${k}</b> ${esc(v)}</div>`).join('');
        return `<div class="st-head"><strong>${esc(sp.name)}</strong><span class="muted">${esc(levelLine(sp))}</span></div>
            ${rows ? `<div class="st-props">${rows}</div>` : ''}
            <div class="st-desc">${mdToHtml(sp.desc)}${sp.higher_level ? `<p><b><i>${sp.level === 0 ? 'Cantrip Upgrade.' : 'Using a Higher-Level Spell Slot.'}</i></b> ${esc(sp.higher_level)}</p>` : ''}</div>
            <div class="st-foot muted">${esc(sp.source)}${sp.classes ? ' · ' + esc(sp.classes) : ''}</div>`;
    }

    async function showTip(anchor) {
        clearTimeout(hideTimer);
        if (tipFor === anchor && !tip.hidden) return;
        tipFor = anchor;
        if (!tip.isConnected) document.body.append(tip);
        const name = anchor.dataset.spell;
        const source = anchor.closest('.sb')?.dataset.source || '';
        const key = norm(name) + '|' + source;
        tip.innerHTML = '<div class="muted">Loading…</div>';
        tip.hidden = false;
        placeTip(anchor);
        try {
            if (!spellCache.has(key)) spellCache.set(key, post('api.php', { action: 'spell', name, source }));
            const sp = await spellCache.get(key);
            if (tipFor !== anchor) return;
            tip.innerHTML = tipHtml(sp);
        } catch (e) {
            spellCache.delete(key);
            if (tipFor === anchor) tip.innerHTML = `<div class="bad">${esc(e.message)}</div>`;
        }
        if (tipFor === anchor) placeTip(anchor);
    }
    function hideTip(now = false) {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        const go = () => { tip.hidden = true; tipFor = null; };
        now ? go() : (hideTimer = setTimeout(go, 220));
    }

    document.addEventListener('mouseover', (e) => {
        const s = e.target.closest?.('.spell');
        if (s) {
            clearTimeout(hideTimer);
            clearTimeout(showTimer);
            showTimer = setTimeout(() => showTip(s), 120);
        } else if (e.target.closest?.('.spell-tip')) {
            clearTimeout(hideTimer); // moving into the box keeps it open (so long spells can scroll)
        }
    });
    document.addEventListener('mouseout', (e) => {
        if (e.target.closest?.('.spell, .spell-tip') && !e.relatedTarget?.closest?.('.spell-tip, .spell')) hideTip();
    });
    document.addEventListener('focusin', (e) => { if (e.target.classList?.contains('spell')) showTip(e.target); });
    document.addEventListener('focusout', (e) => { if (e.target.classList?.contains('spell')) hideTip(); });
    document.addEventListener('click', (e) => {
        const s = e.target.closest?.('.spell');
        if (s) { e.preventDefault(); tipFor === s && !tip.hidden ? hideTip(true) : showTip(s); } // taps on touch screens
        else if (!e.target.closest?.('.spell-tip')) hideTip(true);
    });
    // Esc closes the spell box first (before the stat block).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !tip.hidden) { e.stopImmediatePropagation(); hideTip(true); }
    }, true);
    window.addEventListener('scroll', () => tip.hidden || hideTip(true), true);

    async function load(target) {
        const res = await fetch('api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'statblock', ...target }),
        });
        const json = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
        if (!res.ok || json.error) throw new Error(json.error || 'Could not load the stat block');
        // Know early whether this copy can edit/remove shared art.
        getLicenses().catch(() => {});
        return json;
    }

    function loading(el, msg = 'Loading stat block…') {
        el.classList.add('sb');
        el.innerHTML = `<div class="sb-loading muted">${esc(msg)}</div>`;
    }

    window.StatBlock = { load, render, loading, VIEWS };
})();
