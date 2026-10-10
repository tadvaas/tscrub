-- tScrub database schema.
-- Run once as the tScrub MySQL user against the tScrub database:
--   mysql -h 127.0.0.1 -u tScrub -p tScrub < schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(255)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  name            VARCHAR(200)    NOT NULL DEFAULT '',
  account_type    ENUM('personal','company') NOT NULL DEFAULT 'personal',
  company_name    VARCHAR(255)    NOT NULL DEFAULT '',
  company_reg     VARCHAR(64)     NOT NULL DEFAULT '',
  addr_line1      VARCHAR(255)    NOT NULL DEFAULT '',
  addr_line2      VARCHAR(255)    NOT NULL DEFAULT '',
  city            VARCHAR(100)    NOT NULL DEFAULT '',
  postcode        VARCHAR(20)     NOT NULL DEFAULT '',
  country         VARCHAR(100)    NOT NULL DEFAULT '',
  phone           VARCHAR(50)     NOT NULL DEFAULT '',
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  email_verified  TINYINT(1)      NOT NULL DEFAULT 0,
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
  failed_attempts INT UNSIGNED    NOT NULL DEFAULT 0,
  locked_until    DATETIME        NULL DEFAULT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id         CHAR(64)        NOT NULL,
  user_id    BIGINT UNSIGNED NULL DEFAULT NULL,
  csrf       CHAR(64)        NOT NULL,
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  user_agent VARCHAR(255)    NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificates (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cert_id    VARCHAR(64)     NOT NULL,
  cocid      VARCHAR(64)     NOT NULL,
  user_id    BIGINT UNSIGNED NULL DEFAULT NULL,
  devices    INT UNSIGNED    NOT NULL DEFAULT 0,
  methods    INT UNSIGNED    NOT NULL DEFAULT 0,
  runs       INT UNSIGNED    NOT NULL DEFAULT 0,
  first_ts   VARCHAR(32)     NULL DEFAULT NULL,
  last_ts    VARCHAR(32)     NULL DEFAULT NULL,
  sha_state  VARCHAR(16)     NOT NULL DEFAULT 'unverified',
  sig_state  VARCHAR(16)     NOT NULL DEFAULT 'none',
  pdf_sha256 CHAR(64)        NOT NULL DEFAULT '',
  pdf_path   VARCHAR(255)    NOT NULL DEFAULT '',
  json_path  VARCHAR(255)    NOT NULL DEFAULT '',
  json_sha256 CHAR(64)       NOT NULL DEFAULT '',
  json_ts_state VARCHAR(16)  NOT NULL DEFAULT 'none',
  issued_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificates_cert (cert_id),
  KEY idx_certificates_user (user_id),
  KEY idx_certificates_cocid (cocid),
  CONSTRAINT fk_certificates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_reports (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_id BIGINT UNSIGNED NOT NULL,
  report_name    VARCHAR(255)    NOT NULL,
  sha256         CHAR(64)        NOT NULL,
  state          VARCHAR(16)     NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_cr_cert (certificate_id),
  CONSTRAINT fk_cr_cert FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_drives (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_id   BIGINT UNSIGNED NOT NULL,
  ts               VARCHAR(32)     NOT NULL DEFAULT '',
  device           VARCHAR(64)     NOT NULL DEFAULT '',
  type             VARCHAR(64)     NOT NULL DEFAULT '',
  model            VARCHAR(255)    NOT NULL DEFAULT '',
  serial           VARCHAR(255)    NOT NULL DEFAULT '',
  size             VARCHAR(32)     NOT NULL DEFAULT '',
  bus              VARCHAR(32)     NOT NULL DEFAULT '',
  class            VARCHAR(64)     NOT NULL DEFAULT '',
  method           VARCHAR(255)    NOT NULL DEFAULT '',
  certification    VARCHAR(64)     NOT NULL DEFAULT '',
  final_status     VARCHAR(64)     NOT NULL DEFAULT '',
  system_name      VARCHAR(255)    NOT NULL DEFAULT '',
  system_serial    VARCHAR(255)    NOT NULL DEFAULT '',
  baseboard_serial VARCHAR(255)    NOT NULL DEFAULT '',
  smart            VARCHAR(16)     NOT NULL DEFAULT '',
  tempc            VARCHAR(16)     NOT NULL DEFAULT '',
  poweronhours     VARCHAR(32)     NOT NULL DEFAULT '',
  powercycles      VARCHAR(32)     NOT NULL DEFAULT '',
  reallocsectors   VARCHAR(32)     NOT NULL DEFAULT '',
  pctused          VARCHAR(32)     NOT NULL DEFAULT '',
  availspare       VARCHAR(32)     NOT NULL DEFAULT '',
  tbw_tb           VARCHAR(32)     NOT NULL DEFAULT '',
  smartpost        VARCHAR(16)     NOT NULL DEFAULT '',
  tempcpost        VARCHAR(16)     NOT NULL DEFAULT '',
  poweronhourspost VARCHAR(32)     NOT NULL DEFAULT '',
  firmware         VARCHAR(64)     NOT NULL DEFAULT '',
  sector_size      VARCHAR(16)     NOT NULL DEFAULT '',
  sectors          VARCHAR(32)     NOT NULL DEFAULT '',
  hpa              VARCHAR(20)     NOT NULL DEFAULT '',
  dco              VARCHAR(20)     NOT NULL DEFAULT '',
  sed_status       VARCHAR(20)     NOT NULL DEFAULT '',
  reallocsectorspost VARCHAR(32)   NOT NULL DEFAULT '',
  selftest         VARCHAR(128)    NOT NULL DEFAULT '',
  start_time       VARCHAR(32)     NOT NULL DEFAULT '',
  end_time         VARCHAR(32)     NOT NULL DEFAULT '',
  duration_secs    VARCHAR(32)     NOT NULL DEFAULT '',
  tool_version     VARCHAR(32)     NOT NULL DEFAULT '',
  operator         VARCHAR(128)    NOT NULL DEFAULT '',
  validator        VARCHAR(128)    NOT NULL DEFAULT '',
  media_source     VARCHAR(128)    NOT NULL DEFAULT '',
  media_destination VARCHAR(128)   NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_cd_cert (certificate_id),
  CONSTRAINT fk_cd_cert FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploaded reports (raw evidence) stored independently of certificates.
-- A certificate is generated from these on demand (POST /api/certs).
CREATE TABLE IF NOT EXISTS reports (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  cocid       VARCHAR(64)     NOT NULL,
  filename    VARCHAR(500)    NOT NULL DEFAULT '',
  sha_state   VARCHAR(16)     NOT NULL DEFAULT 'unverified',
  sig_state   VARCHAR(16)     NOT NULL DEFAULT 'none',
  source      VARCHAR(16)     NOT NULL DEFAULT 'manual',
  report_type ENUM('erasure','diagnostics') NOT NULL DEFAULT 'erasure',
  grade       VARCHAR(8)      NOT NULL DEFAULT '',
  notes       TEXT            NULL,
  devices     INT UNSIGNED    NOT NULL DEFAULT 0,
  runs        INT UNSIGNED    NOT NULL DEFAULT 0,
  payload     JSON            NOT NULL,
  device_key    VARCHAR(255)    NOT NULL DEFAULT '',
  summary_json  JSON            NULL,
  drive_serials JSON            NULL,
  uploaded_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_user (user_id, uploaded_at),
  KEY idx_reports_cocid (user_id, cocid),
  KEY idx_reports_user_type_ts (user_id, report_type, uploaded_at, id),
  KEY idx_reports_user_type_key (user_id, report_type, device_key, id),
  CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  token        CHAR(64)        NOT NULL,
  label        VARCHAR(100)    NOT NULL DEFAULT '',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_token (token),
  KEY idx_api_tokens_user (user_id),
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licences (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  tier         VARCHAR(20)     NOT NULL DEFAULT 'free',
  customer     VARCHAR(255)    NOT NULL,
  expiry       DATE            NOT NULL,
  licence_json TEXT            NOT NULL,
  pub_key      VARCHAR(255)    NOT NULL DEFAULT '',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_licences_user (user_id),
  CONSTRAINT fk_licences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
  token      CHAR(64)        NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       ENUM('verify','reset') NOT NULL,
  expires_at DATETIME        NOT NULL,
  used       TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id   BIGINT UNSIGNED NOT NULL,
  action     VARCHAR(100)    NOT NULL,
  target     VARCHAR(255)    NOT NULL DEFAULT '',
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prepaid device-credit wallet (Stripe Phase 1). One credit = one device wiped.
-- `ref` is the idempotency key: 'stripe:<checkout_session_id>' for credits and
-- 'report:<csv-sha>' for debits, so a re-delivered webhook or a re-uploaded
-- report can never double-count.
-- Credits pool per organisation (§5): `organisation_id` is the org the event
-- belongs to (0 = personal/solo). `user_id` records the actor. A member's
-- balance is the org pool; a solo user's is their own (organisation_id = 0).
CREATE TABLE IF NOT EXISTS credit_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  organisation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  type            ENUM('credit','debit') NOT NULL,
  units           INT UNSIGNED    NOT NULL,
  ref             VARCHAR(255)    NOT NULL DEFAULT '',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_ref (user_id, ref),
  KEY idx_credit_user (user_id, created_at),
  KEY idx_credit_org (organisation_id, id),
  CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe subscriptions (Phase 2). Populated later; schema reserved now.
CREATE TABLE IF NOT EXISTS subscriptions (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                BIGINT UNSIGNED NOT NULL,
  stripe_customer_id     VARCHAR(255)    NOT NULL DEFAULT '',
  stripe_subscription_id VARCHAR(255)    NOT NULL DEFAULT '',
  price_id               VARCHAR(255)    NOT NULL DEFAULT '',
  status                 VARCHAR(32)     NOT NULL DEFAULT '',
  current_period_end     DATETIME        NULL DEFAULT NULL,
  created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subs_user (user_id),
  KEY idx_subs_stripe (stripe_subscription_id),
  CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe webhook event log for idempotency.
CREATE TABLE IF NOT EXISTS stripe_events (
  id         VARCHAR(255) NOT NULL,
  type       VARCHAR(64)  NOT NULL DEFAULT '',
  handled_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Windows Autopilot MDM check audit log (POST /api/mdm/autopilot).
-- One row per probe; the verdict is computed live against Graph.
CREATE TABLE IF NOT EXISTS mdm_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  serial     VARCHAR(255)    NOT NULL DEFAULT '',
  uuid       VARCHAR(64)     NOT NULL DEFAULT '',
  verdict    VARCHAR(20)     NOT NULL DEFAULT '',
  source     VARCHAR(10)     NOT NULL DEFAULT 'live',
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mdm_log_user (user_id, created_at),
  CONSTRAINT fk_mdm_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Windows Autopilot MDM captured hash (POST /api/mdm/hash). Holds the
-- WinPE-captured authoritative oa3tool hash for a device; one row per
-- (user, serial, uuid), kept after the check so the device can be re-probed.
-- owner_key = COALESCE(user_id, 0): the unassigned pool (user_id NULL) is one
-- row per device, and each account owns its own row — a second account adding
-- a device another account owns gets its own hash instead of clobbering the
-- first account's. A re-capture of the same device (serial + uuid) by the same
-- owner overwrites the row; the same serial with a different uuid is a
-- distinct device.
CREATE TABLE IF NOT EXISTS mdm_staged_hash (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NULL,
  owner_key           BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(user_id, 0)) VIRTUAL,
  serial              VARCHAR(255)    NOT NULL DEFAULT '',
  uuid                VARCHAR(64)     NOT NULL DEFAULT '',
  model               VARCHAR(255)    NOT NULL DEFAULT '',
  hardware_identifier TEXT            NOT NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mdm_staged_device (owner_key, serial, uuid),
  KEY idx_mdm_staged_user (user_id),
  CONSTRAINT fk_mdm_staged_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Windows Autopilot MDM check queue (processed by mdm-worker.php). One row per
-- check; status: queued -> checking -> done|failed. The latest done row is the
-- device's current verdict (shown on the dashboard Devices tab).
CREATE TABLE IF NOT EXISTS mdm_jobs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  serial     VARCHAR(255)    NOT NULL DEFAULT '',
  uuid       VARCHAR(64)     NOT NULL DEFAULT '',
  status     VARCHAR(16)     NOT NULL DEFAULT 'queued',
  verdict    VARCHAR(20)     NOT NULL DEFAULT '',
  source     VARCHAR(10)     NOT NULL DEFAULT '',
  detail     TEXT            NULL,
  attempts   INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mdm_jobs_status (status, created_at),
  KEY idx_mdm_jobs_device (user_id, serial, uuid, id),
  KEY idx_mdm_jobs_user_status_ts (user_id, status, updated_at, id),
  CONSTRAINT fk_mdm_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every WinPE hash-upload attempt (logged before validation) so a failed
-- upload is diagnosable server-side even after the WinPE screen has rebooted.
CREATE TABLE IF NOT EXISTS mdm_ingest_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  serial     VARCHAR(255)    NOT NULL DEFAULT '',
  uuid       VARCHAR(64)     NOT NULL DEFAULT '',
  hash_len   INT UNSIGNED    NOT NULL DEFAULT 0,
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mdm_ingest_user (user_id, created_at),
  CONSTRAINT fk_mdm_ingest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appliance presence (POST /api/heartbeat). One row per (user, serial, uuid)
-- recording the last Unix-epoch heartbeat; the dashboard Devices tab marks a
-- machine "online" when its heartbeat is within the last ~90 seconds.
CREATE TABLE IF NOT EXISTS device_presence (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  serial       VARCHAR(255)    NOT NULL DEFAULT '',
  uuid         VARCHAR(64)     NOT NULL DEFAULT '',
  lan_ip       VARCHAR(45)     NOT NULL DEFAULT '',
  phase        VARCHAR(16)     NOT NULL DEFAULT '',
  drives_total INT UNSIGNED    NOT NULL DEFAULT 0,
  drives_done  INT UNSIGNED    NOT NULL DEFAULT 0,
  drives_failed INT UNSIGNED   NOT NULL DEFAULT 0,
  progress_pct TINYINT         NOT NULL DEFAULT -1,
  progress_eta_sec INT         NOT NULL DEFAULT -1,
  last_seen_ts INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_presence_device (user_id, serial, uuid),
  KEY idx_presence_seen (last_seen_ts),
  CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remote BIOS password clear (dashboard stages, appliance executes). Passwords
-- are encrypted at rest (libsodium; see bios_unlock.php). status:
-- LEGACY / HISTORY ONLY. A BIOS password clear is now a `bios_unlock` command
-- in device_commands (one queue for every remote command); nothing writes this
-- table any more, and it is kept so pre-merge clears stay auditable. status:
-- pending -> dispatched -> done|failed|unsupported (or superseded/cancelled).
CREATE TABLE IF NOT EXISTS bios_unlock (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  serial        VARCHAR(255)    NOT NULL DEFAULT '',
  uuid          VARCHAR(64)     NOT NULL DEFAULT '',
  password_enc  VARCHAR(512)    NOT NULL DEFAULT '',
  status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
  result        VARCHAR(16)     NOT NULL DEFAULT '',
  detail        VARCHAR(512)    NOT NULL DEFAULT '',
  verdict       VARCHAR(24)     NOT NULL DEFAULT '',
  tool_version  VARCHAR(32)     NOT NULL DEFAULT '',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispatched_at DATETIME        NULL,
  resolved_at   DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_unlock_user_serial (user_id, serial, id),
  KEY idx_unlock_status (status, id),
  CONSTRAINT fk_unlock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remote device commands (dashboard stages, appliance executes on poll). One
-- queue for EVERY remote command: shutdown, reboot, wipe and bios_unlock. A
-- BIOS unlock keeps its password in `options` (libsodium-encrypted, purged when
-- the command resolves) and its verdict + producing build in the two columns
-- below. status: pending -> dispatched -> done|failed|unsupported (or
-- superseded/cancelled/expired). The appliance reports "deferred" (a wipe
-- started after the claim), which flips the row straight back to "pending" for
-- the next poll.
CREATE TABLE IF NOT EXISTS device_commands (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  serial        VARCHAR(255)    NOT NULL DEFAULT '',
  uuid          VARCHAR(64)     NOT NULL DEFAULT '',
  command       VARCHAR(16)     NOT NULL DEFAULT '',
  options       JSON            NULL,
  status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
  result        VARCHAR(16)     NOT NULL DEFAULT '',
  detail        VARCHAR(255)    NOT NULL DEFAULT '',
  verdict       VARCHAR(24)     NOT NULL DEFAULT '',
  tool_version  VARCHAR(32)     NOT NULL DEFAULT '',
  cocid         VARCHAR(64)     NOT NULL DEFAULT '',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispatched_at DATETIME        NULL,
  resolved_at   DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_cmd_user_serial (user_id, serial, id),
  KEY idx_cmd_status (status, id),
  CONSTRAINT fk_cmd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live device registration: the appliance posts its identity + hardware + drive
-- inventory on boot (ITAD triage), BEFORE any wipe. Keyed + upserted by
-- (user_id, serial, uuid); the JSON payload carries the full snapshot.
CREATE TABLE IF NOT EXISTS device_registrations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  serial        VARCHAR(255)    NOT NULL DEFAULT '',
  uuid          VARCHAR(64)     NOT NULL DEFAULT '',
  payload       JSON            NOT NULL,
  registered_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_ts  INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_device (user_id, serial, uuid),
  KEY idx_reg_user (user_id, id),
  CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual device refurb grade (I-A–I-F) is stored on the diagnostics report
-- itself (reports.grade) so each report row carries its grade; the Devices
-- tab shows the grade of the latest diagnostics report. Free-form operator
-- notes (e.g. "damaged screen") live alongside it in reports.notes.

-- Organisations & seats (§5) — a lightweight multi-user workspace layer. One
-- Team/Enterprise licence covers several operators (email-invite + role), who
-- share the org's reports, certificates, devices, MDM results, remote commands
-- and BIOS-unlock queue. Reads are org-scoped via org_member_ids(); writes keep
-- user_id (attribution). Member removal is a SOFT remove (status -> inactive):
-- rows stay in org history but the seat is freed.
CREATE TABLE IF NOT EXISTS organisations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(255)    NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_org_owner (owner_user_id),
  CONSTRAINT fk_org_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organisation_members (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organisation_id BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  role            ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_org_member_user (user_id),
  KEY idx_org_member_org (organisation_id),
  CONSTRAINT fk_org_member_org  FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
  CONSTRAINT fk_org_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organisation_invites (
  token           CHAR(64)        NOT NULL,
  organisation_id BIGINT UNSIGNED NOT NULL,
  email           VARCHAR(255)    NOT NULL,
  role            ENUM('admin','member') NOT NULL DEFAULT 'member',
  created_by      BIGINT UNSIGNED NOT NULL,
  expires_at      DATETIME        NOT NULL,
  used            TINYINT(1)      NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY idx_org_invites_org (organisation_id),
  KEY idx_org_invites_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
