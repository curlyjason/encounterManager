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

    function headHtml(d, opts) {
        const art = d.image
            ? `<img class="sb-img" src="${esc(d.image.url)}" alt="${esc(d.name)}">`
            : `<span class="sb-type-icon" style="-webkit-mask-image:url('${esc(d.type_icon)}');mask-image:url('${esc(d.type_icon)}')" role="img" aria-label="${esc(d.type || 'Monster')}"></span>`;
        const views = opts.view === 'tab' ? '' : `<div class="sb-bar"><span class="sb-bar-label muted">Show as</span><div class="sb-views" role="group" aria-label="Show stat blocks as">
            ${VIEWS.map(([v, label, icon]) => `<button class="sb-view ${v === opts.view ? 'on' : ''}" data-view="${v}" title="${label}" aria-label="${label}" aria-pressed="${v === opts.view}">${icon}</button>`).join('')}
            </div><button class="sb-close" title="Close (Esc)" aria-label="Close">✕</button>
        </div>`;
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

    async function artForm(root, d, onSaved) {
        const lic = await getLicenses();
        const box = root.querySelector('.sb-artform');
        const saved = (() => { try { return JSON.parse(localStorage.getItem('em.artMeta') || '{}'); } catch (_) { return {}; } })();
        box.hidden = false;
        box.innerHTML = `
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

        box.querySelector('[data-act="art-cancel"]').onclick = () => { box.hidden = true; box.innerHTML = ''; };
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
        el.innerHTML = headHtml(d, opts) + '<div class="sb-artform" hidden></div>' + bodyHtml(d) + footHtml(d);
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
