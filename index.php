<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

[$events, $problems, $error] = load_events();

$allTags = all_tags($events);
$activeTag = requested_tag($allTags);
$events = filter_by_tag($events, $activeTag);

$today = new DateTimeImmutable('today');
// An event stays "upcoming" until its last day has passed
$upcoming = array_values(array_filter($events, fn($ev) => !is_past($ev, $today)));
$past     = array_reverse(array_values(array_filter($events, fn($ev) => is_past($ev, $today))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HPS Talks Utrecht</title>
    <?php head_assets(); ?>
</head>
<body>
<main>
    <?php site_header($allTags, $activeTag); ?>

    <p class="empty" id="no-results" hidden>No events match your search.</p>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php elseif (!$upcoming): ?>
        <p class="empty"><?= $activeTag !== null ? 'No upcoming ' . e($activeTag) . ' events at the moment.' : 'No upcoming events at the moment.' ?></p>
    <?php else: ?>
        <div id="upcoming"><?php render_months($upcoming, $today); ?></div>
    <?php endif; ?>

    <?php if ($past): ?>
        <details id="past" class="past">
            <summary>Past events (<?= count($past) ?>)</summary>
            <?php render_months($past, $today, true); ?>
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

</main>
<script>
// Instant search: hides rows (and empty months) that don't match. Every word
// must appear somewhere in the event; ?q=... is kept in the URL.
(function () {
    var input = document.getElementById('q');
    var rows = Array.prototype.slice.call(document.querySelectorAll('details.event'));
    var months = Array.prototype.slice.call(document.querySelectorAll('section.month'));
    var past = document.getElementById('past');
    var none = document.getElementById('no-results');
    if (!input || !rows.length) return;
    input.hidden = false;

    var params = new URLSearchParams(location.search);
    input.value = params.get('q') || '';

    function norm(s) {
        return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    rows.forEach(function (r) { r._text = norm(r.getAttribute('data-search') || ''); });

    function apply() {
        var words = norm(input.value).split(/\s+/).filter(Boolean);
        var shown = 0;
        rows.forEach(function (r) {
            var hit = words.every(function (w) { return r._text.indexOf(w) !== -1; });
            r.hidden = !hit;
            if (hit) shown++;
        });
        months.forEach(function (m) {
            m.hidden = !m.querySelector('details.event:not([hidden])');
            // The date is written once per day: on the first row still visible
            var prev = null;
            Array.prototype.forEach.call(m.querySelectorAll('details.event:not([hidden])'), function (r) {
                var day = r.getAttribute('data-day');
                r.querySelector('.day').classList.toggle('same-day', day === prev);
                prev = day;
            });
        });
        if (past) {
            var pastHits = past.querySelectorAll('details.event:not([hidden])').length;
            past.hidden = pastHits === 0;
            if (words.length && pastHits) past.open = true;
        }
        none.hidden = !(words.length && shown === 0);

        if (input.value) params.set('q', input.value); else params.delete('q');
        var qs = params.toString();
        history.replaceState(null, '', (qs ? '?' + qs : location.pathname) + location.hash);
    }
    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { input.value = ''; apply(); input.blur(); }
    });
    input.addEventListener('input', apply);
    if (input.value) apply();
})();

// Filters menu
(function () {
    var toggle = document.getElementById('filter-toggle');
    var panel = document.getElementById('filter-panel');
    if (!toggle || !panel) return;
    toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;
    });
})();

// A link to #<event id> opens that event
(function () {
    function openHash() {
        var el = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (!el || el.tagName !== 'DETAILS') return;
        el.open = true;
        var past = el.closest('#past');
        if (past) past.open = true;
        el.scrollIntoView({ block: 'center' });
    }
    window.addEventListener('hashchange', openHash);
    openHash();
})();
</script>
</body>
</html>
