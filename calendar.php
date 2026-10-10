<?php
declare(strict_types=1);

// iCalendar feed that calendar apps can subscribe to: calendar.php,
// or calendar.php?tag=UPAC for one topic only. Linked from index.php.

require __DIR__ . '/lib.php';

[$events] = load_events();

$tag = isset($_GET['tag']) && is_string($_GET['tag']) ? trim($_GET['tag']) : '';
if ($tag !== '') {
    $events = array_values(array_filter($events, fn($ev) => in_array(
        mb_strtolower($tag),
        array_map('mb_strtolower', event_tags($ev)),
        true
    )));
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'hps-calendar.vjbe.net';
$base = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
$uidHost = preg_replace('/:\d+$/', '', $host);

// TEXT values escape \ ; , and newlines (RFC 5545 §3.3.11)
function ical_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
}

// Lines longer than 75 octets are folded onto continuation lines starting with a space
function ical_line(string $line): string
{
    $out = '';
    $max = 75;
    while (strlen($line) > $max) {
        $cut = $max;
        // Don't split a multi-byte UTF-8 character
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $max = 74;   // room for the leading space
    }
    return $out . $line . "\r\n";
}

// Local Amsterdam time on $day at "HH:MM", as UTC (…Z), so no VTIMEZONE block is needed
function ical_utc(DateTimeImmutable $day, string $hm): ?string
{
    $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day->format('Y-m-d') . ' ' . $hm);
    return $t ? $t->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') : null;
}

$name = 'HPS Calendar - Victor Elgersma-Azmanov' . ($tag !== '' ? " ($tag)" : '');
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//HPS Talks Utrecht//hps-calendar//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ical_text($name),
    'X-WR-CALDESC:' . ical_text('Extracurricular talks related to History and Philosophy of Science in Utrecht. Unofficial; always check the details with the organisers.'),
    'X-WR-TIMEZONE:Europe/Amsterdam',
    'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
    'X-PUBLISHED-TTL:PT6H',
];

foreach ($events as $ev) {
    $d = $ev['_date'];
    $end = $ev['_end'];
    $file = __DIR__ . '/events/' . $ev['_id'] . '.json';
    $url = $base . event_url($ev);

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $ev['_id'] . '@' . $uidHost;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', @filemtime($file) ?: time());

    $start = ($end == $d && !empty($ev['time-start'])) ? ical_utc($d, (string) $ev['time-start']) : null;
    if ($start !== null) {
        $stop = !empty($ev['time-end']) ? ical_utc($d, (string) $ev['time-end']) : null;
        $lines[] = 'DTSTART:' . $start;
        // No end time given: assume the usual 1.5-hour talk
        $lines[] = $stop !== null && $stop > $start
            ? 'DTEND:' . $stop
            : 'DURATION:PT1H30M';
    } else {
        // Untimed and multi-day events are all-day; DTEND is the day after the last day
        $lines[] = 'DTSTART;VALUE=DATE:' . $d->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $end->modify('+1 day')->format('Ymd');
    }

    $lines[] = 'SUMMARY:' . ical_text((string) $ev['title']);

    $place = implode(', ', array_filter([
        trim((string) ($ev['location'] ?? '')),
        trim((string) ($ev['city'] ?? '')),
    ], fn($v) => $v !== ''));
    if ($place !== '') {
        $lines[] = 'LOCATION:' . ical_text($place);
    }

    $desc = [];
    if (!empty($ev['speaker'])) $desc[] = 'Speaker: ' . $ev['speaker'];
    if ($end != $d && !empty($ev['time-start'])) {
        $desc[] = 'Time: ' . $ev['time-start'] . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : '');
    }
    if (!empty($ev['reading'])) $desc[] = 'Reading: ' . $ev['reading'];
    if (!empty($ev['description'])) $desc[] = (string) $ev['description'];
    foreach ((array) ($ev['attachments'] ?? []) as $att) {
        if ($link = safe_url($att['URL'] ?? $att['url'] ?? null)) {
            $desc[] = ($att['title'] ?? 'Link') . ': ' . $link;
        }
    }
    $desc[] = 'Details: ' . $url;
    $lines[] = 'DESCRIPTION:' . ical_text(implode("\n\n", $desc));
    $lines[] = 'URL:' . $url;

    $tags = event_tags($ev);
    if ($tags) {
        $lines[] = 'CATEGORIES:' . implode(',', array_map('ical_text', $tags));
    }
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="HPS Calendar - Victor Elgersma-Azmanov.ics"');
echo implode('', array_map('ical_line', $lines));
