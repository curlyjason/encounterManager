<?php
declare(strict_types=1);
// A stat block in its own tab: statblock.php?id=<combatant id>  or  ?ref=<monster ref>
$v = fn(string $f) => filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stat block · Encounter Manager</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
    <link rel="stylesheet" href="assets/statblock.css?v=<?= $v('statblock.css') ?>">
</head>
<body>
<main class="sb-page"><div id="sb"></div></main>
<script src="assets/vendor/showdown.min.js"></script>
<script src="assets/statblock.js?v=<?= $v('statblock.js') ?>"></script>
<script>
(async () => {
    const q = new URLSearchParams(location.search);
    const target = q.get('id') ? { id: Number(q.get('id')) } : { ref: q.get('ref') || '' };
    const el = document.getElementById('sb');
    StatBlock.loading(el);
    try {
        const d = await StatBlock.load(target);
        document.title = d.name + ' · Encounter Manager';
        StatBlock.render(el, d, { view: 'tab' });
    } catch (e) {
        el.innerHTML = '<div class="sb-loading bad"></div>';
        el.firstChild.textContent = e.message;
    }
})();
</script>
</body>
</html>
