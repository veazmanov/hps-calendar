<?php
declare(strict_types=1);

// How to subscribe to the calendar feed (calendar.php).
// subscribe.php?tag=UPAC subscribes to one topic only.

require __DIR__ . '/lib.php';

$tag = isset($_GET['tag']) && is_string($_GET['tag']) && trim($_GET['tag']) !== '' ? trim($_GET['tag']) : null;
$feed = feed_url($tag);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscribe in your calendar · HPS Talks Utrecht</title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body>
<main>
    <header>
        <p><a class="back" href="<?= e(tag_url($tag)) ?>">← Back to all talks</a></p>
        <h1>Subscribe in your calendar</h1>
        <p>Get <?= $tag !== null ? 'the ' . e($tag) . ' talks' : 'all talks' ?> in your own calendar. New and changed events show up automatically.</p>
    </header>

    <div class="notice">
        <p><strong>Using Thunderbird, Google Calendar or Outlook on the web?</strong> Subscribe to the calendar with this address:</p>
        <p class="feed-url"><input id="feed" type="text" readonly value="https:<?= e($feed) ?>" aria-label="Calendar address" onfocus="this.select()"></p>
        <p class="share"><button type="button" class="button" id="copy-feed" hidden>Copy address</button></p>
        <ul class="steps">
            <li><strong>Thunderbird:</strong> New Calendar → On the Network → paste the address.</li>
            <li><strong>Google Calendar:</strong> Other calendars → + → From URL → paste the address.</li>
            <li><strong>Outlook on the web:</strong> Add calendar → Subscribe from web → paste the address.</li>
        </ul>
    </div>

    <div class="notice">
        <p><strong>Apple Calendar, Outlook on your computer, or a phone?</strong> This should open your calendar app directly:</p>
        <p class="share"><a class="button" href="webcal:<?= e($feed) ?>">Open in calendar app</a></p>
    </div>
</main>
<script>
(function () {
    var btn = document.getElementById('copy-feed');
    var input = document.getElementById('feed');
    if (!btn || !navigator.clipboard) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(input.value).then(function () {
            btn.textContent = 'Address copied';
            setTimeout(function () { btn.textContent = 'Copy address'; }, 2000);
        });
    });
})();
</script>
</body>
</html>
