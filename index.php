<?php
// First run: no settings yet, so start with the setup wizard.
if (!is_file(__DIR__ . '/lib/config.php')) {
    header('Location: setup.php');
    exit;
}
require __DIR__ . '/lib/version.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Encounter Manager</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
    <link rel="stylesheet" href="assets/statblock.css?v=<?= filemtime(__DIR__ . '/assets/statblock.css') ?>">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="side-head">
            <h1>Encounter Manager</h1>
            <button id="btn-hide-side" class="side-toggle" title="Hide the encounter list ([)" aria-label="Hide the encounter list">«</button>
        </div>
        <form id="new-encounter" class="row">
            <input name="name" placeholder="New encounter name" required>
            <button type="submit">Add</button>
        </form>
        <ul id="encounter-list" class="enc-list"></ul>
        <div class="side-foot">
            <a href="setup.php" class="setup-link" title="Notion connection, campaigns and columns"><span aria-hidden="true">⚙</span> Setup</a>
            <a href="images.php" class="images-link" title="Add, share and import monster art">Monster images</a>
            <a href="whatsnew.php" class="version-link" title="Release notes and updates">v<?= htmlspecialchars(app_version()) ?> · What's new</a>
        </div>
    </aside>

    <main id="main">
        <button id="btn-show-side" class="side-toggle show" title="Show the encounter list ([)" aria-label="Show the encounter list" hidden>»</button>
        <div id="update-banner" class="update-banner" hidden>
            <span id="update-text"></span>
            <a href="whatsnew.php">What's new</a>
            <button id="btn-update-later" class="linkish" title="Hide until the next version">Later</button>
        </div>
        <div id="empty" class="empty">Create or pick an encounter to begin.</div>

        <section id="encounter" hidden>
            <header class="enc-header">
                <h2 id="enc-name" title="Click to rename"></h2>
                <div class="round">Round <strong id="enc-round">–</strong></div>
                <span id="paused-badge" class="paused-badge" hidden>Paused</span>
                <button id="btn-setup" class="setup-toggle" aria-expanded="true" aria-controls="setup">Hide setup ▴</button>
                <div class="turn-controls">
                    <label class="follow-turns" title="Open the stat block of whoever's turn it is when you press Next or Prev">
                        <input type="checkbox" id="sb-follow"> Stat block each turn</label>
                    <button id="btn-stop" title="Stop combat and reset to round 0" disabled>■ Stop</button>
                    <button id="btn-pause" title="Pause combat, keeping the round and whose turn it is" disabled>❚❚ Pause</button>
                    <button id="btn-prev" title="Previous turn (Shift+←)">◀ Prev</button>
                    <button id="btn-next" class="primary" title="Next turn (Shift+→)">Start ▶</button>
                </div>
            </header>

            <div id="difficulty" class="difficulty">
                <span id="diff-rating" class="diff-rating">–</span>
                <div class="diff-bar" aria-hidden="true">
                    <span id="diff-fill" class="diff-fill"></span>
                    <span class="diff-mark" data-mark="low"></span>
                    <span class="diff-mark" data-mark="moderate"></span>
                    <span class="diff-mark" data-mark="high"></span>
                </div>
                <span id="diff-text" class="diff-text muted"></span>
                <label class="diff-scale" title="Multiplies the 2024 DMG XP budgets. 1.5 = official numbers +50%.">
                    Budget × <input id="diff-scale" type="number" min="0.25" max="5" step="0.05">
                </label>
            </div>
            <div id="diff-warn" class="diff-warn" hidden></div>

            <div id="setup" class="setup">
            <div class="toolbar notion-bar">
                <label class="label">Campaign
                    <select id="campaign"><option value="">— none —</option></select>
                </label>
                <button id="btn-party" class="primary" disabled>Add party</button>
                <button id="btn-refresh" disabled title="Re-read AC, Max HP and Init Mod from Notion">Refresh PCs</button>
                <div class="monster-search">
                    <div class="search-row">
                        <input id="monster-q" type="search" placeholder="Search monsters in Notion…" autocomplete="off">
                        <label class="cr-range" title="Challenge rating range">CR
                            <select id="cr-min" aria-label="Minimum CR"></select>
                            –
                            <select id="cr-max" aria-label="Maximum CR"></select>
                        </label>
                    </div>
                    <ul id="monster-results" class="monster-results" hidden></ul>
                </div>
                <span id="notion-off" class="muted" hidden>Notion not connected: <a href="setup.php">run Setup</a></span>
            </div>

            <div class="toolbar">
                <span class="label">Roll initiative:</span>
                <button data-roll="unrolled">Unrolled</button>
                <button data-roll="monsters">Non-PCs</button>
                <button data-roll="all">Everyone</button>
                <span class="spacer"></span>
                <button id="btn-duplicate">Duplicate</button>
                <button id="btn-delete" class="danger">Delete</button>
            </div>

            <form id="add-combatant" class="add-form">
                <input name="name" placeholder="Name" required>
                <select name="kind">
                    <option value="monster">Monster</option>
                    <option value="npc">NPC</option>
                    <option value="pc">PC</option>
                </select>
                <label>Init mod <input name="init_mod" type="number" value="0"></label>
                <label>Init <input name="initiative" type="number" placeholder="roll"></label>
                <label>AC <input name="ac" type="number"></label>
                <label>HP <input name="hp_max" type="number" min="0" value="10"></label>
                <label class="for-monster">CR <input name="cr" class="cr-input" placeholder="1/2"></label>
                <label class="for-pc" hidden>Level <input name="level" type="number" min="1" max="20"></label>
                <label>× <input name="count" type="number" min="1" max="30" value="1"></label>
                <input name="notes" type="hidden">
                <input name="ref" type="hidden">
                <label class="waiting-opt" title="Add them to the waiting list instead of straight into initiative">
                    <input name="waiting" type="checkbox"> Waiting
                </label>
                <button type="submit" class="primary">Add</button>
            </form>
            </div><!-- /#setup -->

            <div id="selection-bar" class="selection-bar" hidden>
                <span id="selection-count"></span>
                <button id="btn-hold">Move to waiting</button>
                <button id="btn-clear-sel">Clear</button>
            </div>

            <table class="combatants">
                <thead>
                <tr>
                    <th class="sel"><input type="checkbox" id="sel-all" title="Select all"></th>
                    <th>Init</th><th>Name</th><th>AC</th><th>HP</th><th>Damage / Heal</th><th>Conditions</th><th></th>
                </tr>
                </thead>
                <tbody id="rows"></tbody>
            </table>

            <section id="waiting" class="waiting" hidden>
                <header>
                    <h3>Waiting to join <span id="waiting-count" class="muted"></span></h3>
                    <button id="btn-bring-all">Bring in all</button>
                </header>
                <ul id="waiting-groups"></ul>
            </section>

            <section id="fallen" class="fallen" hidden>
                <header>
                    <h3>Fallen <span id="fallen-count" class="muted"></span></h3>
                </header>
                <ul id="fallen-list"></ul>
            </section>
        </section>
    </main>

    <aside id="sb-side" class="sb-side" hidden aria-label="Stat block"></aside>
</div>

<div id="toast" class="toast" hidden></div>
<script src="assets/vendor/showdown.min.js"></script>
<script src="assets/statblock.js?v=<?= filemtime(__DIR__ . '/assets/statblock.js') ?>"></script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
