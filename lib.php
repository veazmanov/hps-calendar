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

/** "Amsterdam" for an event outside Utrecht, otherwise null. */
function away_city(array $ev): ?string
{
    $city = trim((string) ($ev['city'] ?? ''));
    return ($city !== '' && mb_strtolower($city) !== 'utrecht') ? $city : null;
}

function time_text(array $ev): string
{
    return trim(($ev['time-start'] ?? '') . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : ''));
}

/**
 * Everything about an event except its title: when and where, speaker,
 * reading, description, links and tags. Shown when a row on the overview is
 * opened, and on the event's own page.
 */
function event_details(array $ev): void
{
    $time = time_text($ev);
    $links = [];
    foreach ((array) ($ev['attachments'] ?? []) as $att) {
        $url = safe_url($att['URL'] ?? $att['url'] ?? null);
        if ($url) {
            $links[] = ['title' => $att['title'] ?? 'Link', 'url' => $url];
        }
    }
    $tags = event_tags($ev); ?>
    <p class="meta">
        <time datetime="<?= e($ev['_date']->format('Y-m-d') . (!empty($ev['time-start']) ? 'T' . $ev['time-start'] : '')) ?>"><?= e(date_range_text($ev['_date'], $ev['_end'])) ?><?= $time !== '' ? ', ' . e($time) : '' ?></time>
        <?php if (!empty($ev['location'])): ?><br><?= e($ev['location']) ?><?php endif; ?>
        <?php if ($away = away_city($ev)): ?><?= !empty($ev['location']) ? ', ' : '<br>' ?><?= e($away) ?><?php endif; ?>
    </p>
    <?php if (!empty($ev['speaker'])): ?><p class="speaker"><?= e($ev['speaker']) ?></p><?php endif; ?>
    <?php if (!empty($ev['reading'])): ?><p class="reading">Reading: <em><?= e($ev['reading']) ?></em></p><?php endif; ?>
    <?php if (!empty($ev['description'])): ?><p class="desc"><?= nl2br(e($ev['description'])) ?></p><?php endif; ?>
    <?php if ($links): ?>
        <p class="links">
            <?php foreach ($links as $i => $l): ?><?= $i ? ' · ' : '' ?><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e($l['title']) ?> ↗</a><?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php if ($tags): ?>
        <?php // Each tag links to the overview filtered on that topic ?>
        <p class="tags">
            <?php foreach ($tags as $t): ?>
                <a class="tag" href="<?= e(tag_url($t)) ?>" title="Show only <?= e($t) ?> talks"><?= e($t) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif;
}

/** The event's own page (event.php). */
function render_event(array $ev, DateTimeImmutable $today, bool $isPast = false): void
{ ?>
    <article class="event single" id="<?= e($ev['_id']) ?>">
        <?php if ($isPast): ?><p class="note">Past event</p><?php endif; ?>
        <h1><?= e($ev['title']) ?></h1>
        <?php event_details($ev); ?>
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

/**
 * Overview listing: a heading per month and one line per event (date, time,
 * title). Clicking a line opens the rest (event_details). Past events are
 * listed newest first, so their months run backwards.
 */
function render_months(array $events, DateTimeImmutable $today, bool $isPast = false): void
{
    $byMonth = [];
    foreach ($events as $ev) {
        $byMonth[$ev['_date']->format('Y-m')][] = $ev;
    }
    foreach ($byMonth as $key => $list): ?>
        <section class="month" id="<?= $isPast ? 'past-' : 'm-' ?><?= e($key) ?>">
            <h2><?= e($list[0]['_date']->format('F Y')) ?></h2>
            <?php $prevDay = null;
            foreach ($list as $ev) {
                $day = $ev['_date']->format('Y-m-d');
                render_event_row($ev, $today, $isPast, $day === $prevDay);
                $prevDay = $day;
            } ?>
        </section>
    <?php endforeach;
}

function render_event_row(array $ev, DateTimeImmutable $today, bool $isPast, bool $sameDay): void
{
    $d = $ev['_date'];
    $end = $ev['_end'];
    $notes = array_filter([
        $isPast ? null : badge($d, $end, $today),
        $end != $d ? 'until ' . $end->format('D j M') : null,
        away_city($ev),
    ]); ?>
    <details class="event" id="<?= e($ev['_id']) ?>" data-day="<?= e($d->format('Y-m-d')) ?>" data-search="<?= e(event_haystack($ev)) ?>">
        <summary>
            <span class="day<?= $sameDay ? ' same-day' : '' ?>"><?= e($d->format('D j')) ?></span>
            <span class="time"><?= e((string) ($ev['time-start'] ?? '')) ?></span>
            <span class="title"><?= e($ev['title']) ?><?php foreach ($notes as $n): ?> <span class="note"><?= e($n) ?></span><?php endforeach; ?></span>
        </summary>
        <div class="more">
            <?php event_details($ev); ?>
            <p class="permalink"><a href="<?= e(event_url($ev)) ?>">Event page →</a></p>
        </div>
    </details>
    <?php
}

/** Stylesheet, body font and the saved light/dark choice; goes in every <head>. */
function head_assets(): void
{ ?>
    <script>try { var t = localStorage.getItem('hps-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}</script>
    <link rel="preload" href="fonts/et-book-roman-line-figures.woff" as="font" type="font/woff" crossorigin>
    <?php /* ?v= changes whenever style.css changes, so browsers never use a stale copy */ ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <?php
}

/**
 * Light/dark toggle. The page follows the system setting until the visitor
 * picks the other theme, which is kept in <html data-theme> and localStorage
 * (read back in head_assets()); picking the system's own theme again forgets
 * the choice.
 */
function theme_toggle(): void
{ ?>
    <button type="button" class="theme-toggle" id="theme-toggle" hidden>
        <svg class="theme-icon-dark" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
        <svg class="theme-icon-light" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/></svg>
    </button>
    <script>
    (function () {
        var button = document.getElementById('theme-toggle');
        var root = document.documentElement;
        var system = window.matchMedia('(prefers-color-scheme: dark)');
        function current() { return root.dataset.theme || (system.matches ? 'dark' : 'light'); }
        function label() {
            var next = current() === 'dark' ? 'light' : 'dark';
            button.setAttribute('aria-label', 'Switch to ' + next + ' mode');
            button.title = 'Switch to ' + next + ' mode';
        }
        button.addEventListener('click', function () {
            var next = current() === 'dark' ? 'light' : 'dark';
            try {
                if (next === (system.matches ? 'dark' : 'light')) {
                    delete root.dataset.theme;
                    localStorage.removeItem('hps-theme');
                } else {
                    root.dataset.theme = next;
                    localStorage.setItem('hps-theme', next);
                }
            } catch (e) {
                root.dataset.theme = next;
            }
            label();
        });
        system.addEventListener('change', label);
        label();
        button.hidden = false;
    })();
    </script>
    <?php
}

/** Header of a sub-page: back link and theme toggle. */
function page_header(string $backHref = './', string $backLabel = '← All talks'): void
{ ?>
    <div class="top-row">
        <a class="back" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
        <?php theme_toggle(); ?>
    </div>
    <?php
}

/** Overview header: title, links, search box and the Filters menu with the topics. */
function site_header(array $allTags, ?string $activeTag): void
{ ?>
    <header>
        <div class="top-row">
            <h1>HPS Talks Utrecht</h1>
            <?php theme_toggle(); ?>
        </div>
        <p class="intro">Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
        <p class="nav">
            <a href="about.php">About</a> ·
            <a href="subscribe.php<?= $activeTag !== null ? '?tag=' . e(urlencode($activeTag)) : '' ?>">Subscribe in your calendar</a> ·
            <a href="contributing.php">Submit your talk</a> ·
            <a href="correction.php">Submit a correction</a> ·
            <?php // The browser's print dialog, which can also save as PDF ?>
            <a href="#" onclick="window.print(); return false;">Print this page</a>
        </p>
        <div class="tools">
            <input id="q" type="search" aria-label="Search talks" placeholder="Search talks…" autocomplete="off" hidden>
            <?php if ($allTags): ?>
                <button type="button" class="filter-toggle" id="filter-toggle" aria-controls="filter-panel"
                    aria-expanded="<?= $activeTag !== null ? 'true' : 'false' ?>"<?= $activeTag !== null ? ' aria-label="Filters, 1 active"' : '' ?>>
                    Filters<?php if ($activeTag !== null): ?><span class="filter-badge">1</span><?php endif; ?>
                </button>
            <?php endif; ?>
        </div>
        <?php if ($activeTag !== null): ?>
            <p class="active-filter">Showing only <a class="tag" href="<?= e(tag_url($activeTag)) ?>"><?= e($activeTag) ?></a> talks · <a href="<?= e(tag_url(null)) ?>">show all talks ×</a></p>
        <?php endif; ?>
        <?php if ($allTags): ?>
            <?php // Open when a topic is chosen, so a filtered link shows why ?>
            <nav class="filter-panel" id="filter-panel" aria-label="Filter by topic"<?= $activeTag === null ? ' hidden' : '' ?>>
                <span class="filter-label">Topic</span>
                <span class="filter-options">
                    <a href="<?= e(tag_url(null)) ?>"<?= $activeTag === null ? ' aria-current="page"' : '' ?>>All</a>
                    <?php foreach ($allTags as $t): ?>
                        <a href="<?= e(tag_url($t)) ?>"<?= $activeTag === $t ? ' aria-current="page"' : '' ?>><?= e($t) ?></a>
                    <?php endforeach; ?>
                </span>
            </nav>
            <noscript><style>.filter-panel[hidden] { display: flex !important; } .filter-toggle { display: none; }</style></noscript>
        <?php endif; ?>
    </header>
    <?php
}
