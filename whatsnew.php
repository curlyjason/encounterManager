<?php
declare(strict_types=1);
require __DIR__ . '/lib/version.php';

$changelog = @file_get_contents(__DIR__ . '/CHANGELOG.md') ?: '';
$v = fn(string $f) => filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>What's New</title>
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
        <h1>Encounter Manager <span class="muted">v<?= htmlspecialchars(app_version()) ?></span></h1>
        <a href="./" class="muted">← Back to encounters</a>
    </header>

    <section class="step" id="update-box">
        <h2>Updates</h2>
        <p id="upd-status" class="muted">Checking for updates…</p>
        <div id="upd-notes" class="notes"></div>
        <p id="upd-actions" hidden>
            <button id="btn-install" class="primary">Install update</button>
            <a id="upd-page" class="muted" target="_blank" rel="noopener">or download it from GitHub</a>
        </p>
        <p><button id="btn-check" class="linkish">Check again</button></p>
    </section>

    <section class="step notes">
        <?= md_to_html($changelog) ?>
    </section>
</main>
<script>
(() => {
    const $ = (s) => document.querySelector(s);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    async function api(action, body = {}) {
        const res = await fetch('update_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
        const data = await res.json().catch(() => ({ error: `Server error (${res.status})` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }
    async function check(force) {
        $('#upd-status').textContent = 'Checking for updates…';
        $('#upd-notes').innerHTML = '';
        $('#upd-actions').hidden = true;
        let u;
        try { u = await api('check', { force }); } catch (e) { $('#upd-status').textContent = e.message; return; }
        if (u.error) { $('#upd-status').textContent = u.error; return; }
        if (!u.available) {
            $('#upd-status').textContent = `You have the newest version (${u.current}).`;
            return;
        }
        $('#upd-status').innerHTML = `<strong>Version ${esc(u.latest)} is available.</strong> You have ${esc(u.current)}.`;
        $('#upd-notes').innerHTML = u.notes.map((n) => `<h3>${esc(n.version)}${n.date ? ` <span class="muted small">${esc(n.date)}</span>` : ''}</h3>${n.html}`).join('');
        $('#upd-page').href = u.page_url;
        $('#upd-actions').hidden = false;
        $('#btn-install').hidden = u.dev || !u.zip_url;
        if (u.dev) $('#upd-page').textContent = 'This is the development copy: update it with git.';
    }
    $('#btn-check').addEventListener('click', () => check(true));
    $('#btn-install').addEventListener('click', async (e) => {
        if (!confirm('Install the update now?\n\nYour encounters and settings are kept, and the current version is backed up first.')) return;
        const btn = e.target;
        btn.disabled = true;
        btn.textContent = 'Installing…';
        try {
            const r = await api('install');
            alert(`Updated from ${r.from} to ${r.to}.\n\nThe previous version was backed up to ${r.backup}.`);
            location.reload();
        } catch (err) {
            alert(err.message);
            btn.disabled = false;
            btn.textContent = 'Install update';
        }
    });
    check(false);
})();
</script>
</body>
</html>
