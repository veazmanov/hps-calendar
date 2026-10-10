<?php
declare(strict_types=1);

// One week as a time grid: week.php?w=2026-W42 (ISO week, default: this week),
// plus ?tag=... as on the list. Timed events are blocks in the grid; untimed and
// multi-day events go in the all-day strip above it. Below 640px the grid is
// swapped for a plain day-by-day list (both are in the HTML; see style.css).

require __DIR__ . '/lib.php';

[$events, , $error] = load_events();
$allTags = all_tags($events);
$activeTag = requested_tag($allTags);
$events = filter_by_tag($events, $activeTag);

$today = new DateTimeImmutable('today');

/** Monday of an ISO week like "2026-W42", or null if it isn't one. */
function parse_week(string $w): ?DateTimeImmutable
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $w, $m)) {
        return null;
    }
    $monday = (new DateTimeImmutable('today'))->setISODate((int) $m[1], (int) $m[2]);
    // Rejects W00, and W53 in years that only have 52 weeks
    return $monday->format('o-\WW') === $w ? $monday : null;
}

/** "15:30" → minutes since midnight, or null. */
function minutes(mixed $hm): ?int
{
    if (!is_string($hm) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m) || $m[1] > 23 || $m[2] > 59) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/** "12–16 October 2026", "28 September – 2 October 2026", "28 December 2026 – 1 January 2027" */
function week_range_text(DateTimeImmutable $a, DateTimeImmutable $b): string
{
    return match (true) {
        $a->format('Y') !== $b->format('Y') => $a->format('j F Y') . ' – ' . $b->format('j F Y'),
        $a->format('m') !== $b->format('m') => $a->format('j F') . ' – ' . $b->format('j F Y'),
        default => $a->format('j') . '–' . $b->format('j F Y'),
    };
}

/**
 * Side-by-side columns for one day's blocks: events that overlap form a
 * cluster, each takes the first free column, and all blocks in a cluster
 * share its width. Adds 'col' and 'cols' to each block.
 */
function layout_day(array $blocks): array
{
    usort($blocks, fn($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
    $out = [];
    $cluster = [];
    $colEnds = [];      // per column: when its last block ends
    $clusterEnd = 0;
    foreach ($blocks as $b) {
        if ($cluster && $b['start'] >= $clusterEnd) {
            foreach ($cluster as $c) $out[] = $c + ['cols' => count($colEnds)];
            $cluster = [];
            $colEnds = [];
        }
        $col = 0;
        while (isset($colEnds[$col]) && $colEnds[$col] > $b['start']) {
            $col++;
        }
        $colEnds[$col] = $b['end'];
        $clusterEnd = $cluster ? max($clusterEnd, $b['end']) : $b['end'];
        $cluster[] = $b + ['col' => $col];
    }
    foreach ($cluster as $c) $out[] = $c + ['cols' => count($colEnds)];
    return $out;
}

function pct(float $v): string
{
    return sprintf('%.3F%%', $v);   // %F ignores the locale, so never "12,5%"
}

$wParam = isset($_GET['w']) && is_string($_GET['w']) ? $_GET['w'] : '';
$monday = parse_week($wParam) ?? $today->setISODate((int) $today->format('o'), (int) $today->format('W'));
$sunday = $monday->modify('+6 days');
$week = $monday->format('o-\WW');
$dayIndex = fn(DateTimeImmutable $d) => max(0, min(6, (int) $monday->diff($d)->format('%r%a')));

// ---- This week's events -----------------------------------------------------
$weekEvents = [];   // everything, in list order, for the narrow-screen list
$timed = [];        // day index => blocks
$allDay = [];
$lastDay = 4;       // Friday; grows if something happens at the weekend
foreach ($events as $ev) {
    if ($ev['_date'] > $sunday || $ev['_end'] < $monday) {
        continue;
    }
    $weekEvents[] = $ev;
    $start = minutes($ev['time-start'] ?? null);
    if ($ev['_end'] == $ev['_date'] && $start !== null) {
        $end = minutes($ev['time-end'] ?? null);
        if ($end === null || $end <= $start) {
            $end = $start + 60;   // no (usable) end time: assume an hour
        }
        $day = $dayIndex($ev['_date']);
        $timed[$day][] = ['ev' => $ev, 'start' => $start, 'end' => min($end, 24 * 60)];
        $lastDay = max($lastDay, $day);
    } else {
        $allDay[] = $ev;
        $lastDay = max($lastDay, $dayIndex($ev['_end']));
    }
}

// Hours shown: 13:00–18:00, widened to whole hours around this week's events
$from = 13 * 60;
$to = 18 * 60;
foreach ($timed as $day => $blocks) {
    foreach ($blocks as $b) {
        $from = min($from, intdiv($b['start'], 60) * 60);
        $to = max($to, (int) ceil($b['end'] / 60) * 60);
    }
    $timed[$day] = layout_day($blocks);
}
$hours = intdiv($to - $from, 60);

$days = [];
for ($i = 0; $i <= $lastDay; $i++) {
    $days[$i] = $monday->modify("+$i days");
}
$tagLink = fn(?string $t) => week_url($week, $t);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week <?= e($monday->format('W')) ?> · HPS Talks Utrecht</title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body>
<main class="wide">
    <?php site_header($allTags, $activeTag, 'week', $tagLink); ?>

    <nav class="week-nav" aria-label="Weeks">
        <a class="button" href="<?= e(week_url($monday->modify('-7 days')->format('o-\WW'), $activeTag)) ?>">← Previous week</a>
        <a class="button" href="<?= e(week_url(null, $activeTag)) ?>">This week</a>
        <a class="button" href="<?= e(week_url($monday->modify('+7 days')->format('o-\WW'), $activeTag)) ?>">Next week →</a>
        <a class="subscribe-link" href="subscribe.php<?= $activeTag !== null ? '?tag=' . e(urlencode($activeTag)) : '' ?>">Subscribe in your calendar</a>
    </nav>
    <h2 class="week-title">Week <?= e($monday->format('W')) ?> · <?= e(week_range_text($monday, $days[$lastDay])) ?></h2>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php else: ?>
        <?php if (!$weekEvents): ?>
            <p class="empty"><?= $activeTag !== null ? 'No ' . e($activeTag) . ' events this week.' : 'No events this week.' ?></p>
        <?php endif; ?>
        <div class="week-grid" style="--days: <?= count($days) ?>; --hours: <?= $hours ?>">
            <div class="wk-corner"></div>
            <?php foreach ($days as $d): ?>
                <div class="wk-head<?= $d == $today ? ' today' : '' ?>"><?= e($d->format('D')) ?> <b><?= e($d->format('j')) ?></b></div>
            <?php endforeach; ?>

            <?php if ($allDay): ?>
                <div class="wk-allday-strip">
                    <div class="wk-allday-label" style="grid-row: 1 / span <?= count($allDay) ?>">All day</div>
                    <?php foreach ($allDay as $i => $ev):
                        $s = $dayIndex($ev['_date']);
                        $n = min($dayIndex($ev['_end']), $lastDay) - $s + 1; ?>
                        <a class="wk-allday" href="<?= e(event_url($ev)) ?>" style="grid-row: <?= $i + 1 ?>; grid-column: <?= $s + 2 ?> / span <?= $n ?>" title="<?= e($ev['title'] . ' · ' . date_range_text($ev['_date'], $ev['_end'])) ?>"><?= e($ev['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="wk-times">
                <?php for ($h = 0; $h < $hours; $h++): ?>
                    <span style="top: <?= pct($h / $hours * 100) ?>"><?= sprintf('%02d:00', intdiv($from, 60) + $h) ?></span>
                <?php endfor; ?>
            </div>
            <?php foreach ($days as $i => $d): ?>
                <div class="wk-col<?= $d == $today ? ' today' : '' ?>">
                    <?php foreach ($timed[$i] ?? [] as $b):
                        $ev = $b['ev'];
                        $span = $to - $from;
                        $time = $ev['time-start'] . (minutes($ev['time-end'] ?? null) !== null ? '–' . $ev['time-end'] : '');
                        $place = trim((string) ($ev['location'] ?? ''));
                        $style = 'top: ' . pct(($b['start'] - $from) / $span * 100)
                            . '; height: ' . pct(($b['end'] - $b['start']) / $span * 100)
                            . '; left: ' . pct($b['col'] / $b['cols'] * 100)
                            . '; width: ' . pct(100 / $b['cols']);
                        // Shorter blocks show less, so text never gets cut off halfway a line
                        $size = match (true) { $b['end'] - $b['start'] < 45 => ' tiny', $b['end'] - $b['start'] <= 60 => ' short', default => '' }; ?>
                        <div class="wk-ev<?= $size ?>" style="<?= e($style) ?>" title="<?= e(implode(' · ', array_filter([$time, $ev['title'], $place]))) ?>">
                            <time><?= e($time) ?></time>
                            <a href="<?= e(event_url($ev)) ?>"><?= e($ev['title']) ?></a>
                            <?php if ($place !== ''): ?><span class="wk-loc"><?= e($place) ?></span><?php endif; ?>
                            <?php foreach (event_tags($ev) as $t): ?>
                                <a class="tag" href="<?= e(week_url($week, $t)) ?>"><?= e($t) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="week-list">
            <?php render_day_groups($weekEvents, $today); ?>
        </div>
    <?php endif; ?>

    <?php site_footer(); ?>
</main>
</body>
</html>
