<?php
declare(strict_types=1);

// One event on its own page: event.php?id=<file name without .json>,
// e.g. event.php?id=2026-10-20-descartes-colloquium

require __DIR__ . '/lib.php';

[$events] = load_events();

$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$event = null;
foreach ($events as $ev) {
    if ($ev['_id'] === $id) {
        $event = $ev;
        break;
    }
}

$today = new DateTimeImmutable('today');

if ($event === null) {
    http_response_code(404);
}

// Short summary for the page description and link previews (Signal, Mastodon, …)
$summary = '';
if ($event !== null) {
    $when = date_range_text($event['_date'], $event['_end'])
        . (!empty($event['time-start']) ? ', ' . $event['time-start'] . (!empty($event['time-end']) ? '–' . $event['time-end'] : '') : '');
    $summary = implode(' · ', array_filter([
        $when,
        $event['speaker'] ?? null,
        $event['location'] ?? null,
    ], fn($v) => is_string($v) && $v !== ''));
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$selfUrl = $event !== null && !empty($_SERVER['HTTP_HOST'])
    ? $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?id=' . rawurlencode($event['_id'])
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $event !== null ? e($event['title']) . ' · ' : 'Event not found · ' ?>HPS Talks Utrecht</title>
    <?php if ($event !== null): ?>
        <meta name="description" content="<?= e($summary) ?>">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="HPS Talks Utrecht">
        <meta property="og:title" content="<?= e($event['title']) ?>">
        <meta property="og:description" content="<?= e($summary) ?>">
        <?php if ($selfUrl): ?>
            <meta property="og:url" content="<?= e($selfUrl) ?>">
            <link rel="canonical" href="<?= e($selfUrl) ?>">
        <?php endif; ?>
    <?php endif; ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body>
<main>
    <header>
        <p><a class="back" href="./">← All talks</a></p>
    </header>

    <?php if ($event === null): ?>
        <div class="notice">
            <p>This event doesn't exist (any more). It may have been renamed or removed.</p>
            <p><a href="./">See all talks</a></p>
        </div>
    <?php else: ?>
        <?php render_event($event, $today, is_past($event, $today), true); ?>
        <p class="share">
            <button type="button" class="button" id="copy-link" hidden>Copy link</button>
            <a class="button" href="correction.php">Submit a correction</a>
        </p>
    <?php endif; ?>

    <footer>
        Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>
        · <a href="https://github.com/victorelgersma/hps-calendar" target="_blank" rel="noopener">Source on GitHub</a>
    </footer>
</main>
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
</body>
</html>
