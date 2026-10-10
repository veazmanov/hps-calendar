<?php
declare(strict_types=1);

// Shared by index.php (overview) and event.php (one event per page).

date_default_timezone_set('Europe/Amsterdam');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function safe_url(?string $url): ?string
{
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}

/**
 * Loads every events/*.json file. Each event gets:
 *   _id   the file name without .json (used in event.php?id=...)
 *   _date / _end   DateTimeImmutable start and last day
 * A broken file is skipped and reported in $problems instead of
 * taking the whole calendar down.
 *
 * @return array{0: array, 1: array, 2: ?string} [events, problems, error]
 */
function load_events(): array
{
    $error = null;
    $events = [];
    $problems = [];

    if (!is_dir(__DIR__ . '/events')) {
        return [[], [], 'The events/ folder is missing.'];
    }

    foreach (glob(__DIR__ . '/events/*.json') ?: [] as $file) {
        $name = basename($file);
        $ev = json_decode((string) @file_get_contents($file), true);

        if (!is_array($ev)) {
            $problems[] = "$name: not valid JSON (" . json_last_error_msg() . ')';
            continue;
        }
        if (empty($ev['date']) || empty($ev['title'])) {
            $problems[] = "$name: needs at least a \"date\" and a \"title\"";
            continue;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ev['date']);
        if (!$date || $date->format('Y-m-d') !== $ev['date']) {
            $problems[] = "$name: \"date\" must look like 2026-09-25";
            continue;
        }
        // Optional last day for multi-day events (conferences, workshops)
        $end = !empty($ev['date-end'])
            ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ev['date-end'])
            : false;
        if (!$end || $end < $date) {
            $end = $date;
        }
        $ev['_id']   = basename($name, '.json');
        $ev['_date'] = $date;
        $ev['_end']  = $end;
        $ev['_sort'] = $date->format('Y-m-d') . ' ' . ($ev['time-start'] ?? '00:00') . ' ' . $name;
        $events[] = $ev;
    }

    usort($events, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));
    return [$events, $problems, $error];
}

function event_url(array $ev): string
{
    return 'event.php?id=' . rawurlencode($ev['_id']);
}

// Events may carry "tags": ["Philosophy of Physics", ...]; ?tag=... filters.
function event_tags(array $ev): array
{
    return array_values(array_filter(array_map(
        fn($t) => trim((string) $t),
        is_array($ev['tags'] ?? null) ? $ev['tags'] : []
    ), fn($t) => $t !== ''));
}

function tag_url(?string $tag): string
{
    return $tag === null ? './' : './?tag=' . urlencode($tag);
}

/** Every tag in use, keyed by its lower-case form; the first spelling wins. */
function all_tags(array $events): array
{
    $tags = [];
    foreach ($events as $ev) {
        foreach (event_tags($ev) as $t) {
            $tags[mb_strtolower($t)] ??= $t;
        }
    }
    natcasesort($tags);
    return $tags;
}

/** The tag asked for with ?tag=..., in its usual spelling, or null if there is no such tag. */
function requested_tag(array $allTags): ?string
{
    $requested = isset($_GET['tag']) && is_string($_GET['tag']) ? mb_strtolower(trim($_GET['tag'])) : '';
    return $allTags[$requested] ?? null;
}

function filter_by_tag(array $events, ?string $tag): array
{
    if ($tag === null) {
        return $events;
    }
    return array_values(array_filter($events, fn($ev) => in_array(
        mb_strtolower($tag),
        array_map('mb_strtolower', event_tags($ev)),
        true
    )));
}

/**
 * Address of the calendar feed (calendar.php), without the scheme, so it can
 * be prefixed with https: or webcal: (which opens the calendar app).
 * The trailing file name is ignored by calendar.php, but Thunderbird uses it
 * as the calendar's default name (it ignores X-WR-CALNAME).
 */
function feed_url(?string $tag): string
{
    return '//' . ($_SERVER['HTTP_HOST'] ?? 'hps-calendar.vjbe.net')
        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/')
        . '/calendar.php/hps-talks-utrecht.ics'
        . ($tag !== null ? '?tag=' . urlencode($tag) : '');
}

function is_past(array $ev, DateTimeImmutable $today): bool
{
    return $ev['_end'] < $today;
}

function badge(DateTimeImmutable $date, DateTimeImmutable $end, DateTimeImmutable $today): ?string
{
    $days = (int) $today->diff($date)->format('%r%a');
    return match (true) {
        $days < 0 && $end >= $today => 'Ongoing',
        $days === 0 => 'Today',
        $days === 1 => 'Tomorrow',
        $days > 1 && $days < 7 => 'This week',
        default => null,
    };
}

/**
 * Human-readable date line, e.g.
 *   "Friday 25 September 2026"
 *   "Wednesday 25 – Friday 27 August 2027"
 *   "Wednesday 30 September – Friday 2 October 2026"
 *   "Wednesday 30 December 2026 – Friday 1 January 2027"
 */
function date_range_text(DateTimeImmutable $d, DateTimeImmutable $end): string
{
    if ($d == $end) {
        return $d->format('l j F Y');
    }
    if ($d->format('Y') !== $end->format('Y')) {
        return $d->format('l j F Y') . ' – ' . $end->format('l j F Y');
    }
    if ($d->format('m') !== $end->format('m')) {
        return $d->format('l j F') . ' – ' . $end->format('l j F Y');
    }
    return $d->format('l j') . ' – ' . $end->format('l j F Y');
}

/**
 * One event card. On the overview the title links to the event's own page;
 * on that page ($single = true) the title is the page heading instead.
 */
function render_event(array $ev, DateTimeImmutable $today, bool $isPast = false, bool $single = false): void
{
    $d = $ev['_date'];
    $end = $ev['_end'];
    $time = trim(($ev['time-start'] ?? '') . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : ''));
    $badge = $isPast ? null : badge($d, $end, $today);
    $city = trim((string) ($ev['city'] ?? ''));
    $away = ($city !== '' && mb_strtolower($city) !== 'utrecht') ? $city : null;
    $haystack = event_haystack($ev); ?>
    <article class="event<?= $isPast ? ' past' : '' ?><?= $single ? ' single' : '' ?>" id="<?= e($ev['_id']) ?>" data-search="<?= e($haystack) ?>">
        <?php date_block($d, $end); ?>
        <div class="body">
            <?php $tags = event_tags($ev); if ($badge || $away || $tags || ($single && $isPast)): ?>
                <div class="labels">
                    <?php if ($single && $isPast): ?><span class="badge">Past event</span><?php endif; ?>
                    <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
                    <?php if ($away): ?><span class="badge away">In <?= e($away) ?></span><?php endif; ?>
                    <?php foreach ($tags as $t): ?>
                        <a class="tag" href="<?= e(tag_url($t)) ?>"><?= e($t) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($single): ?>
                <h1><?= e($ev['title']) ?></h1>
            <?php else: ?>
                <h3><a class="title-link" href="<?= e(event_url($ev)) ?>"><?= e($ev['title']) ?></a></h3>
            <?php endif; ?>
            <p class="meta">
                <time datetime="<?= e($d->format('Y-m-d') . (!empty($ev['time-start']) ? 'T' . $ev['time-start'] : '')) ?>">
                    <?= e(date_range_text($d, $end)) ?><?= $time !== '' ? ' · ' . e($time) : '' ?>
                </time>
                <?php if (!empty($ev['location'])): ?> · <?= e($ev['location']) ?><?php endif; ?>
            </p>
            <?php if (!empty($ev['speaker'])): ?><p class="speaker"><?= e($ev['speaker']) ?></p><?php endif; ?>
            <?php if (!empty($ev['reading'])): ?><p class="reading"><span>Reading:</span> <?= e($ev['reading']) ?></p><?php endif; ?>
            <?php if (!empty($ev['description'])): ?><p class="desc"><?= nl2br(e($ev['description'])) ?></p><?php endif; ?>
            <?php
            $links = [];
            foreach ((array) ($ev['attachments'] ?? []) as $att) {
                $url = safe_url($att['URL'] ?? $att['url'] ?? null);
                if ($url) {
                    $links[] = ['title' => $att['title'] ?? 'Link', 'url' => $url];
                }
            }
            if ($links): ?>
                <ul class="attachments">
                    <?php foreach ($links as $l): ?>
                        <li><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e($l['title']) ?> ↗</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

// Everything a visitor might search for, lower-cased, for the search bar.
// The overview only shows a summary, but search still covers the full text.
function event_haystack(array $ev): string
{
    return mb_strtolower(implode(' ', array_filter([
        $ev['title'] ?? '', $ev['speaker'] ?? '', $ev['location'] ?? '', $ev['city'] ?? '',
        $ev['reading'] ?? '', $ev['description'] ?? '',
        implode(' ', event_tags($ev)), date_range_text($ev['_date'], $ev['_end']),
    ], 'is_string')));
}

/** The tear-off-calendar date: "TUE / 13 / OCT", or a range for multi-day events. */
function date_block(DateTimeImmutable $d, DateTimeImmutable $end): void
{
    $multi = $end != $d;
    $sameMonth = $d->format('Y-m') === $end->format('Y-m'); ?>
    <div class="date<?= $multi ? ' range' : '' ?>">
        <?php if ($multi): ?>
            <span class="dow"><?= e($d->format('D') . '–' . $end->format('D')) ?></span>
            <span class="day"><?= e($d->format('j') . '–' . $end->format('j')) ?></span>
            <span class="mon"><?= e($sameMonth ? $d->format('M') : $d->format('M') . '–' . $end->format('M')) ?></span>
        <?php else: ?>
            <span class="dow"><?= e($d->format('D')) ?></span>
            <span class="day"><?= e($d->format('j')) ?></span>
            <span class="mon"><?= e($d->format('M')) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Overview listing: events that start on the same day share one card with a
 * single date block, and each event is one compact row (time, title,
 * speaker/place, tags). Descriptions, readings and links live on event.php.
 */
function render_day_groups(array $events, DateTimeImmutable $today, bool $isPast = false): void
{
    $groups = [];
    foreach ($events as $ev) {
        $groups[$ev['_date']->format('Y-m-d')][] = $ev;
    }
    foreach ($groups as $list):
        $d = $list[0]['_date'];
        // A day holding only one multi-day event gets the range as its date block
        $end = count($list) === 1 ? $list[0]['_end'] : $d; ?>
        <div class="day-group<?= $isPast ? ' past' : '' ?>">
            <?php date_block($d, $end); ?>
            <div class="rows">
                <?php foreach ($list as $ev) render_event_row($ev, $today, $isPast); ?>
            </div>
        </div>
    <?php endforeach;
}

function render_event_row(array $ev, DateTimeImmutable $today, bool $isPast = false): void
{
    $d = $ev['_date'];
    $end = $ev['_end'];
    $time = (string) ($ev['time-start'] ?? '');
    // Multi-day events say when they end, e.g. "until Sat 31 Oct"
    $until = $end != $d ? 'until ' . $end->format('D j M') : null;
    $badge = $isPast ? null : badge($d, $end, $today);
    $city = trim((string) ($ev['city'] ?? ''));
    $away = ($city !== '' && mb_strtolower($city) !== 'utrecht') ? $city : null;
    $tags = event_tags($ev);
    $sub = implode(' · ', array_filter([$ev['speaker'] ?? null, $ev['location'] ?? null],
        fn($v) => is_string($v) && trim($v) !== '')); ?>
    <article class="event row" id="<?= e($ev['_id']) ?>" data-search="<?= e(event_haystack($ev)) ?>">
        <div class="when">
            <?php if ($time !== ''): ?>
                <time datetime="<?= e($d->format('Y-m-d') . (!empty($ev['time-start']) ? 'T' . $ev['time-start'] : '')) ?>"><?= e($time) ?></time>
            <?php endif; ?>
        </div>
        <div class="what">
            <h3><a class="title-link" href="<?= e(event_url($ev)) ?>"><?= e($ev['title']) ?></a></h3>
            <?php if ($sub !== '' || $until): ?>
                <p class="sub" title="<?= e($sub) ?>"><?php if ($until): ?><span class="until"><?= e($until) ?></span><?= $sub !== '' ? ' · ' : '' ?><?php endif; ?><?= e($sub) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($badge || $away || $tags): ?>
            <div class="labels">
                <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
                <?php if ($away): ?><span class="badge away">In <?= e($away) ?></span><?php endif; ?>
                <?php foreach ($tags as $t): ?>
                    <a class="tag" href="<?= e(tag_url($t)) ?>"><?= e($t) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

/** Site header: title, intro, disclaimer, buttons, search box and tag filters. */
function site_header(array $allTags, ?string $activeTag): void
{ ?>
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
    <?php
}

function site_footer(): void
{ ?>
    <footer>
        Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>
        · <a href="https://github.com/victorelgersma/hps-calendar" target="_blank" rel="noopener">Source on GitHub</a>
    </footer>
    <?php
}
