<?php
declare(strict_types=1);

// One event on its own page: event.php?id=<file name without .json>,
// e.g. event.php?id=2026-10-20-descartes-colloquium

require __DIR__ . '/lib.php';

[$events] = load_events();

$event = find_event($events, query_param('id'));

$today = new DateTimeImmutable('today');

if ($event === null) {
    http_response_code(404);
}

// Short summary for the page description and link previews (Signal, Mastodon, …)
$summary = '';
if ($event !== null) {
    $when = date_range_text($event['_date'], $event['_end'])
        . (!empty($event['time-start']) ? ', ' . time_text($event) : '');
    $summary = implode(' · ', array_filter([
        $when,
        $event['speaker'] ?? null,
        $event['location'] ?? null,
    ], fn($v) => is_string($v) && $v !== ''));
}
// No og:url/canonical without a Host header, rather than guessing the address
$selfUrl = $event !== null && !empty($_SERVER['HTTP_HOST']) ? site_url(event_url($event)) : null;

$meta = '';
if ($event !== null) {
    $meta = '<meta name="description" content="' . e($summary) . '">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="HPS Extracurricular Agenda">
    <meta property="og:title" content="' . e($event['title']) . '">
    <meta property="og:description" content="' . e($summary) . '">';
    if ($selfUrl) {
        $meta .= '
    <meta property="og:url" content="' . e($selfUrl) . '">
    <link rel="canonical" href="' . e($selfUrl) . '">';
    }
}
page_start($event !== null ? (string) $event['title'] : 'Event not found', $meta);
?>
    <header><?php page_header(); ?></header>

    <?php if ($event === null): ?>
        <div class="notice">
            <p>This event doesn't exist (any more). It may have been renamed or removed.</p>
            <p><a href="./">See all talks</a></p>
        </div>
    <?php else: ?>
        <?php render_event($event, $today, is_past($event, $today)); ?>
        <p class="share">
            <?php // A file to open, or drag into a calendar app, for just this event ?>
            <a class="button" href="calendar.php/<?= e(rawurlencode($event['_id'])) ?>.ics?id=<?= e(rawurlencode($event['_id'])) ?>" download="<?= e($event['_id']) ?>.ics">Add to calendar (.ics)</a>
            <button type="button" class="button" id="copy-link" hidden>Copy link</button>
            <a class="button" href="correction.php">Submit a correction</a>
            <button type="button" class="button" onclick="window.print()">Print this page</button>
        </p>
    <?php endif; ?>

<script>
(function () {
    var btn = document.getElementById('copy-link');
    if (!btn || !navigator.clipboard) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(location.href).then(function () {
            btn.textContent = 'Link copied';
            setTimeout(function () { btn.textContent = 'Copy link'; }, 2000);
        });
    });
})();
</script>
<?php page_end(); ?>
