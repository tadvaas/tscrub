#!/usr/bin/env python3
"""Issue a tScrub licence and email it to the customer.

Usage:
  issue_licence.py "Customer Ltd" 2027-09-12 buyer@example.com

Generates an Ed25519 report key, signs customer|expiry|key with the vendor
private key, writes the licence JSON to licences/, and emails the .lic file
to the customer. SMTP + vendor key settings come from config.json.
"""
import argparse
import base64
import json
import os
import re
import smtplib
import ssl
import subprocess
import sys
import tempfile
from email.mime.application import MIMEApplication
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formataddr, formatdate

BASE = os.path.dirname(os.path.realpath(__file__))


def load_config():
    with open(os.path.join(BASE, "config.json"), encoding="utf-8") as f:
        return json.load(f)


def run(cmd):
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode != 0:
        sys.stderr.write((r.stderr or b"command failed").decode())
        sys.exit(1)
    return r.stdout


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("customer")
    ap.add_argument("expiry", help="YYYY-MM-DD")
    ap.add_argument("email")
    args = ap.parse_args()

    customer = args.customer.strip()
    expiry = args.expiry.strip()
    email = args.email.strip()

    if not customer:
        sys.stderr.write("customer name required\n")
        sys.exit(1)
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", expiry):
        sys.stderr.write("expiry must be YYYY-MM-DD\n")
        sys.exit(1)
    if "@" not in email:
        sys.stderr.write("invalid email address\n")
        sys.exit(1)

    cfg = load_config()
    from_addr = cfg.get("from_licence", cfg.get("from"))
    vendor_key = cfg.get("vendor_key", os.path.join(BASE, "vendor.key"))
    if not os.path.exists(vendor_key):
        sys.stderr.write(f"vendor key not found: {vendor_key}\n")
        sys.exit(1)

    # 1. Report signing key (private, embedded in the licence)
    report_key = run(["openssl", "genpkey", "-algorithm", "ED25519"])
    key_b64 = base64.b64encode(report_key).decode()

    # 2. Signature over customer|expiry|key
    msg = f"{customer}|{expiry}|{key_b64}"
    with tempfile.NamedTemporaryFile() as tmp:
        tmp.write(msg.encode())
        tmp.flush()
        sig = run(["openssl", "pkeyutl", "-sign", "-inkey", vendor_key,
                   "-rawin", "-in", tmp.name])
    sig_b64 = base64.b64encode(sig).decode()

    licence = {
        "schema": "tscrub-license/1",
        "customer": customer,
        "expiry": expiry,
        "key": key_b64,
        "signature": sig_b64,
    }
    lic_json = json.dumps(licence, indent=2) + "\n"

    # 3. Save for the record
    outdir = os.path.join(BASE, "licences")
    os.makedirs(outdir, exist_ok=True)
    slug = re.sub(r"[^a-z0-9]+", "-", customer.lower()).strip("-") or "customer"
    filename = f"{slug}-{expiry}.lic"
    outpath = os.path.join(outdir, filename)
    with open(outpath, "w", encoding="utf-8") as f:
        f.write(lic_json)

    # 4. Email it
    download_url = cfg.get("download_url", "https://tscrub.com/download")
    body = (
        f"Hi {customer},\n\n"
        "Your tScrub licence is attached. Place the .lic file alongside "
        "tscrub.sh on the appliance\n(or point to it with --license).\n\n"
        f"Licence file: {filename}\n"
        f"Expires: {expiry}\n\n"
        f"Download the appliance: {download_url}\n\n"
        "— tScrub\n"
    )

    msgobj = MIMEMultipart()
    msgobj["Subject"] = "Your tScrub licence"
    msgobj["From"] = formataddr(("tScrub", from_addr))
    msgobj["To"] = email
    msgobj["Date"] = formatdate(localtime=True)
    msgobj.attach(MIMEText(body, "plain", "utf-8"))

    att = MIMEApplication(lic_json, _subtype="octet-stream", name=filename)
    att["Content-Disposition"] = f'attachment; filename="{filename}"'
    msgobj.attach(att)

    ctx = ssl.create_default_context()
    with smtplib.SMTP(cfg["host"], int(cfg["port"]), timeout=30) as s:
        s.ehlo()
        s.starttls(context=ctx)
        s.ehlo()
        s.login(cfg["user"], cfg["password"])
        s.sendmail(from_addr, [email], msgobj.as_string())

    print(f"Licence written: {outpath}")
    print(f"Emailed to:      {email}")


if __name__ == "__main__":
    main()
