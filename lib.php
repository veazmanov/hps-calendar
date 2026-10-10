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
    $multi = $end != $d;
    $sameMonth = $d->format('Y-m') === $end->format('Y-m');
    $time = trim(($ev['time-start'] ?? '') . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : ''));
    $badge = $isPast ? null : badge($d, $end, $today);
    $city = trim((string) ($ev['city'] ?? ''));
    $away = ($city !== '' && mb_strtolower($city) !== 'utrecht') ? $city : null;
    // Everything a visitor might search for, lower-cased, for the search bar
    $haystack = mb_strtolower(implode(' ', array_filter([
        $ev['title'] ?? '', $ev['speaker'] ?? '', $ev['location'] ?? '', $ev['city'] ?? '',
        $ev['reading'] ?? '', $ev['description'] ?? '',
        implode(' ', event_tags($ev)), date_range_text($d, $end),
    ], 'is_string'))); ?>
    <article class="event<?= $isPast ? ' past' : '' ?><?= $single ? ' single' : '' ?>" id="<?= e($ev['_id']) ?>" data-search="<?= e($haystack) ?>">
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
