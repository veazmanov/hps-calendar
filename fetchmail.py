#!/usr/bin/env python3
"""Download new e-mails from the hps-calendar inbox into inbox/.

Reads IMAP_HOST / IMAP_USER / IMAP_PASSWORD (and optionally IMAP_PORT,
IMAP_FOLDER, IMAP_TIMEOUT) from .env. Standard library only.

Each mail becomes a folder in inbox/:
    inbox/2026-10-14_1530_sender_subject_uid42/
        message.eml        the raw mail
        message.txt        headers + plain-text body (what Claude Code should read)
        attachments/       posters, PDFs, ...

The mailbox is opened read-only: nothing is marked as read, moved or deleted.
Already-downloaded mails are tracked in inbox/.state.json.

    python3 fetchmail.py          # only new mails
    python3 fetchmail.py --all    # re-download everything
"""
import imaplib
import json
import os
import re
import sys
from email import message_from_bytes, policy
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "inbox"
STATE = OUT / ".state.json"


def load_env() -> dict:
    env = {}
    path = ROOT / ".env"
    if path.exists():
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    for k in list(os.environ):
        if k.startswith("IMAP_"):
            env[k] = os.environ[k]
    return env


class _Text(HTMLParser):
    def __init__(self):
        super().__init__()
        self.parts = []
        self.skip = 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style"):
            self.skip += 1
        elif tag in ("br", "p", "div", "tr", "li", "h1", "h2", "h3", "h4"):
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in ("script", "style"):
            self.skip = max(0, self.skip - 1)

    def handle_data(self, data):
        if not self.skip:
            self.parts.append(data)


def html_to_text(html: str) -> str:
    p = _Text()
    p.feed(html)
    text = "".join(p.parts)
    return re.sub(r"\n{3,}", "\n\n", re.sub(r"[ \t]+", " ", text)).strip()


def slug(s: str, n: int = 40) -> str:
    s = re.sub(r"[^\w\-]+", "-", s, flags=re.UNICODE).strip("-")
    return s[:n] or "x"


def body_text(msg) -> str:
    part = msg.get_body(preferencelist=("plain", "html"))
    if part is None:
        return ""
    try:
        text = part.get_content()
    except Exception:  # unknown charset etc.
        text = part.get_payload(decode=True).decode("utf-8", errors="replace")
    return html_to_text(text) if part.get_content_type() == "text/html" else text.strip()


def save_mail(uid: int, raw: bytes) -> Path:
    msg = message_from_bytes(raw, policy=policy.default)
    try:
        dt = parsedate_to_datetime(msg["Date"]).astimezone()
        stamp = dt.strftime("%Y-%m-%d_%H%M")
    except Exception:
        stamp = "undated"
    sender = slug(msg.get("From", "unknown").split("<")[0] or msg.get("From", ""), 20)
    folder = OUT / f"{stamp}_{sender}_{slug(msg.get('Subject', ''))}_uid{uid}"
    folder.mkdir(parents=True, exist_ok=True)

    (folder / "message.eml").write_bytes(raw)

    attachments = []
    for part in msg.walk():
        name = part.get_filename()
        if not name or part.is_multipart():
            continue
        payload = part.get_payload(decode=True)
        if payload is None:
            continue
        adir = folder / "attachments"
        adir.mkdir(exist_ok=True)
        safe = re.sub(r'[\\/:*?"<>|]', "_", name)
        target = adir / safe
        i = 1
        while target.exists():
            target = adir / f"{i}_{safe}"
            i += 1
        target.write_bytes(payload)
        attachments.append(target.name)

    header = "\n".join(f"{h}: {msg.get(h, '')}" for h in ("From", "To", "Date", "Subject"))
    if attachments:
        header += "\nAttachments: " + ", ".join(attachments)
    (folder / "message.txt").write_text(header + "\n\n" + body_text(msg) + "\n", encoding="utf-8")
    return folder


def main() -> int:
    env = load_env()
    missing = [k for k in ("IMAP_HOST", "IMAP_USER", "IMAP_PASSWORD") if not env.get(k)]
    if missing:
        print(f"Missing in .env: {', '.join(missing)} (see .env.example)", file=sys.stderr)
        return 1

    OUT.mkdir(exist_ok=True)
    state = json.loads(STATE.read_text()) if STATE.exists() else {}

    host, port = env["IMAP_HOST"], int(env.get("IMAP_PORT", 993))
    timeout = int(env.get("IMAP_TIMEOUT", 30))
    print(f"Connecting to {host}:{port} ...", flush=True)
    try:
        imap = imaplib.IMAP4_SSL(host, port, timeout=timeout)
    except OSError as err:
        print(f"Could not connect to {host}:{port} ({err}).\n"
              "Check IMAP_HOST in .env (Fastmail: imap.fastmail.com, not smtp.fastmail.com) "
              "and your network.", file=sys.stderr)
        return 1
    print(f"Logging in as {env['IMAP_USER']} ...", flush=True)
    try:
        imap.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    except imaplib.IMAP4.error as err:
        print(f"Login failed: {err}\n"
              "Check IMAP_USER (your main login, not the alias) and that the app password has IMAP access.",
              file=sys.stderr)
        return 1
    try:
        folder_name = env.get("IMAP_FOLDER", "INBOX")
        typ, _ = imap.select(folder_name, readonly=True)
        if typ != "OK":
            print(f"Could not open folder {folder_name!r}. Does it exist, with exactly this spelling?",
                  file=sys.stderr)
            return 1
        print(f"Opened {folder_name}, looking for new mail ...", flush=True)

        # UIDs are only meaningful for one UIDVALIDITY; if it changed, start over
        validity = imap.response("UIDVALIDITY")[1][0].decode()
        last = state.get("last_uid", 0)
        if "--all" in sys.argv or state.get("uidvalidity") != validity:
            last = 0

        typ, data = imap.uid("SEARCH", None, f"UID {last + 1}:*")
        uids = sorted(int(u) for u in data[0].split() if int(u) > last)
        if not uids:
            print("No new mail.")
            return 0

        for uid in uids:
            typ, msg_data = imap.uid("FETCH", str(uid), "(BODY.PEEK[])")
            raw = next((p[1] for p in msg_data if isinstance(p, tuple)), None)
            if raw is None:
                print(f"  ! uid {uid}: could not fetch", file=sys.stderr)
                continue
            folder = save_mail(uid, raw)
            print(f"  ✓ {folder.relative_to(ROOT)}")
            state = {"uidvalidity": validity, "last_uid": uid}
            STATE.write_text(json.dumps(state))
        print(f"Downloaded {len(uids)} mail(s) into {OUT.relative_to(ROOT)}/")
    finally:
        imap.logout()
    return 0


if __name__ == "__main__":
    sys.exit(main())
