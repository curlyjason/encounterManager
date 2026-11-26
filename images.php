<?php
declare(strict_types=1);
require __DIR__ . '/lib/setup.php';
require __DIR__ . '/lib/version.php';

$allowed = setup_allowed();
$v = fn(string $f) => filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monster images · Encounter Manager</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <meta name="theme-color" content="#7c3aed">
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
    <link rel="stylesheet" href="assets/setup.css?v=<?= $v('setup.css') ?>">
</head>
<body class="setup-page">
<main class="wizard">
    <header class="wiz-head">
        <h1>Monster images</h1>
        <a href="./" class="muted">← Back to encounters</a>
    </header>

    <?php if (!$allowed): ?>
        <section class="step"><p>Images can only be managed on the computer running Encounter Manager.</p></section>
    <?php else: ?>
    <p class="intro">Monster art is shared: everyone using Encounter Manager sees the same collection, kept with the app on
        GitHub, and anyone can add to it. Add art from any stat block (click the picture, or <strong>+ Add art</strong>).
        Every image is shrunk to 512 px and saved as WebP, so each one is small.</p>

    <section class="step" id="sum">
        <h2>Your collection</h2>
        <ul class="checks" id="sum-list"><li class="muted">Loading…</li></ul>
        <p><button id="btn-refresh">Check GitHub for new images</button> <span id="refresh-result" class="muted small"></span></p>
    </section>

    <section class="step" id="share" hidden>
        <h2>Share your images</h2>
        <p>Images you add are first saved only on this computer. To add them to the shared collection, download them as a zip
            and send it to whoever looks after Encounter Manager. If you have a GitHub account, you can attach it to a new issue at
            <a id="issue-link" target="_blank" rel="noopener"></a>. Once they're added, everyone gets them automatically.</p>
        <p><a id="btn-export" class="button primary" href="images_api.php?action=export">Download my new images (.zip)</a></p>
        <p class="muted small">“Personal use only” images are never included.</p>
    </section>

    <section class="step" id="import" hidden>
        <h2>Add images someone shared</h2>
        <p>Pick a zip made with “Download my new images”. Each image is checked and converted again before it's added.</p>
        <form id="import-form" class="row-form">
            <input type="file" name="zip" accept=".zip,application/zip" required>
            <label class="small"><input type="checkbox" name="replace" value="1"> Replace images we already have</label>
            <button class="primary">Import</button>
        </form>
        <p id="import-result" class="result" hidden></p>
    </section>

    <section class="step" id="folder">
        <h2>Add a whole folder</h2>
        <p>Have a folder of art or tokens (for example a token pack you bought)? Files are matched to monsters by their names,
            like <code>Adult Red Dragon.png</code> or <code>adult_red_dragon_token.webp</code>. Needs the 5e Artisanal Database set up in Setup.</p>
        <div class="row-form">
            <input id="folder-path" type="text" placeholder="/Users/you/Downloads/Monster Tokens" autocomplete="off" spellcheck="false"
                   aria-label="Folder of images">
        </div>
        <div class="col-grid" style="margin:8px 0">
            <label for="folder-license">Where are they from?</label>
            <select id="folder-license"></select>
            <label for="folder-credit">Credit</label>
            <input id="folder-credit" placeholder="Who made them" maxlength="200">
            <label for="folder-source">Source page</label>
            <input id="folder-source" type="url" placeholder="https://… (optional)">
        </div>
        <p id="folder-note" class="muted small"></p>
        <p><label class="small"><input type="checkbox" id="folder-replace"> Replace images we already have</label></p>
        <p><button id="btn-folder" class="primary">Add images from this folder</button></p>
        <p id="folder-result" class="result" hidden></p>
    </section>

    <section class="step">
        <h2>Credits</h2>
        <p class="muted small">Who made each picture, and under what license.</p>
        <ul id="credits" class="credits"><li class="muted">Loading…</li></ul>
    </section>
    <?php endif; ?>
</main>
<?php if ($allowed): ?>
<script>
(() => {
    const $ = (s) => document.querySelector(s);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    async function api(body) {
        const res = await fetch('images_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const j = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
        if (!res.ok || j.error) throw new Error(j.error || `HTTP ${res.status}`);
        return j;
    }
    const show = (el, msg, good) => { el.hidden = !msg; el.className = 'result ' + (good ? 'good' : 'bad'); el.innerHTML = msg || ''; };
    const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
    let S = null;

    async function summary() {
        S = await api({ action: 'summary' });
        const items = [
            `<li class="ok">${plural(S.shared, 'image')} in the shared collection${S.dev ? ' (images/monsters in this repo)' : ' (from GitHub)'}</li>`,
            `<li class="ok">${plural(S.mine, 'image')} of your own on this computer` +
                (S.mine ? ` <span class="muted">(${S.to_share} waiting to be shared, ${S.personal} personal)</span>` : '') + '</li>',
        ];
        if (!S.can_edit) items.push('<li class="no">Adding images needs PHP’s GD extension with WebP support. You can still see the shared collection.</li>');
        if (S.dev) items.push('<li class="ok">This is the development copy: new shareable images go straight into <code>images/monsters</code>. Commit and push to share them.</li>');
        $('#sum-list').innerHTML = items.join('');
        $('#share').hidden = S.dev;
        $('#btn-refresh').parentElement.hidden = S.dev; // the dev copy *is* the collection
        $('#import').hidden = !S.dev;
        $('#btn-export').classList.toggle('disabled', !S.to_share);
        $('#btn-export').textContent = S.to_share ? `Download my ${plural(S.to_share, 'new image')} (.zip)` : 'No new images to share yet';
        const issue = `https://github.com/${S.repo}/issues/new?title=${encodeURIComponent('Monster images')}`;
        $('#issue-link').href = issue;
        $('#issue-link').textContent = `github.com/${S.repo}/issues`;
        if (!$('#folder-license').options.length) {
            $('#folder-license').innerHTML = '<option value="">Pick one…</option>' +
                Object.entries(S.licenses).map(([k, l]) => `<option value="${k}">${esc(l.label)}</option>`).join('');
        }
        $('#folder').hidden = !S.can_edit;
        $('#btn-folder').disabled = !S.known;
    }

    async function credits() {
        const { images } = await api({ action: 'list' });
        $('#credits').innerHTML = images.map((i) => `
            <li>
                <img src="${esc(i.url)}" alt="" loading="lazy">
                <span><strong>${esc(i.name || i.key)}</strong>
                    <span class="muted">${esc([i.credit, i.license_label].filter(Boolean).join(' · '))}
                    ${i.source ? ` · <a href="${esc(i.source)}" target="_blank" rel="noopener">source</a>` : ''}
                    ${i.where !== 'shared' ? ` · <em>${esc(i.where)}</em>` : ''}</span></span>
            </li>`).join('') || '<li class="muted">No images yet. Open a stat block and click “+ Add art”.</li>';
    }

    $('#btn-refresh').onclick = async () => {
        $('#refresh-result').textContent = 'Checking…';
        try {
            await api({ action: 'refresh' });
            await summary();
            await credits();
            $('#refresh-result').textContent = 'Up to date.';
        } catch (e) { $('#refresh-result').textContent = e.message; }
    };
    $('#btn-export').addEventListener('click', (e) => { if (!S?.to_share) e.preventDefault(); });

    $('#import-form').onsubmit = async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Importing…';
        try {
            const res = await fetch('images_api.php', { method: 'POST', body: new FormData(e.target) });
            const r = await res.json();
            if (!res.ok || r.error) throw new Error(r.error || 'Import failed');
            show($('#import-result'), [
                `✓ Added ${plural(r.added.length, 'image')}${r.added.length ? ': ' + esc(r.added.join(', ')) : ''}.`,
                r.skipped.length ? `Already had ${r.skipped.length}: ${esc(r.skipped.join(', '))}.` : '',
                r.failed.length ? `<span class="bad">Couldn't add ${r.failed.length}: ${esc(r.failed.join('; '))}.</span>` : '',
                r.added.length && S.dev ? 'Commit and push <code>images/monsters</code> to share them with everyone.' : '',
            ].filter(Boolean).join('<br>'), true);
            e.target.reset();
            await summary();
            await credits();
        } catch (err) { show($('#import-result'), esc(err.message), false); }
        btn.disabled = false;
        btn.textContent = 'Import';
    };

    const folderNote = () => {
        const l = S?.licenses[$('#folder-license').value];
        $('#folder-note').textContent = !l ? 'Only use art you have the right to share. Most pictures found online are copyrighted: choose “Personal use only” for those.'
            : !l.share ? 'Kept on this computer only.' : 'These go into the shared collection everyone sees.';
    };
    $('#folder-license').addEventListener('change', folderNote);

    $('#btn-folder').onclick = async () => {
        const btn = $('#btn-folder');
        const body = {
            action: 'import_folder', path: $('#folder-path').value.trim(), license: $('#folder-license').value,
            credit: $('#folder-credit').value, source: $('#folder-source').value, replace: $('#folder-replace').checked,
        };
        if (!body.path) return show($('#folder-result'), 'Type or drag in the folder first.', false);
        if (!body.license) return show($('#folder-result'), 'Say where the images are from.', false);
        btn.disabled = true;
        const tot = { added: [], skipped: [], unmatched: [], failed: [] };
        try {
            for (let offset = 0; offset !== null;) {
                const r = await api({ ...body, offset });
                Object.keys(tot).forEach((k) => tot[k].push(...r[k]));
                offset = r.next;
                btn.textContent = `Working… ${Math.min(offset ?? r.total, r.total)} of ${r.total}`;
            }
            show($('#folder-result'), [
                `✓ Added ${plural(tot.added.length, 'image')}.`,
                tot.skipped.length ? `${tot.skipped.length} skipped (already have them).` : '',
                tot.unmatched.length ? `${tot.unmatched.length} didn't match a monster name: ${esc(tot.unmatched.slice(0, 15).join(', '))}${tot.unmatched.length > 15 ? '…' : ''}` : '',
                tot.failed.length ? `<span class="bad">${tot.failed.length} failed: ${esc(tot.failed.slice(0, 10).join('; '))}</span>` : '',
            ].filter(Boolean).join('<br>'), true);
            await summary();
            await credits();
        } catch (e) { show($('#folder-result'), esc(e.message), false); }
        btn.disabled = false;
        btn.textContent = 'Add images from this folder';
    };
    $('#folder-path').addEventListener('drop', (e) => {
        const t = e.dataTransfer.getData('text/uri-list') || e.dataTransfer.getData('text/plain');
        if (!t) return;
        e.preventDefault();
        e.target.value = decodeURIComponent(t.trim().split('\n')[0].replace(/^file:\/\//, '')).replace(/\/$/, '');
    });

    summary().then(folderNote).then(credits).catch((e) => { $('#sum-list').innerHTML = `<li class="no">${esc(e.message)}</li>`; });
})();
</script>
<?php endif; ?>
</body>
</html>
