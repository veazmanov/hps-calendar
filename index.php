<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

[$events, $problems, $error] = load_events();

// ---- Tags & filtering -----------------------------------------------------
$allTags = [];
foreach ($events as $ev) {
    foreach (event_tags($ev) as $t) {
        $allTags[mb_strtolower($t)] ??= $t;   // first spelling wins
    }
}
natcasesort($allTags);

$requested = isset($_GET['tag']) && is_string($_GET['tag']) ? mb_strtolower(trim($_GET['tag'])) : '';
$activeTag = $allTags[$requested] ?? null;

if ($activeTag !== null) {
    $events = array_values(array_filter($events, fn($ev) => in_array(
        mb_strtolower($activeTag),
        array_map('mb_strtolower', event_tags($ev)),
        true
    )));
}

$today = new DateTimeImmutable('today');
// An event stays "upcoming" until its last day has passed
$upcoming = array_values(array_filter($events, fn($ev) => !is_past($ev, $today)));
$past     = array_reverse(array_values(array_filter($events, fn($ev) => is_past($ev, $today))));

// Group upcoming events by month, keyed "2026-10" (also the anchor id)
$byMonth = [];
foreach ($upcoming as $ev) {
    $byMonth[$ev['_date']->format('Y-m')][] = $ev;
}
$thisYear = $today->format('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HPS Talks Utrecht</title>
    <?php /* ?v= changes whenever style.css changes, so browsers never use a stale copy */ ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body>
<main>
    <header>
        <div class="title-row">
            <h1>HPS Talks Utrecht</h1>
            <div class="search" hidden>
                <button type="button" class="search-toggle" aria-label="Search" aria-expanded="false" aria-controls="q">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <input id="q" type="search" aria-label="Search" autocomplete="off" tabindex="-1">
            </div>
        </div>
        <p>Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
        <p><small>Unofficial overview compiled by a student. Always check the details with the organisers.</small></p>
        <?php if ($updated = trim((string) @file_get_contents(__DIR__ . '/last-deploy.txt'))): ?>
            <p><small>Last updated <?= e($updated) ?></small></p>
        <?php endif; ?>
        <p class="buttons">
            <a class="button" href="correction.php">Submit a correction</a>
            <a class="button" href="contributing.php">Submit your talk</a>
            <a class="button" href="subscribe.php<?= $activeTag !== null ? '?tag=' . e(urlencode($activeTag)) : '' ?>">Subscribe in your calendar</a>
        </p>
        <?php if ($allTags): ?>
            <nav class="filters" aria-label="Filter by topic">
                <span class="label">Show:</span>
                <a class="tag<?= $activeTag === null ? ' active' : '' ?>" href="<?= e(tag_url(null)) ?>"<?= $activeTag === null ? ' aria-current="page"' : '' ?>>All</a>
                <?php foreach ($allTags as $t): ?>
                    <a class="tag<?= $activeTag === $t ? ' active' : '' ?>" href="<?= e(tag_url($t)) ?>"<?= $activeTag === $t ? ' aria-current="page"' : '' ?>><?= e($t) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <?php if (!$error && count($byMonth) > 1): ?>
        <nav class="months" aria-label="Jump to month">
            <?php foreach ($byMonth as $key => $list):
                $m = $list[0]['_date'];
                // "Jan ’27" once the list runs into another year
                $label = $m->format('M') . ($m->format('Y') !== $thisYear ? ' ’' . $m->format('y') : ''); ?>
                <a class="month-chip" href="#m-<?= e($key) ?>" data-month="<?= e($key) ?>" title="<?= e($m->format('F Y')) ?>: <?= count($list) ?> event<?= count($list) === 1 ? '' : 's' ?>">
                    <?= e($label) ?> <span class="count"><?= count($list) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <p class="empty" id="no-results" hidden>No events match your search.</p>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php elseif (!$upcoming): ?>
        <p class="empty"><?= $activeTag !== null ? 'No upcoming ' . e($activeTag) . ' events at the moment.' : 'No upcoming events at the moment.' ?></p>
    <?php else: ?>
        <?php foreach ($byMonth as $key => $list): ?>
            <section id="m-<?= e($key) ?>" data-month="<?= e($key) ?>">
                <h2><?= e($list[0]['_date']->format('F Y')) ?> <span class="count"><?= count($list) ?></span></h2>
                <?php render_day_groups($list, $today); ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($past): ?>
        <details id="past">
            <summary>Past events (<?= count($past) ?>)</summary>
            <?php render_day_groups($past, $today, true); ?>
        </details>
    <?php endif; ?>

    <?php if ($problems): ?>
        <details class="problems">
            <summary><?= count($problems) ?> event file<?= count($problems) === 1 ? '' : 's' ?> could not be shown</summary>
            <ul>
                <?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <footer>
        Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>
        · <a href="https://github.com/victorelgersma/hps-calendar" target="_blank" rel="noopener">Source on GitHub</a>
    </footer>
</main>
<script>
// Instant search: hides rows (and empty days and months) that don't match,
// and keeps the per-month counts in the month bar in step.
// Every word must appear somewhere in the event; ?q=... is kept in the URL.
(function () {
    var box = document.querySelector('.search');
    var input = document.getElementById('q');
    var toggle = box && box.querySelector('.search-toggle');
    var cards = Array.prototype.slice.call(document.querySelectorAll('article.event'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('main > section'));
    var past = document.getElementById('past');
    var none = document.getElementById('no-results');
    if (!box || !cards.length) return;
    box.hidden = false;

    var params = new URLSearchParams(location.search);
    input.value = params.get('q') || '';

    function norm(s) {
        return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    cards.forEach(function (c) { c._text = norm(c.getAttribute('data-search') || ''); });

    function apply() {
        var words = norm(input.value).split(/\s+/).filter(Boolean);
        var shown = 0;
        cards.forEach(function (c) {
            var hit = words.every(function (w) { return c._text.indexOf(w) !== -1; });
            c.hidden = !hit;
            if (hit) shown++;
        });
        Array.prototype.forEach.call(document.querySelectorAll('.day-group'), function (g) {
            g.hidden = !g.querySelector('article.event:not([hidden])');
        });
        sections.forEach(function (s) {
            var n = s.querySelectorAll('article.event:not([hidden])').length;
            s.hidden = n === 0;
            var chip = document.querySelector('.month-chip[data-month="' + s.getAttribute('data-month') + '"]');
            var counts = [s.querySelector('h2 .count'), chip && chip.querySelector('.count')];
            counts.forEach(function (c) { if (c) c.textContent = n; });
            if (chip) chip.hidden = n === 0;
        });
        if (past) {
            var pastHits = past.querySelectorAll('article.event:not([hidden])').length;
            past.hidden = pastHits === 0;
            if (words.length && pastHits) past.open = true;
        }
        none.hidden = !(words.length && shown === 0);

        if (input.value) params.set('q', input.value); else params.delete('q');
        var qs = params.toString();
        history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }
    function setOpen(open) {
        box.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        input.tabIndex = open ? 0 : -1;
        if (open) input.focus();
    }
    toggle.addEventListener('click', function () {
        if (!box.classList.contains('open')) setOpen(true);
        else if (!input.value) setOpen(false);
        else input.focus();
    });
    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { input.value = ''; apply(); setOpen(false); toggle.focus(); }
    });
    input.addEventListener('blur', function () {
        if (!input.value) setTimeout(function () {
            if (document.activeElement !== toggle) setOpen(false);
        }, 150);
    });
    input.addEventListener('input', apply);
    if (input.value) { box.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); input.tabIndex = 0; apply(); }
})();

// Month bar: highlight the month currently on screen.
(function () {
    var chips = document.querySelectorAll('.month-chip');
    if (!chips.length || !('IntersectionObserver' in window)) return;
    var visible = {};
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { visible[en.target.getAttribute('data-month')] = en.isIntersecting; });
        var first = null;
        Array.prototype.forEach.call(chips, function (c) {
            if (!first && visible[c.getAttribute('data-month')]) first = c;
        });
        Array.prototype.forEach.call(chips, function (c) { c.classList.toggle('current', c === first); });
        if (first && first.scrollIntoView && first.parentNode.scrollWidth > first.parentNode.clientWidth) {
            first.parentNode.scrollLeft = first.offsetLeft - 16;
        }
    }, { rootMargin: '-60px 0px -60% 0px' });
    Array.prototype.forEach.call(document.querySelectorAll('main > section[data-month]'), function (s) { io.observe(s); });
})();
</script>
</body>
</html>
