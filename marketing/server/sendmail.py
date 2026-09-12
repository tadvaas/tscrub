#!/usr/bin/env python3
"""Send a form submission via Apple iCloud SMTP (smtp.mail.me.com).

Reads a JSON payload on stdin:
  { "type": "contact|download", "subject": "...", "text": "...", "reply_to": "..." }

Credentials come from config.json next to this script.
"""
import json
import os
import smtplib
import ssl
import sys
from email.mime.text import MIMEText
from email.utils import formataddr

BASE = os.path.dirname(os.path.realpath(__file__))


def main():
    try:
        payload = json.load(sys.stdin)
    except Exception as e:
        sys.stderr.write(f"payload: {e}\n")
        sys.exit(2)

    try:
        with open(os.path.join(BASE, "config.json"), encoding="utf-8") as f:
            cfg = json.load(f)
    except Exception as e:
        sys.stderr.write(f"config: {e}\n")
        sys.exit(2)

    typ = payload.get("type", "contact")
    if typ == "download":
        to = cfg.get("to_download", cfg["from"])
    elif typ == "support":
        to = cfg.get("to_support", cfg.get("to_contact", cfg["from"]))
    else:
        to = cfg.get("to_contact", cfg["from"])
    reply_to = payload.get("reply_to")

    msg = MIMEText(payload.get("text", ""), "plain", "utf-8")
    msg["Subject"] = payload.get("subject", "tScrub enquiry")
    msg["From"] = formataddr(("tScrub", cfg["from"]))
    msg["To"] = to
    if reply_to:
        msg["Reply-To"] = reply_to

    ctx = ssl.create_default_context()
    with smtplib.SMTP(cfg["host"], int(cfg["port"]), timeout=30) as server:
        server.ehlo()
        server.starttls(context=ctx)
        server.ehlo()
        server.login(cfg["user"], cfg["password"])
        server.sendmail(cfg["from"], [to], msg.as_string())

    print("ok")


if __name__ == "__main__":
    main()
