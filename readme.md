
# HPS Calendar Utrecht


This is the calendar for extracurricular events at HPS: https://hps-calendar.vjbe.net

Every event is its own JSON file in `events/`, added manually (or by Claude Code from
incoming e-mails, see below). The file name, without `.json`, is the event's ID and
is used in its URL, e.g. `events/2026-10-20-descartes-colloquium.json` is shown at
`event.php?id=2026-10-20-descartes-colloquium`. A broken file is skipped and listed
at the bottom of the overview instead of taking the calendar down.

Example:

```json
{
    "date": "2026-10-20",
    "time-start": "15:30",
    "time-end": "17:00",
    "title": "Descartes Colloquium",
    "speaker": "Jane Doe",
    "location": "Drift 21, room 0.07",
    "city": "Utrecht",
    "tags": ["Philosophy of Physics"],
    "description": "Free text."
}
```

Only `date` (YYYY-MM-DD) and `title` are required. Optional: `date-end` for multi-day
events, `time-start`/`time-end`, `speaker`, `location`, `city` (a badge is shown if it
isn't Utrecht), `reading`, `description` and `tags`.

Pages: `index.php` (overview), `event.php` (one event), `calendar.php` (iCalendar feed),
`subscribe.php` (how to subscribe), shared code in `lib.php`.
`./dev.sh` runs it locally, `./deploy.sh` uploads it.


## Sources to check

Besides the e-mails (see below), these pages are always consulted when updating the calendar:

- UPAC colloquia (Utrecht Philosophy of Astronomy & Cosmology): https://www.uu.nl/en/research/utrecht-philosophy-of-astronomy-cosmology/events/upac-colloquium
- SAS-UUS activities: https://sasuus.nl/activities/
- SUUS agenda feed (has events the activities page doesn't show yet): https://calendar.google.com/calendar/ical/ayuhpssecsa%40gmail.com/public/basic.ics


## Downloading incoming e-mails

Organisers send their announcements to **hps-calendar@vjbe.net** (hosted at Fastmail).
`fetchmail.py` downloads them into `inbox/`, so Claude Code can read them and
update the files in `events/`. It uses only the Python 3 standard library, so there is nothing to install.

### One-time setup

1. **Create an app password** in Fastmail: Settings → Privacy & Security → Integrations → New app password.
   Give it access to **Mail (IMAP)**.
2. **Create `.env`** in the repo root (copy `.env.example`):

   ```
   IMAP_HOST=imap.fastmail.com
   IMAP_USER=hps-calendar@vjbe.net
   IMAP_PASSWORD=<the app password>
   ```

   Note that `smtp.fastmail.com` is for *sending* mail; downloading needs `imap.fastmail.com`.
   If `hps-calendar@vjbe.net` is an alias of your main account rather than its own login,
   set `IMAP_USER` to your main account's address. Optionally add `IMAP_FOLDER=<folder>`
   (default `INBOX`) if a Fastmail rule files these mails into their own folder.
3. **Make sure `.env` and `inbox/` are in `.gitignore`.** The repo is public, and the mails contain personal details.

### Usage

```
python3 fetchmail.py          # download new mails only
python3 fetchmail.py --all    # re-download everything
```

Each mail ends up in its own folder:

```
inbox/2026-10-14_1530_sender_subject_uid42/
    message.eml      raw mail
    message.txt      headers + plain-text body (point Claude Code at this)
    attachments/     posters, PDFs, ...
```

- The mailbox is opened read-only: nothing is marked as read, moved or deleted.
- Which mails were already downloaded is remembered in `inbox/.state.json`.
  Delete that file (or use `--all`) to fetch everything again.
- `deploy.sh` only uploads a fixed list of files, so `.env`, `inbox/` and the script never go to the server.

### Typical workflow

In short: `make fetch`, `make populate`, check with `./dev.sh`, `make deploy`.
`make populate` runs Claude Code non-interactively with the instructions in `populate.md`
on the mails it hasn't seen yet (tracked in `inbox/.processed`) and the pages below. It can only edit
files, check JSON and fetch pages from the domains listed under Sources to check, and it doesn't commit or deploy.
By hand, the steps are:

1. `python3 fetchmail.py`
2. Ask Claude Code to read the new folders in `inbox/` and add or update the matching files in `events/`.
   Keep events minimal: date, time, title, speaker, location, tags and at most a short
   description. **No registration links, buttons or attachments.** The details people need to sign up
   are in the organisers' own e-mails; the calendar just tells people that a talk exists, and when and where.
3. Go through the pages under [Sources to check](#sources-to-check) for new or changed events.
4. Check the result locally (`./dev.sh`), then `./deploy.sh`.
