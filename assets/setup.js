/* Encounter Manager setup wizard. Talks to setup_api.php; the saved token never comes back to the browser. */
(() => {
    const $ = (s) => document.querySelector(s);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const NUMERIC = ['number', 'formula', 'rollup'];

    const st = {
        status: null,
        connected: false,
        sources: [],          // databases the integration can see
        monster: '',          // data_source id or ''
        campaigns: new Map(), // data_source id -> campaign name (ticked ones only)
        columns: {},          // data_source id -> { field: column | '' | '__create__' }
    };

    async function api(action, body = {}) {
        const res = await fetch('setup_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...body }),
        });
        const data = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    function show(el, msg, good) {
        el.hidden = !msg;
        el.className = 'result ' + (good ? 'good' : 'bad');
        el.innerHTML = msg || '';
    }
    const unlock = (id, on = true) => $(id).classList.toggle('locked', !on);
    const done = (id, on = true) => $(id).classList.toggle('done', on);
    const token = () => $('#token').value.trim();
    const byId = (id) => st.sources.find((s) => s.data_source === id);

    // ---- 1. requirements ---------------------------------------------------
    function renderRequirements() {
        const reqs = st.status.requirements;
        $('#req-list').innerHTML = reqs.map((r) =>
            `<li class="${r.ok ? 'ok' : 'no'}">${esc(r.label)}${r.ok ? '' : ` <span class="muted small">(${esc(r.detail)})</span>`}</li>`).join('');
        const ok = reqs.every((r) => r.ok);
        $('#req-bad').hidden = ok;
        done('#step-req', ok);
        return ok;
    }

    // ---- 2. token ----------------------------------------------------------
    async function connect() {
        const btn = $('#btn-test');
        btn.disabled = true;
        btn.textContent = 'Connecting…';
        try {
            const me = await api('test_token', { token: token() });
            st.connected = true;
            show($('#token-result'), `✓ Connected as <strong>${esc(me.integration)}</strong>${me.workspace ? ` to the <strong>${esc(me.workspace)}</strong> workspace` : ''}.`, true);
            done('#step-token');
            unlock('#step-dbs');
            await findDatabases();
        } catch (e) {
            st.connected = false;
            show($('#token-result'), esc(e.message), false);
            done('#step-token', false);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Connect';
        }
    }

    // ---- 3. databases ------------------------------------------------------
    async function findDatabases() {
        const btn = $('#btn-find');
        btn.disabled = true;
        btn.textContent = 'Looking…';
        try {
            st.sources = (await api('list_sources', { token: token() })).sources;
            if (!st.sources.length) {
                show($('#dbs-result'), 'The integration can’t see any databases yet. Share them as described above, then click <em>Find my databases</em> again.', false);
                $('#db-pick').hidden = true;
                return;
            }
            show($('#dbs-result'), `Found ${st.sources.length} database${st.sources.length === 1 ? '' : 's'}. Shared another one? Click <em>Find my databases</em> again.`, true);
            preselect();
            renderDatabases();
        } catch (e) {
            show($('#dbs-result'), esc(e.message), false);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Find my databases';
        }
    }

    /** First time: guess from titles. Later: keep what's saved (or what was picked before re-finding). */
    function preselect() {
        const has = (id) => !!byId(id);
        const saved = st.status;
        if (!st.monster || !has(st.monster)) {
            st.monster = saved.monsters?.data_source && has(saved.monsters.data_source) ? saved.monsters.data_source
                : (st.sources.find((s) => /monster|bestiary|creature/i.test(s.title))?.data_source || '');
        }
        if (!st.campaigns.size) {
            saved.campaigns.forEach((c) => has(c.data_source) && st.campaigns.set(c.data_source, c.name));
            if (!saved.campaigns.length) {
                st.sources.filter((s) => /character|party|player|\bpcs?\b|heroes/i.test(s.title))
                    .forEach((s) => st.campaigns.set(s.data_source, s.parent_title || s.title));
            }
        }
        [...st.campaigns.keys()].forEach((id) => has(id) || st.campaigns.delete(id));
    }

    const where = (s) => s.parent_title ? `${s.title} · in “${s.parent_title}”` : s.title;

    function renderDatabases() {
        $('#db-pick').hidden = false;
        $('#monster-db').innerHTML = '<option value="">— none (type monsters in by hand) —</option>'
            + st.sources.map((s) => `<option value="${esc(s.data_source)}">${esc(where(s))}</option>`).join('');
        $('#monster-db').value = st.monster;

        $('#campaign-list').innerHTML = st.sources.map((s) => {
            const on = st.campaigns.has(s.data_source);
            const name = on ? st.campaigns.get(s.data_source) : (s.parent_title || s.title);
            return `<li data-id="${esc(s.data_source)}" class="${on ? 'on' : ''}">
                <input type="checkbox" ${on ? 'checked' : ''} aria-label="Use as a campaign">
                <span class="db-title">${esc(s.title)}<small>${s.parent_title ? 'in “' + esc(s.parent_title) + '” · ' : ''}${Object.keys(s.properties).length} columns</small></span>
                <input type="text" value="${esc(name)}" placeholder="Campaign name" ${on ? '' : 'disabled'}>
            </li>`;
        }).join('');
        done('#step-dbs', !!st.monster || st.campaigns.size > 0);
        renderColumns();
    }

    // ---- 4. columns --------------------------------------------------------
    function guess(def, props, saved) {
        const ok = (name) => props[name] && def.types.includes(props[name]);
        if (saved && saved[def.key] !== undefined) {
            const c = saved[def.key];
            if (c === '' || ok(c)) return c;
        }
        const names = Object.keys(props);
        for (const m of def.match || []) {
            const hit = names.find((n) => n.toLowerCase() === m.toLowerCase() && ok(n));
            if (hit) return hit;
        }
        if (def.types.includes('title')) return names.find((n) => props[n] === 'title') || '';
        return def.create ? '__create__' : '';
    }

    function cardHtml(src, kind, label) {
        const fields = st.status.fields[kind];
        const savedCfg = kind === 'monster'
            ? (st.status.monsters?.data_source === src.data_source ? st.status.monsters.columns : null)
            : st.status.campaigns.find((c) => c.data_source === src.data_source)?.columns;
        const cur = st.columns[src.data_source] ||= {};
        const rows = Object.entries(fields).map(([key, def]) => {
            def = { ...def, key };
            if (!(key in cur)) cur[key] = guess(def, src.properties, savedCfg);
            const compatible = Object.entries(src.properties).filter(([, t]) => def.types.includes(t));
            const canCreate = def.create && !(def.create in src.properties);
            const opts = [];
            if (!def.required) opts.push(`<option value="">— not used —</option>`);
            compatible.forEach(([n, t]) => opts.push(`<option value="${esc(n)}">${esc(n)} (${esc(t.replace('_', ' '))})</option>`));
            if (canCreate) opts.push(`<option value="__create__">＋ Add a “${esc(def.create)}” Number column</option>`);
            if (!opts.length) opts.push('<option value="">— no suitable column —</option>');
            let note = '';
            if (key === 'cr' && cur[key] && !NUMERIC.includes(src.properties[cur[key]])) {
                note = 'Text CRs work, but CR searches are slower. A Number column is best.';
            }
            if (key === 'level' && !cur[key]) note = 'Without levels the difficulty meter can’t rate encounters.';
            if (key === 'cr' && !cur[key]) note = 'Without CR the difficulty meter can’t rate encounters.';
            return `<label for="c-${esc(src.data_source)}-${key}">${esc(def.label)}${def.required ? ' *' : ''}</label>
                <select id="c-${esc(src.data_source)}-${key}" data-src="${esc(src.data_source)}" data-field="${key}" data-kind="${kind}">${opts.join('')}</select>
                ${note ? `<div class="note">${note}</div>` : ''}`;
        }).join('');
        return `<div class="col-card"><h3>${esc(label)} <span class="muted small">${esc(where(src))}</span></h3>
            <div class="col-grid">${rows}</div></div>`;
    }

    function renderColumns() {
        const cards = [];
        const m = byId(st.monster);
        if (m) cards.push(cardHtml(m, 'monster', 'Monsters'));
        st.campaigns.forEach((name, id) => {
            const s = byId(id);
            if (s) cards.push(cardHtml(s, 'character', name || 'Campaign'));
        });
        $('#col-cards').innerHTML = cards.join('') || '<p class="muted">Pick a monster database or at least one campaign above.</p>';
        $('#col-cards').querySelectorAll('select').forEach((sel) => { sel.value = st.columns[sel.dataset.src][sel.dataset.field]; });
        const any = cards.length > 0;
        unlock('#step-cols', any);
        done('#step-cols', any);
        unlock('#step-save', any);
    }

    // ---- 5. save -----------------------------------------------------------
    async function save() {
        const btn = $('#btn-save');
        const names = [...st.campaigns.values()];
        if (names.some((n) => !n.trim())) return show($('#save-result'), 'Give every ticked campaign a name.', false);
        btn.disabled = true;
        btn.textContent = 'Saving…';
        try {
            const m = byId(st.monster);
            const res = await api('save', {
                token: token(),
                monsters: m ? { data_source: m.data_source, database: m.database, name: m.title, columns: st.columns[m.data_source] } : null,
                campaigns: [...st.campaigns].map(([id, name]) => {
                    const s = byId(id);
                    return { name: name.trim(), data_source: id, database: s.database, columns: st.columns[id] };
                }),
            });
            const bits = [`✓ Saved: ${res.campaigns} campaign${res.campaigns === 1 ? '' : 's'}${res.monsters ? ' and your monster database' : ''}.`];
            if (res.created.length) bits.push(`Added columns: ${res.created.map(esc).join(', ')}. Fill them in Notion, then use <em>Refresh PCs</em> in the app.`);
            show($('#save-result'), bits.join('<br>'), true);
            $('#save-done').hidden = false;
            $('#token').value = '';
            st.status = await api('status');
            st.columns = {}; // re-read saved mappings (created columns now exist)
            $('#token-saved').hidden = !st.status.has_token;
            done('#step-save');
            await findDatabases();
        } catch (e) {
            show($('#save-result'), esc(e.message), false);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save settings';
        }
    }

    // ---- events ------------------------------------------------------------
    $('#btn-test').addEventListener('click', connect);
    $('#token').addEventListener('keydown', (e) => e.key === 'Enter' && connect());
    $('#btn-find').addEventListener('click', findDatabases);
    $('#btn-save').addEventListener('click', save);
    $('#btn-skip').addEventListener('click', async () => {
        if (!confirm('Use Encounter Manager without Notion?\n\nYou can type PCs and monsters in by hand, and come back to Setup any time.')) return;
        await api('skip_notion');
        location.href = './';
    });
    $('#monster-db').addEventListener('change', (e) => {
        st.monster = e.target.value;
        done('#step-dbs', !!st.monster || st.campaigns.size > 0);
        renderColumns();
    });
    $('#campaign-list').addEventListener('change', (e) => {
        const li = e.target.closest('li[data-id]');
        if (!li) return;
        const id = li.dataset.id;
        const text = li.querySelector('input[type=text]');
        if (e.target.type === 'checkbox') {
            e.target.checked ? st.campaigns.set(id, text.value) : st.campaigns.delete(id);
            li.classList.toggle('on', e.target.checked);
            text.disabled = !e.target.checked;
            if (e.target.checked) text.select();
            done('#step-dbs', !!st.monster || st.campaigns.size > 0);
            renderColumns();
        } else if (st.campaigns.has(id)) {
            st.campaigns.set(id, text.value);
            renderColumns();
        }
    });
    $('#col-cards').addEventListener('change', (e) => {
        const sel = e.target.closest('select[data-src]');
        if (!sel) return;
        st.columns[sel.dataset.src][sel.dataset.field] = sel.value;
        renderColumns(); // refresh the notes
    });
    document.addEventListener('focusin', (e) => {
        if (e.target.matches('input[type=text], input[type=password]')) e.target.select();
    });

    // ---- start -------------------------------------------------------------
    (async () => {
        try {
            st.status = await api('status');
        } catch (e) {
            show($('#token-result'), esc(e.message), false);
            return;
        }
        $('#back-link').hidden = !st.status.configured;
        $('#token-saved').hidden = !st.status.has_token;
        const ok = renderRequirements();
        unlock('#step-token', ok);
        if (ok && st.status.has_token) connect(); // already set up: show what's saved
    })();
})();
