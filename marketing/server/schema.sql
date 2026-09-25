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
  devices     INT UNSIGNED    NOT NULL DEFAULT 0,
  runs        INT UNSIGNED    NOT NULL DEFAULT 0,
  payload     JSON            NOT NULL,
  uploaded_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_user (user_id, uploaded_at),
  KEY idx_reports_cocid (user_id, cocid),
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
CREATE TABLE IF NOT EXISTS credit_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       ENUM('credit','debit') NOT NULL,
  units      INT UNSIGNED    NOT NULL,
  ref        VARCHAR(255)    NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_ref (user_id, ref),
  KEY idx_credit_user (user_id, created_at),
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
