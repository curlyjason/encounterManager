<?php
declare(strict_types=1);
require __DIR__ . '/lib/setup.php';

$allowed = setup_allowed();
$v = fn(string $f) => filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Encounter Manager Setup</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
    <link rel="stylesheet" href="assets/setup.css?v=<?= $v('setup.css') ?>">
</head>
<body class="setup-page">
<main class="wizard">
    <header class="wiz-head">
        <h1>Encounter Manager setup</h1>
        <a href="./" id="back-link" class="muted" hidden>← Back to encounters</a>
    </header>

    <?php if (!$allowed): ?>
        <section class="step"><p>Setup only works on the computer running Encounter Manager. Open it there at
            <code>http://localhost…/setup.php</code>.</p></section>
    <?php else: ?>

    <p class="intro">Encounter Manager is a combat tracker for D&amp;D 5e. It can load your party and search your
        monsters from Notion. This page connects it to your Notion workspace. It takes about five minutes,
        and you can come back here any time from <strong>⚙ Setup</strong> in the sidebar.</p>

    <!-- 1 -->
    <section class="step" id="step-req">
        <h2><span class="num">1</span> Check this computer</h2>
        <ul id="req-list" class="checks"></ul>
        <p id="req-bad" class="bad" hidden>Fix the items marked ✕ (see README.md), then reload this page.</p>
    </section>

    <!-- 2 -->
    <section class="step" id="step-token">
        <h2><span class="num">2</span> Create a Notion integration</h2>
        <p>An integration is a private key that lets this app read the Notion databases you choose, and nothing else.</p>
        <ol class="howto">
            <li>Open <a href="https://www.notion.so/profile/integrations" target="_blank" rel="noopener">notion.so/profile/integrations</a>
                (sign in to Notion if asked) and click <strong>New integration</strong>.</li>
            <li>Name it <strong>Encounter Manager</strong>, choose your workspace, leave the type as <strong>Internal</strong>, and click <strong>Save</strong>.</li>
            <li>On the integration's <strong>Capabilities</strong> section, tick <strong>Read content</strong> and
                <strong>Update content</strong>, then <strong>Save</strong>.
                <span class="muted">Update content only lets setup add the columns it needs. You can untick it once setup is done.</span></li>
            <li>Under <strong>Internal Integration Secret</strong>, click <strong>Show</strong>, then <strong>Copy</strong>, and paste it here:</li>
        </ol>
        <div class="row-form">
            <input id="token" type="password" placeholder="ntn_…" autocomplete="off" spellcheck="false">
            <button id="btn-test" class="primary">Connect</button>
        </div>
        <p id="token-saved" class="muted" hidden>A secret is already saved. Leave the box empty to keep it.</p>
        <p id="token-result" class="result" hidden></p>
        <p class="muted small">The secret is stored only on this computer, in <code>lib/config.php</code>.
            <button id="btn-skip" class="linkish">Skip Notion and type everything in by hand</button></p>
    </section>

    <!-- 3 -->
    <section class="step locked" id="step-dbs">
        <h2><span class="num">3</span> Share your databases with it</h2>
        <p>The integration can only see what you share with it. In Notion, for your <strong>monster database</strong> and each
            campaign's <strong>characters database</strong>:</p>
        <ol class="howto">
            <li>Open the database (or the page it sits on) as a full page.</li>
            <li>Click <strong>•••</strong> at the top right → <strong>Connections</strong> (or <em>Add connections</em>).</li>
            <li>Search for <strong>Encounter Manager</strong> and confirm.</li>
        </ol>
        <p class="muted small">Tip: sharing a parent page shares every database inside it.</p>
        <button id="btn-find">Find my databases</button>
        <p id="dbs-result" class="result" hidden></p>

        <div id="db-pick" hidden>
            <h3>Monster database</h3>
            <p id="monster-db-note" class="muted small" hidden>Monster search uses the 5e Artisanal Database (below), so this is only needed if you stop using it.</p>
            <select id="monster-db"></select>
            <h3>Campaigns <span class="muted small">Tick each database that lists a campaign's player characters.</span></h3>
            <ul id="campaign-list" class="db-list"></ul>
        </div>
    </section>

    <!-- 4 -->
    <section class="step locked" id="step-cols">
        <h2><span class="num">4</span> Match the columns</h2>
        <p>Tell the app which column holds what. Anything missing from a characters database can be added for you as
            an empty Number column; fill the numbers in afterwards in Notion.</p>
        <div id="col-cards"></div>
    </section>

    <!-- 5 -->
    <section class="step locked" id="step-save">
        <h2><span class="num">5</span> Save</h2>
        <button id="btn-save" class="primary">Save settings</button>
        <p id="save-result" class="result" hidden></p>
        <p id="save-done" hidden><a class="button primary" href="./">Open Encounter Manager →</a></p>
    </section>

    <!-- Optional: 5eADB -->
    <section class="step locked" id="step-adb">
        <h2><span class="num">+</span> Monsters from the 5e Artisanal Database <span class="muted small">optional</span></h2>
        <p>If you have Sly Flourish's <strong>5e Artisanal Database</strong> (a Patreon reward) unzipped on this computer,
            monster search can use its 3,000+ monsters instead of a Notion database, with a link to each stat block.
            It works with or without Notion.</p>
        <div class="row-form">
            <input id="adb-path" type="text" placeholder="/Users/you/Documents/5e_artisanal_database" autocomplete="off" spellcheck="false"
                   aria-label="5e Artisanal Database folder">
            <button id="btn-adb-find">Find it</button>
            <button id="btn-adb-save" class="primary">Use this folder</button>
        </div>
        <p class="muted small">The folder that holds <code>index.html</code>, <code>monsters</code> and <code>css_js</code>.
            On a Mac, you can drag the folder from Finder into the box. On Windows, Shift + right-click it → <em>Copy as path</em>.</p>
        <ul id="adb-found" class="db-list" hidden></ul>
        <p id="adb-result" class="result" hidden></p>
        <p id="adb-off" class="small" hidden><button id="btn-adb-remove" class="linkish">Stop using it</button>
            <span class="muted">(monster search goes back to Notion)</span></p>
    </section>
    <?php endif; ?>
</main>
<?php if ($allowed): ?>
<script src="assets/setup.js?v=<?= $v('setup.js') ?>"></script>
<?php endif; ?>
</body>
</html>
