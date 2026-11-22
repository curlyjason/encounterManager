(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const state = { conditions: [], encounterId: null, data: null, notion: false, campaigns: [], selected: new Set() };

    // ---- Encounter difficulty (2024 DMG) -------------------------------------
    // XP budget per character by level: [Low, Moderate, High].
    const XP_BUDGET = {
        1: [50, 75, 100], 2: [100, 150, 200], 3: [150, 225, 400], 4: [250, 375, 500],
        5: [500, 750, 1100], 6: [600, 1000, 1400], 7: [750, 1300, 1700], 8: [1000, 1700, 2100],
        9: [1300, 2000, 2600], 10: [1600, 2300, 3100], 11: [1900, 2900, 4100], 12: [2200, 3700, 4700],
        13: [2600, 4200, 5400], 14: [2900, 4900, 6200], 15: [3300, 5400, 7800], 16: [3800, 6100, 9800],
        17: [4500, 7200, 11700], 18: [5000, 8700, 14200], 19: [5500, 10700, 17200], 20: [6400, 13200, 22000],
    };
    // Monster XP by CR.
    const CR_XP = {
        0: 10, 0.125: 25, 0.25: 50, 0.5: 100, 1: 200, 2: 450, 3: 700, 4: 1100, 5: 1800, 6: 2300,
        7: 2900, 8: 3900, 9: 5000, 10: 5900, 11: 7200, 12: 8400, 13: 10000, 14: 11500, 15: 13000,
        16: 15000, 17: 18000, 18: 20000, 19: 22000, 20: 25000, 21: 33000, 22: 41000, 23: 50000,
        24: 62000, 25: 75000, 26: 90000, 27: 105000, 28: 120000, 29: 135000, 30: 155000,
    };
    const crXp = (cr) => CR_XP[cr] ?? CR_XP[Math.round(cr)] ?? 0;
    const fmtCr = (cr) => (cr === 0.125 ? '1/8' : cr === 0.25 ? '1/4' : cr === 0.5 ? '1/2' : String(cr));
    const n0 = (n) => Math.round(n).toLocaleString();

    function renderDifficulty(combatants) {
        const scale = state.difficultyScale;
        $('#diff-scale').value = scale;

        const pcs = combatants.filter((c) => c.kind === 'pc');
        const leveled = pcs.filter((c) => c.level);
        const foes = combatants.filter((c) => c.kind === 'monster');
        const noCr = foes.filter((c) => c.cr === null);

        // Mixed-level parties: add up each character's own budget.
        const budget = [0, 1, 2].map((i) => leveled.reduce((s, c) => s + XP_BUDGET[c.level][i], 0) * scale);
        const total = foes.reduce((s, c) => s + (c.cr === null ? 0 : crXp(c.cr)), 0);
        const remaining = foes.filter((c) => !c.dead).reduce((s, c) => s + (c.cr === null ? 0 : crXp(c.cr)), 0);

        let rating = '–', cls = '';
        if (leveled.length && foes.length) {
            const [low, mod, high] = budget;
            if (total >= high * 1.5) { rating = 'Extreme'; cls = 'extreme'; }
            else if (total >= high) { rating = 'High'; cls = 'high'; }
            else if (total >= mod) { rating = 'Moderate'; cls = 'moderate'; }
            else if (total >= low) { rating = 'Low'; cls = 'low'; }
            else { rating = 'Trivial'; cls = 'trivial'; }
        }
        const badge = $('#diff-rating');
        badge.textContent = rating;
        badge.className = 'diff-rating ' + cls;

        // Bar runs from 0 to 1.5× the High budget; markers sit at each threshold.
        const top = budget[2] * 1.5 || 1;
        $('#diff-fill').style.width = `${Math.min(100, (total / top) * 100)}%`;
        $('#diff-fill').className = 'diff-fill ' + cls;
        ['low', 'moderate', 'high'].forEach((k, i) => {
            const m = document.querySelector(`.diff-mark[data-mark="${k}"]`);
            m.style.left = `${(budget[i] / top) * 100}%`;
            m.hidden = !leveled.length;
            m.title = `${k[0].toUpperCase() + k.slice(1)}: ${n0(budget[i])} XP`;
        });

        $('#diff-text').textContent = !foes.length
            ? 'Add monsters to rate this encounter'
            : !leveled.length
                ? `${n0(total)} XP of monsters · set PC levels to rate it`
                : `${n0(total)} XP` + (remaining !== total ? ` (${n0(remaining)} still alive)` : '') +
                  ` · Low ${n0(budget[0])} · Mod ${n0(budget[1])} · High ${n0(budget[2])}`;

        const warn = [];
        const noLevel = pcs.filter((c) => !c.level).map((c) => c.name);
        if (noLevel.length) warn.push(`No level for: ${noLevel.join(', ')}`);
        if (noCr.length) warn.push(`No CR for: ${noCr.map((c) => c.name).join(', ')}`);
        $('#diff-warn').hidden = !warn.length;
        $('#diff-warn').textContent = warn.join(' · ');
    }

    $('#diff-scale').addEventListener('change', async (e) => {
        const { difficulty_scale } = await api('set_difficulty_scale', { value: e.target.value });
        state.difficultyScale = difficulty_scale;
        renderDifficulty(state.data.combatants);
    });

    /** "Goblin 3" → "Goblin" so numbered copies group together. */
    const baseName = (n) => n.replace(/\s+\d+$/, '');

    // ---- API ---------------------------------------------------------------
    async function api(action, body = {}) {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...body }),
        });
        const json = await res.json().catch(() => ({ error: 'Bad response from server' }));
        if (!res.ok || json.error) {
            toast(json.error || 'Request failed');
            throw new Error(json.error);
        }
        return json;
    }

    function toast(msg, kind = 'error') {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast ' + kind;
        t.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (t.hidden = true), kind === 'error' ? 4000 : 3000);
    }

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    // ---- Encounter list ----------------------------------------------------
    async function loadList() {
        const { encounters } = await api('list_encounters');
        const ul = $('#encounter-list');
        ul.innerHTML = encounters.map((e) => `
            <li class="${e.id == state.encounterId ? 'active' : ''}" data-id="${e.id}" data-name="${esc(e.name)}">
                <button class="enc-del" title="Delete this encounter" aria-label="Delete ${esc(e.name)}">✕</button>
                <span class="enc-title">${esc(e.name)}</span>
                <span class="enc-meta">${e.count} combatant${e.count == 1 ? '' : 's'}${e.round > 0 ? ` · R${e.round}` : ''}</span>
            </li>`).join('') || '<li class="muted">No encounters yet</li>';
    }

    async function openEncounter(id) {
        state.encounterId = id;
        state.selected.clear();
        try { localStorage.setItem('em.last', id); } catch (_) {}
        render(await api('get_encounter', { id }));
        loadList();
    }

    // ---- Rendering ---------------------------------------------------------
    function render(data) {
        state.data = data;
        const { encounter: enc, combatants } = data;
        $('#empty').hidden = true;
        $('#encounter').hidden = false;
        $('#enc-name').textContent = enc.name;
        $('#enc-round').textContent = enc.round > 0 ? enc.round : '–';
        const started = enc.round > 0;
        $('#btn-next').textContent = started ? 'Next ▶' : 'Start ▶';
        $('#btn-next').disabled = enc.paused;
        $('#btn-prev').disabled = !started || enc.paused;
        $('#btn-pause').disabled = !started;
        $('#btn-pause').textContent = enc.paused ? '▶ Resume' : '❚❚ Pause';
        $('#btn-pause').classList.toggle('primary', enc.paused);
        $('#btn-stop').disabled = !started;
        $('#paused-badge').hidden = !enc.paused;
        $('#encounter').classList.toggle('is-paused', enc.paused);
        $('#campaign').value = enc.campaign || '';
        $('#btn-party').disabled = !(state.notion && enc.campaign);
        $('#btn-party').title = !state.notion ? 'Connect Notion first'
            : !enc.campaign ? 'Pick a campaign first' : 'Add every PC from this campaign';
        $('#btn-refresh').disabled = !(state.notion && enc.campaign && combatants.some((c) => c.kind === 'pc'));

        const fighting = combatants.filter((c) => c.in_combat && !c.dead);
        const waiting = combatants.filter((c) => !c.in_combat && !c.dead);
        const fallen = combatants.filter((c) => c.dead);
        // Who just died (only counts when we're still looking at the same encounter).
        const deadNow = new Set(fallen.map((c) => c.id));
        const newlyDead = state.deadIds && state.deadEnc === enc.id
            ? fallen.filter((c) => !state.deadIds.has(c.id)) : [];
        state.deadIds = deadNow;
        state.deadEnc = enc.id;

        renderWaiting(waiting);
        renderFallen(fallen, new Set(newlyDead.map((c) => c.id)));
        renderDifficulty(combatants);
        if (newlyDead.length) {
            const names = newlyDead.map((c) => c.name);
            toast(`☠ ${names.join(', ')} ${names.length === 1 ? 'is' : 'are'} dead`, 'error');
        }

        // Drop selections for anyone no longer in the fight.
        const ids = new Set(fighting.map((c) => c.id));
        [...state.selected].forEach((id) => ids.has(id) || state.selected.delete(id));
        renderSelection(fighting.length);

        const tbody = $('#rows');
        // Keep the old rows of anyone who just died so they can flash red and fade out.
        const dying = newlyDead.map((c) => {
            const tr = tbody.querySelector(`tr[data-id="${c.id}"]:not(.dying)`);
            return tr && { tr, index: [...tbody.children].indexOf(tr) };
        }).filter(Boolean);

        if (!fighting.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="muted center">${waiting.length
                ? 'Nobody in initiative yet. Bring some in from the waiting list above.'
                : 'Add combatants above.'}</td></tr>`;
            fadeOutDying(tbody, dying);
            return;
        }
        tbody.innerHTML = fighting.map((c) => rowHtml(c, enc.active_id)).join('');
        fadeOutDying(tbody, dying);

        const active = tbody.querySelector('tr.active');
        if (active) active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /** Put just-killed rows back where they were, flash them red, fade them out, then drop them. */
    function fadeOutDying(tbody, dying) {
        dying.sort((a, b) => a.index - b.index).forEach(({ tr, index }) => {
            tr.classList.remove('active', 'just-joined');
            tr.classList.add('dying');
            tr.inert = true;
            tbody.insertBefore(tr, tbody.children[index] || null);
            const done = () => tr.remove();
            tr.addEventListener('animationend', (e) => e.target === tr.cells[0] && done());
            setTimeout(done, 2500);
        });
    }

    /**
     * Wound status from HP (only when max HP is known):
     *  unconscious (PC at 0) · deaths-door (≤ 10% left) · bloodied (≤ 50% left) · healthy.
     * Monsters at 0 are dead and never reach a table row.
     */
    function woundStatus(c) {
        if (c.hp_max <= 0) return null;
        if (c.hp === 0) return c.kind === 'pc' ? 'unconscious' : 'dead';
        if (c.hp <= c.hp_max * 0.1) return 'deaths-door';
        if (c.hp <= c.hp_max * 0.5) return 'bloodied';
        return null;
    }
    const STATUS_LABEL = { bloodied: 'Bloodied', 'deaths-door': "Death's Door", unconscious: 'Unconscious', dead: 'Dead' };

    function rowHtml(c, activeId) {
        const pct = c.hp_max > 0 ? Math.round((c.hp / c.hp_max) * 100) : 0;
        const status = woundStatus(c);
        const hpClass = status || '';
        const badge = status
            ? `<span class="status status-${status}">${STATUS_LABEL[status]}</span>` +
              (status === 'unconscious' ? ' <button class="kill" title="Mark this PC dead (failed death saves, etc.)">☠ Dead</button>' : '')
            : '';
        const chips = c.conditions.map((k) =>
            `<span class="chip" data-cond="${esc(k)}" title="Click to remove">${esc(k)} ×</span>`).join('');
        const options = state.conditions.filter((k) => !c.conditions.includes(k))
            .map((k) => `<option>${esc(k)}</option>`).join('');

        return `
        <tr data-id="${c.id}" class="${c.id === activeId ? 'active' : ''} ${status ? 'row-' + status : ''} ${state.joined?.has(c.id) ? 'just-joined' : ''}">
            <td class="sel"><input type="checkbox" class="row-sel" ${state.selected.has(c.id) ? 'checked' : ''} aria-label="Select ${esc(c.name)}"></td>
            <td><input class="num" data-field="initiative" type="number" value="${c.initiative ?? ''}" placeholder="–"
                       title="Modifier ${c.init_mod >= 0 ? '+' : ''}${c.init_mod}"></td>
            <td>
                <input class="name" data-field="name" value="${esc(c.name)}">
                <span class="kind kind-${c.kind}">${c.kind.toUpperCase()}</span>
                ${c.kind === 'pc'
                    ? `<label class="mini" title="Character level">Lv <input class="tiny" data-field="level" type="number" min="1" max="20" value="${c.level ?? ''}" placeholder="?"></label>`
                    : `<label class="mini" title="Challenge rating${c.cr !== null ? ` (${n0(crXp(c.cr))} XP)` : ''}">CR <input class="tiny" data-field="cr" value="${c.cr === null ? '' : fmtCr(c.cr)}" placeholder="?"></label>`}
                <input class="notes" data-field="notes" value="${esc(c.notes)}" placeholder="notes">
            </td>
            <td><input class="num" data-field="ac" type="number" value="${c.ac ?? ''}"></td>
            <td class="hp-cell">
                <div class="hp-line">
                    <input class="num" data-field="hp" type="number" min="0" value="${c.hp}"> /
                    <input class="num" data-field="hp_max" type="number" min="0" value="${c.hp_max}">
                    <label class="temp" title="Temporary HP">+<input class="num" data-field="temp_hp" type="number" min="0" value="${c.temp_hp}"></label>
                </div>
                <div class="hp-bar ${hpClass}"><span style="width:${pct}%"></span></div>
                ${badge ? `<div class="status-line">${badge}</div>` : ''}
            </td>
            <td class="dmg">
                <input class="num amount" type="number" min="0" placeholder="0">
                <button data-hp="-1" class="danger" title="Damage (Enter)">Dmg</button>
                <button data-hp="1" title="Heal (Shift+Enter)">Heal</button>
            </td>
            <td class="conds">
                ${chips}
                ${options ? `<select class="add-cond"><option value="">+ condition</option>${options}</select>` : ''}
            </td>
            <td><button class="remove" title="Remove">✕</button></td>
        </tr>`;
    }

    // ---- Waves: waiting list + selection ------------------------------------
    function renderWaiting(waiting) {
        const box = $('#waiting');
        box.hidden = waiting.length === 0;
        $('#waiting-count').textContent = `(${waiting.length})`;
        const groups = new Map();
        waiting.forEach((c) => {
            const k = baseName(c.name);
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(c);
        });
        // Remember quantities the user picked so a re-render doesn't reset them.
        const picked = {};
        document.querySelectorAll('#waiting-groups select.qty').forEach((s) => (picked[s.dataset.group] = s.value));

        $('#waiting-groups').innerHTML = [...groups].map(([name, members]) => {
            const n = members.length;
            const sel = Math.min(Number(picked[name] || n), n);
            const opts = Array.from({ length: n }, (_, i) =>
                `<option value="${i + 1}" ${i + 1 === sel ? 'selected' : ''}>${i + 1}</option>`).join('');
            const m = members[0];
            return `
            <li data-group="${esc(name)}">
                <span class="w-name">${esc(name)} <span class="muted">× ${n} waiting</span></span>
                <span class="w-stats muted">AC ${m.ac ?? '–'} · HP ${m.hp_max} · Init ${m.init_mod >= 0 ? '+' : ''}${m.init_mod}</span>
                <span class="w-actions">
                    <select class="qty" data-group="${esc(name)}" aria-label="How many ${esc(name)}">${opts}</select>
                    <button class="primary bring" data-group="${esc(name)}">Bring in</button>
                    <button class="w-remove" data-group="${esc(name)}" title="Remove all waiting ${esc(name)}">✕</button>
                </span>
            </li>`;
        }).join('');
    }

    function renderSelection(total) {
        const n = state.selected.size;
        $('#selection-bar').hidden = n === 0;
        $('#selection-count').textContent = `${n} selected`;
        const all = $('#sel-all');
        all.checked = n > 0 && n === total;
        all.indeterminate = n > 0 && n < total;
    }

    function renderFallen(fallen, justFell = new Set()) {
        $('#fallen').hidden = fallen.length === 0;
        $('#fallen-count').textContent = `(${fallen.length})`;
        $('#fallen-list').innerHTML = fallen.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
            .map((c) => `
            <li data-id="${c.id}" class="${justFell.has(c.id) ? 'just-fell' : ''}">
                <span class="f-name">☠ ${esc(c.name)} <span class="kind kind-${c.kind}">${c.kind.toUpperCase()}</span></span>
                <span class="muted f-meta">${c.hp} / ${c.hp_max} HP</span>
                <span class="f-actions">
                    <button class="revive" title="Bring back into initiative with at least 1 HP">Revive</button>
                    <button class="f-remove" title="Remove from the encounter">✕</button>
                </span>
            </li>`).join('');
    }

    $('#fallen-list').addEventListener('click', async (e) => {
        const li = e.target.closest('li[data-id]');
        if (!li) return;
        const id = Number(li.dataset.id);
        const c = state.data.combatants.find((x) => x.id === id);
        if (e.target.classList.contains('revive')) {
            render(await api('revive', { id }));
            toast(`${c.name} is back in the fight`, 'ok');
        } else if (e.target.classList.contains('f-remove')) {
            if (!confirm(`Remove ${c.name} from the encounter?`)) return;
            render(await api('remove_combatant', { id }));
            loadList();
        }
    });

    const waitingIn = (group) => state.data.combatants
        .filter((c) => !c.in_combat && !c.dead && baseName(c.name) === group)
        .sort((a, b) => a.id - b.id);

    async function bringIn(ids) {
        if (!ids.length) return;
        const data = await api('bring_in', { ids });
        state.joined = new Set(data.joined);
        render(data);
        loadList();
        const names = data.combatants.filter((c) => state.joined.has(c.id))
            .map((c) => `${c.name} (${c.initiative})`);
        toast(`Joined: ${names.join(', ')}`, 'ok');
        setTimeout(() => { state.joined = null; document.querySelectorAll('tr.just-joined').forEach((tr) => tr.classList.remove('just-joined')); }, 4000);
    }

    $('#waiting-groups').addEventListener('click', async (e) => {
        const group = e.target.dataset.group;
        if (!group) return;
        if (e.target.classList.contains('bring')) {
            const select = e.target.closest('li').querySelector('select.qty');
            const qty = Number(select.value);
            select.dataset.group = ''; // forget the picked quantity: next time default to everyone left
            bringIn(waitingIn(group).slice(0, qty).map((c) => c.id));
        } else if (e.target.classList.contains('w-remove')) {
            const members = waitingIn(group);
            if (!confirm(`Remove all ${members.length} waiting ${group}?`)) return;
            for (const c of members) await api('remove_combatant', { id: c.id });
            render(await api('get_encounter', { id: state.encounterId }));
            loadList();
        }
    });

    $('#btn-bring-all').addEventListener('click', () =>
        bringIn(state.data.combatants.filter((c) => !c.in_combat && !c.dead).map((c) => c.id)));

    $('#sel-all').addEventListener('change', (e) => {
        state.selected.clear();
        if (e.target.checked) state.data.combatants.filter((c) => c.in_combat && !c.dead).forEach((c) => state.selected.add(c.id));
        render(state.data);
    });

    $('#btn-clear-sel').addEventListener('click', () => { state.selected.clear(); render(state.data); });

    $('#btn-hold').addEventListener('click', async () => {
        const ids = [...state.selected];
        state.selected.clear();
        render(await api('hold_back', { ids }));
        loadList();
    });

    // Remember the "Waiting" checkbox between adds.
    const waitingBox = $('#add-combatant').waiting;
    try { waitingBox.checked = localStorage.getItem('em.addWaiting') === '1'; } catch (_) {}
    waitingBox.addEventListener('change', () => {
        try { localStorage.setItem('em.addWaiting', waitingBox.checked ? '1' : '0'); } catch (_) {}
    });

    // ---- Event wiring ------------------------------------------------------
    $('#encounter-list').addEventListener('click', (e) => {
        const li = e.target.closest('li[data-id]');
        if (!li) return;
        if (e.target.closest('.enc-del')) deleteEncounter(Number(li.dataset.id), li.dataset.name);
        else openEncounter(Number(li.dataset.id));
    });

    async function deleteEncounter(id, name) {
        if (!confirm(`Delete "${name}" and all its combatants? This cannot be undone.`)) return;
        await api('delete_encounter', { id });
        if (id === state.encounterId) {
            state.encounterId = null;
            try { localStorage.removeItem('em.last'); } catch (_) {}
            $('#encounter').hidden = true;
            $('#empty').hidden = false;
        }
        toast(`Deleted "${name}"`, 'ok');
        loadList();
    }

    $('#new-encounter').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = e.target.name.value.trim();
        if (!name) return;
        const data = await api('create_encounter', { name });
        e.target.reset();
        openEncounter(data.encounter.id);
    });

    $('#enc-name').addEventListener('click', async () => {
        const name = prompt('Rename encounter', state.data.encounter.name);
        if (name && name.trim()) {
            render(await api('rename_encounter', { id: state.encounterId, name }));
            loadList();
        }
    });

    $('#add-combatant').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        const body = Object.fromEntries(new FormData(f).entries());
        render(await api('add_combatant', { encounter_id: state.encounterId, ...body }));
        f.name.value = '';
        f.notes.value = '';
        f.cr.value = '';
        f.level.value = '';
        f.initiative.value = '';
        f.count.value = 1;
        f.name.focus();
        loadList();
    });

    const step = async (dir) => {
        if (state.data?.encounter.paused) return toast('Combat is paused: press Resume first', 'warn');
        const starting = state.data?.encounter.round === 0;
        render(await api(dir > 0 ? 'next_turn' : 'prev_turn', { encounter_id: state.encounterId }));
        if (starting) setSetupHidden(true); // setup controls aren't needed once the fight begins
        loadList();
    };
    $('#btn-next').addEventListener('click', () => step(1));
    $('#btn-prev').addEventListener('click', () => step(-1));

    $('#btn-pause').addEventListener('click', async () => {
        render(await api('set_paused', { encounter_id: state.encounterId, paused: !state.data.encounter.paused }));
    });

    $('#btn-stop').addEventListener('click', async () => {
        if (!confirm('Stop combat?\n\nThis goes back to round 0 with nobody\'s turn.')) return;
        const fresh = confirm('Also start fresh?\n\nOK: restore everyone to full HP, clear conditions and clear initiative.\nCancel: keep HP, conditions and initiative as they are.');
        render(await api('reset_encounter', { encounter_id: state.encounterId, heal: fresh, clear_initiative: fresh }));
        setSetupHidden(false);
        loadList();
    });

    // ---- Collapsible setup panel (remembered per browser) -------------------
    function setSetupHidden(hidden) {
        $('#setup').hidden = hidden;
        const btn = $('#btn-setup');
        btn.textContent = hidden ? 'Show setup ▾' : 'Hide setup ▴';
        btn.setAttribute('aria-expanded', String(!hidden));
        try { localStorage.setItem('em.setupHidden', hidden ? '1' : '0'); } catch (_) {}
    }
    $('#btn-setup').addEventListener('click', () => setSetupHidden(!$('#setup').hidden));
    try { setSetupHidden(localStorage.getItem('em.setupHidden') === '1'); } catch (_) {}

    document.querySelectorAll('[data-roll]').forEach((b) => b.addEventListener('click', async () => {
        render(await api('roll_initiative', { encounter_id: state.encounterId, which: b.dataset.roll }));
    }));

    $('#btn-duplicate').addEventListener('click', async () => {
        const data = await api('duplicate_encounter', { id: state.encounterId });
        openEncounter(data.encounter.id);
    });

    // ---- Updates -------------------------------------------------------------
    // Quietly ask whether a newer release exists; the server caches the answer for a few hours.
    (async () => {
        try {
            const res = await fetch('update_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"check"}' });
            const u = await res.json();
            let snoozed = null;
            try { snoozed = localStorage.getItem('em.updateLater'); } catch (_) {}
            if (!u.available || u.dev || snoozed === u.latest) return;
            $('#update-text').innerHTML = `<strong>Version ${esc(u.latest)} is available.</strong> You have ${esc(u.current)}.`;
            $('#update-banner').hidden = false;
            $('#btn-update-later').onclick = () => {
                $('#update-banner').hidden = true;
                try { localStorage.setItem('em.updateLater', u.latest); } catch (_) {}
            };
        } catch (_) { /* offline or no releases: say nothing */ }
    })();

    $('#btn-delete').addEventListener('click', () => deleteEncounter(state.encounterId, state.data.encounter.name));

    const rows = $('#rows');
    const rowId = (el) => Number(el.closest('tr').dataset.id);

    // Inline field edits save on change.
    rows.addEventListener('change', async (e) => {
        const el = e.target;
        if (el.classList.contains('row-sel')) {
            const id = rowId(el);
            el.checked ? state.selected.add(id) : state.selected.delete(id);
            renderSelection(state.data.combatants.filter((c) => c.in_combat && !c.dead).length);
            return;
        }
        if (el.dataset.field) {
            render(await api('update_combatant', { id: rowId(el), fields: { [el.dataset.field]: el.value } }));
        } else if (el.classList.contains('add-cond') && el.value) {
            const c = state.data.combatants.find((x) => x.id === rowId(el));
            render(await api('update_combatant', { id: c.id, fields: { conditions: [...c.conditions, el.value] } }));
        }
    });

    async function applyHp(row, sign) {
        const input = row.querySelector('.amount');
        const n = Math.abs(parseInt(input.value, 10) || 0);
        if (!n) return input.focus();
        render(await api('adjust_hp', { id: Number(row.dataset.id), amount: sign * n }));
    }

    rows.addEventListener('click', async (e) => {
        const el = e.target;
        if (el.dataset.hp) {
            applyHp(el.closest('tr'), Number(el.dataset.hp));
        } else if (el.classList.contains('chip')) {
            const c = state.data.combatants.find((x) => x.id === rowId(el));
            const conditions = c.conditions.filter((k) => k !== el.dataset.cond);
            render(await api('update_combatant', { id: c.id, fields: { conditions } }));
        } else if (el.classList.contains('kill')) {
            const c = state.data.combatants.find((x) => x.id === rowId(el));
            if (confirm(`Mark ${c.name} as dead?\n\nThey leave initiative and move to the Fallen list (you can Revive them there).`)) {
                render(await api('kill', { id: c.id }));
            }
        } else if (el.classList.contains('remove')) {
            const c = state.data.combatants.find((x) => x.id === rowId(el));
            if (confirm(`Remove ${c.name}?`)) {
                render(await api('remove_combatant', { id: c.id }));
                loadList();
            }
        }
    });

    rows.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.classList.contains('amount')) {
            e.preventDefault();
            applyHp(e.target.closest('tr'), e.shiftKey ? 1 : -1);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (!state.encounterId || !e.shiftKey) return;
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
    });

    // ---- Notion: campaigns, party, monsters ---------------------------------
    $('#campaign').addEventListener('change', async (e) => {
        render(await api('set_campaign', { id: state.encounterId, campaign: e.target.value }));
    });

    $('#btn-party').addEventListener('click', async (e) => {
        const btn = e.target;
        btn.disabled = true;
        btn.textContent = 'Loading…';
        try {
            const data = await api('add_party', { encounter_id: state.encounterId });
            render(data);
            loadList();
            let msg = data.added ? `Added ${data.added} PC${data.added === 1 ? '' : 's'}` : 'Party already here';
            if (data.missing_stats.length) {
                msg += ` · fill AC / Max HP in Notion for: ${data.missing_stats.join(', ')}`;
            }
            toast(msg, data.missing_stats.length ? 'warn' : 'ok');
        } finally {
            btn.textContent = 'Add party';
            btn.disabled = false;
        }
    });

    $('#btn-refresh').addEventListener('click', async (e) => {
        const btn = e.target;
        btn.disabled = true;
        btn.textContent = 'Refreshing…';
        try {
            const data = await api('refresh_party', { encounter_id: state.encounterId });
            render(data);
            let msg = `Updated ${data.updated} PC${data.updated === 1 ? '' : 's'} from Notion`;
            if (data.not_found.length) msg += ` · not in campaign: ${data.not_found.join(', ')}`;
            if (data.missing_stats.length) msg += ` · still missing stats: ${data.missing_stats.join(', ')}`;
            toast(msg, data.not_found.length || data.missing_stats.length ? 'warn' : 'ok');
        } finally {
            btn.textContent = 'Refresh PCs';
            render(state.data);
        }
    });

    const results = $('#monster-results');
    const CRS = [['0', '0'], ['0.125', '1/8'], ['0.25', '1/4'], ['0.5', '1/2'],
        ...Array.from({ length: 30 }, (_, i) => [String(i + 1), String(i + 1)])];
    $('#cr-min').innerHTML = '<option value="">any</option>' + CRS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
    $('#cr-max').innerHTML = $('#cr-min').innerHTML;

    let searchTimer = null;
    let searchSeq = 0;
    function runSearch(delay = 300) {
        clearTimeout(searchTimer);
        const q = $('#monster-q').value.trim();
        const crMin = $('#cr-min').value;
        const crMax = $('#cr-max').value;
        if (q.length < 2 && crMin === '' && crMax === '') { results.hidden = true; return; }
        searchTimer = setTimeout(async () => {
            const seq = ++searchSeq;
            results.innerHTML = '<li class="muted">Searching…</li>';
            results.hidden = false;
            const { monsters, more } = await api('search_monsters', { q, cr_min: crMin, cr_max: crMax });
            if (seq !== searchSeq) return; // a newer search is in flight
            state.monsters = monsters;
            results.innerHTML = monsters.map((m, i) => `
                <li data-i="${i}">
                    <span class="m-name">${esc(m.name)}${m.legendary ? ' <span class="kind kind-monster">LEG</span>' : ''}</span>
                    <span class="m-meta">CR ${esc(m.cr)} · AC ${m.ac ?? '–'} · HP ${m.hp_max} · Init ${m.init_mod >= 0 ? '+' : ''}${m.init_mod}</span>
                    <span class="m-src">${esc(m.type)} · ${esc(m.source)}</span>
                </li>`).join('') || '<li class="muted">No matches</li>';
            if (more) {
                results.insertAdjacentHTML('beforeend',
                    `<li class="muted">Showing the first ${monsters.length}. Narrow it down with a name or a tighter CR range.</li>`);
            }
        }, delay);
    }
    $('#monster-q').addEventListener('input', () => runSearch());
    $('#monster-q').addEventListener('focus', () => runSearch(0));
    ['#cr-min', '#cr-max'].forEach((sel) => $(sel).addEventListener('change', (e) => {
        // Keep the range sensible: picking a min above the max (or vice versa) moves the other end.
        const min = $('#cr-min'), max = $('#cr-max');
        if (min.value !== '' && max.value !== '' && Number(min.value) > Number(max.value)) {
            (e.target === min ? max : min).value = e.target.value;
        }
        runSearch(0);
    }));

    // Picking a monster fills the Add form so you can set how many, then Add.
    results.addEventListener('click', (e) => {
        const li = e.target.closest('li[data-i]');
        if (!li) return;
        const m = state.monsters[Number(li.dataset.i)];
        const f = $('#add-combatant');
        f.name.value = m.name;
        f.kind.value = 'monster';
        f.init_mod.value = m.init_mod;
        f.ac.value = m.ac ?? '';
        f.hp_max.value = m.hp_max;
        f.notes.value = `CR ${m.cr} · ${m.type} · ${m.source}`;
        f.cr.value = m.cr === '?' ? '' : m.cr;
        syncAddKind();
        f.count.value = 1;
        results.hidden = true;
        $('#monster-q').value = '';
        f.count.focus();
        f.count.select();
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.monster-search')) results.hidden = true;
    });

    // Add form: PCs get a Level box, everyone else a CR box.
    function syncAddKind() {
        const f = $('#add-combatant');
        const pc = f.kind.value === 'pc';
        f.querySelector('.for-pc').hidden = !pc;
        f.querySelector('.for-monster').hidden = pc;
    }
    $('#add-combatant').kind.addEventListener('change', syncAddKind);

    // Typing a name by hand clears any monster notes left in the form.
    $('#add-combatant').name.addEventListener('input', (e) => { e.target.form.notes.value = ''; });

    // ---- Select a field's whole contents when you enter it -------------------
    // Works for Tab and click. The click's mouseup would normally drop the selection
    // and place the caret, so we swallow that one mouseup right after focusing.
    const selectable = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=button]):not([type=submit]), textarea';
    document.addEventListener('focusin', (e) => {
        const el = e.target;
        if (!el.matches?.(selectable)) return;
        el.select();
        el.dataset.justFocused = '1';
        setTimeout(() => delete el.dataset.justFocused, 300);
    });
    document.addEventListener('mouseup', (e) => {
        const el = e.target;
        if (el.dataset?.justFocused) {
            e.preventDefault();
            delete el.dataset.justFocused;
        }
    }, true);

    // ---- Boot --------------------------------------------------------------
    (async () => {
        const meta = await api('meta');
        state.conditions = meta.conditions;
        state.notion = meta.notion;
        state.campaigns = meta.campaigns;
        state.difficultyScale = meta.difficulty_scale;
        $('#campaign').insertAdjacentHTML('beforeend',
            meta.campaigns.map((c) => `<option value="${esc(c.key)}">${esc(c.name)}</option>`).join(''));
        $('#monster-q').disabled = !meta.notion;
        $('#notion-off').hidden = meta.notion;
        await loadList();
        let last = null;
        try { last = localStorage.getItem('em.last'); } catch (_) {}
        if (last) openEncounter(Number(last)).catch(() => {
            try { localStorage.removeItem('em.last'); } catch (_) {}
        });
    })();
})();
