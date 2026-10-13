#!/bin/bash
# Exercises the four attested-destruction routes over REAL HTTP with a REAL session.
#
# This exists because it caught two defects nothing else could: api.php rebuilt the
# list responses and silently dropped the new `attested_total` key, and
# manual_attested_save_media() returned {"ok":true,"id":0} because it read
# lastInsertId() after commit(). Unit tests calling load_devices() directly missed
# both, because neither bug is in the function they call.
#
# Minting the session (no password needed — the app stores sessions as plain rows):
#
#   ssh <server> 'cd ~/webs/tscrub-form && php -r "
#     require \"db.php\";
#     \$u = (int)db()->query(\"SELECT id FROM users ORDER BY id LIMIT 1\")->fetchColumn();
#     \$tok = bin2hex(random_bytes(32)); \$csrf = bin2hex(random_bytes(32));
#     db()->prepare(\"INSERT INTO sessions (id,user_id,csrf,ip,user_agent,expires_at)
#       VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))\")
#       ->execute([\$tok,\$u,\$csrf,\"127.0.0.1\",\"route-verify\"]);
#     echo \$tok,\" \",\$csrf;
#   "'
#
# NOTE the token MUST be 64 hex characters: auth_start() only accepts
# /^[0-9a-f]{64}$/ and silently issues a fresh anonymous session otherwise, which
# makes every authenticated request fail with a confusing 403.
#
# Then:  bash route_verify.sh https://tscrub.com <token> <csrf>
# Afterwards, delete the session row and any certificate with the test COCID.
#
# The session must belong to an account with NO existing attested records. Several
# checks assert exact attested counts and that the lists are "empty of attestations
# again" after cleanup, so an account that already owns attested rows fails four of
# them (user 38, which holds the demo records, does; user 1 does not). Those failures
# are data, not code — check the row count before believing them:
#
#   SELECT COUNT(*) FROM certificate_drives d JOIN certificates c ON c.id = d.certificate_id
#    WHERE d.source = 'manual' AND c.user_id = <id>;

set -u
BASE=${1:?usage: route_verify.sh BASE_URL TOKEN CSRF}
TOK=${2:?token}
CSRF=${3:?csrf}
COOKIE="tscrub_session=$TOK"
COC=ROUTE-VERIFY-DELETEME
pass=0; fail=0

chk() { # label expected actual
  if [ "$2" = "$3" ]; then echo "ok   - $1 ($3)"; pass=$((pass+1));
  else echo "FAIL - $1 (expected $2, got $3)"; fail=$((fail+1)); fi
}
code() { curl -s -o /tmp/rv_body.json -w '%{http_code}' "$@"; }

cat > /tmp/rv_dev.json <<JSON
{"cocid":"$COC","device":{"category":"mobile_phone","make":"Apple","model":"iPhone 6","serial":"RV-IMEI-1","reason":"media_soldered","destroy_method":"shredded","destroy_vendor":"Acme Shredding","destroy_date":"2026-10-13","destroy_witness":"W. Ness","evidence_ref":"ACME-1"},"media":[{"type":"eMMC/UFS (integrated)","make":"Apple","model":"iPhone 6","serial":"RV-IMEI-1","size":"16 GB"}]}
JSON
cat > /tmp/rv_solo.json <<JSON
{"cocid":"$COC","media":{"type":"SSD","make":"Samsung","model":"870 EVO","serial":"RV-LOOSE-1","size":"500 GB","bus":"SATA","reason":"physically_damaged","destroy_method":"crushed"}}
JSON

# 1. the guards. CSRF is verified before auth, so an unauthenticated request gets 403,
# not 401 — which also avoids telling an attacker whether the session existed.
c=$(code -X POST "$BASE/api/certificates/devices" -H 'Content-Type: application/json' --data-binary @/tmp/rv_dev.json)
chk 'POST without a session is refused (CSRF checked first)' 403 "$c"
c=$(code -X POST "$BASE/api/certificates/devices" -b "$COOKIE" -H 'Content-Type: application/json' --data-binary @/tmp/rv_dev.json)
chk 'POST with a session but no CSRF token is refused' 403 "$c"

# 2. a device with media
c=$(code -X POST "$BASE/api/certificates/devices" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" --data-binary @/tmp/rv_dev.json)
chk 'POST /api/certificates/devices creates the record' 201 "$c"
DEV_ID=$(sed -n 's/.*"id":\([0-9]*\).*/\1/p' /tmp/rv_body.json)
chk 'the response carries a usable id' true "$([ "${DEV_ID:-0}" -gt 0 ] && echo true || echo false)"
chk 'the response carries the certificate id' true "$(grep -q '"cert":"COD-' /tmp/rv_body.json && echo true || echo false)"

# 3. a duplicate identity is refused with the actionable message
c=$(code -X POST "$BASE/api/certificates/devices" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" --data-binary @/tmp/rv_dev.json)
chk 'a duplicate identity is refused' 409 "$c"
chk 'the duplicate message tells the operator what to do' true "$(grep -q 'asset tag' /tmp/rv_body.json && echo true || echo false)"

# 4. a standalone destroyed drive. The id here is the one that was 0 before the fix.
c=$(code -X POST "$BASE/api/certificates/media" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" --data-binary @/tmp/rv_solo.json)
chk 'POST /api/certificates/media creates the record' 201 "$c"
MEDIA_ID=$(sed -n 's/.*"id":\([0-9]*\).*/\1/p' /tmp/rv_body.json)
chk 'the standalone medium returns a usable id (was 0)' true "$([ "${MEDIA_ID:-0}" -gt 0 ] && echo true || echo false)"

# 5. a bad body is rejected with a field-shaped message
echo "{\"cocid\":\"$COC\",\"media\":{\"make\":\"x\",\"model\":\"y\"}}" > /tmp/rv_bad.json
c=$(code -X POST "$BASE/api/certificates/media" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" --data-binary @/tmp/rv_bad.json)
chk 'a medium without a serial is rejected' 400 "$c"

# 6. both records reach the lists, and the attested count is forwarded
curl -s -b "$COOKIE" "$BASE/api/devices?page=1&per=25&q=&sort=status&dir=desc" > /tmp/rv_devices.json
chk 'the attested device is on the Devices list' true "$(grep -q 'RV-IMEI-1' /tmp/rv_devices.json && echo true || echo false)"
chk 'the Devices list reports the attested count' true "$(grep -q '"attested_total":1' /tmp/rv_devices.json && echo true || echo false)"
chk 'the Devices list did not fail with an SQL error' true "$(grep -q 'SQLSTATE' /tmp/rv_devices.json && echo false || echo true)"
curl -s -b "$COOKIE" "$BASE/api/drives?page=1&per=25&q=" > /tmp/rv_drives.json
chk 'the attested media are on the Drives list' true "$(grep -q 'RV-LOOSE-1' /tmp/rv_drives.json && echo true || echo false)"
chk 'the Drives list reports the attested count' true "$(grep -q '"attested_total":2' /tmp/rv_drives.json && echo true || echo false)"
chk 'a standalone drive is not given a host' true "$(grep -q '"serial":"RV-LOOSE-1"' /tmp/rv_drives.json && python3 -c "
import json,sys
d=json.load(open('/tmp/rv_drives.json'))
for x in d['drives']:
    if x.get('serial')=='RV-LOOSE-1':
        sys.exit(0 if not x.get('system') else 1)
sys.exit(1)
" && echo true || echo false)"

# 7. certificate generation for a job of ONLY manually recorded destruction. This is the
#    case that used to answer 404, and the case that used to fold the attested media into
#    the verified drive list — so "devices":0 is the assertion that matters: two attested
#    items must never be counted as instrument-verified evidence.
echo "{\"cocid\":\"$COC\"}" > /tmp/rv_cert.json
c=$(code -X POST "$BASE/api/certs" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" --data-binary @/tmp/rv_cert.json)
chk 'an attested-only job generates a certificate' 201 "$c"
chk 'the verified device count is 0 (attested media are not laundered in)' true "$(grep -q '"devices":0' /tmp/rv_body.json && echo true || echo false)"
chk 'the certificate reports a PDF' true "$(grep -q '"has_pdf":true' /tmp/rv_body.json && echo true || echo false)"
chk 'an attested-only job asks for no destruction decision' true "$(grep -q 'decision_required' /tmp/rv_body.json && echo false || echo true)"

# 8. generation guards. NOTE the phantom-draft risk (answering "is there a job for this
#    COCID?" by INSERTING a row) is not observable over HTTP, because drafts are hidden
#    from the list — the route uses a read-only SELECT, asserted by inspection there.
c=$(code -X POST "$BASE/api/certs" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d '{}')
chk 'generating without a COCID is refused' 400 "$c"
c=$(code -X POST "$BASE/api/certs" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d '{"cocid":"ROUTE-VERIFY-NOTHING-HERE"}')
chk 'generating for a COCID with no reports and no attested records is refused' 404 "$c"
c=$(code -X POST "$BASE/api/certs" -b "$COOKIE" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d '{"cocid":"'"$(printf 'x%.0s' $(seq 1 65))"'"}')
chk 'an over-long COCID is refused' 400 "$c"

# 9. delete both, and confirm they leave
c=$(code -X DELETE "$BASE/api/certificates/devices/$DEV_ID?cocid=$COC" -b "$COOKIE" -H "X-CSRF-Token: $CSRF")
chk 'DELETE /api/certificates/devices removes the unit' 200 "$c"
c=$(code -X DELETE "$BASE/api/certificates/media/$MEDIA_ID?cocid=$COC" -b "$COOKIE" -H "X-CSRF-Token: $CSRF")
chk 'DELETE /api/certificates/media removes the medium' 200 "$c"
curl -s -b "$COOKIE" "$BASE/api/drives?page=1&per=25&q=" > /tmp/rv_after.json
chk 'the drives list is empty of attestations again' true "$(grep -q '"attested_total":0' /tmp/rv_after.json && echo true || echo false)"
c=$(code -X DELETE "$BASE/api/certificates/devices/99999?cocid=$COC" -b "$COOKIE" -H "X-CSRF-Token: $CSRF")
chk 'deleting a non-existent id is a no-op, not an error' 200 "$c"
c=$(code -X DELETE "$BASE/api/certificates/devices/1" -b "$COOKIE" -H "X-CSRF-Token: $CSRF")
chk 'DELETE without a COCID is refused' 400 "$c"

echo
echo "$pass passed, $fail failed"
exit $((fail > 0 ? 1 : 0))
