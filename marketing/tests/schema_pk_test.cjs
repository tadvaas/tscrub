/**
 * Every AUTO_INCREMENT column must be the first column of some key.
 *
 * Why a test: MySQL rejects the table with error 1075 ("there can be only one
 * auto column and it must be defined as a key") when it is not. In
 * certificate_assets the id was keyed only as the second column of
 * uq_cert_asset (certificate_id, asset_key), so the CREATE threw. Because the
 * lazy bootstrap creates its tables in one loop, the exception also aborted the
 * creation of processing_hosts, and "Mark as our bench machine" answered 400 on
 * every request while media_links (created first) looked fine.
 *
 * The failure is invisible to PHP unit tests that run against a database where
 * the tables already exist, and schema.sql carried the same mistake. Assert the
 * shape statically in both places.
 *
 * Run: node marketing/tests/schema_pk_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.SCHEMA_ROOT || path.join(__dirname, '..');
const sql = fs.readFileSync(path.join(root, 'server', 'schema.sql'), 'utf8');
const lib = fs.readFileSync(path.join(root, 'server', 'reports_lib.php'), 'utf8');

let count = 0;
let fails = 0;
function check(label, ok, detail) {
  count++;
  if (ok) console.log('ok   - ' + label);
  else {
    fails++;
    console.log('FAIL - ' + label + (detail !== undefined ? ' (got: ' + detail + ')' : ''));
  }
}

/** True when `col` is the first column of a PRIMARY/UNIQUE/KEY definition. */
function keyedFirst(body, col) {
  const re = new RegExp(
    String.raw`(?:PRIMARY\s+KEY|UNIQUE\s+KEY\s+\w+|KEY\s+\w+)\s*\(\s*` + col + String.raw`\s*[,)]`,
    'i'
  );
  return re.test(body);
}

/** Assert the auto column of a CREATE TABLE body is keyed first. */
function assertAutoKeyed(name, body, where) {
  const m = body.match(/(\w+)\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i);
  if (!m) return;
  check(
    where + ': ' + name + ' keys its AUTO_INCREMENT column first (' + m[1] + ')',
    keyedFirst(body, m[1]),
    'no PRIMARY KEY (' + m[1] + ') and not the first column of another key'
  );
}

// --- schema.sql -----------------------------------------------------------
const sqlTables = {};
const reSql = /CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\(([\s\S]*?)\)\s*ENGINE=/gi;
let m;
while ((m = reSql.exec(sql)) !== null) sqlTables[m[1]] = m[2];
check('schema.sql defines tables', Object.keys(sqlTables).length > 0, Object.keys(sqlTables).length);
for (const [name, body] of Object.entries(sqlTables)) assertAutoKeyed(name, body, 'schema.sql');

// --- the lazy bootstrap in reports_lib.php --------------------------------
const ddlBlock = (lib.match(/\$ddl\s*=\s*\[([\s\S]*?)\n\s*\];/) || [])[1] || '';
check('reports_lib.php has a $ddl array', ddlBlock.length > 0);
const reLib = /'(\w+)'\s*=>\s*"([^"]*)"/g;
const libTables = {};
while ((m = reLib.exec(ddlBlock)) !== null) libTables[m[1]] = m[2];
check('the lazy bootstrap creates tables', Object.keys(libTables).length > 0, Object.keys(libTables).length);

for (const [name, body] of Object.entries(libTables)) {
  assertAutoKeyed(name, body, 'lazy DDL');
  check('lazy DDL: ' + name + ' also exists in schema.sql', Object.prototype.hasOwnProperty.call(sqlTables, name));
  // The two definitions must not drift on the primary-key decision.
  const sqlHas = /PRIMARY KEY \(id\)/i.test(sqlTables[name] || '');
  const libHas = /PRIMARY KEY \(id\)/i.test(body);
  check('lazy DDL: ' + name + ' matches schema.sql on PRIMARY KEY (id)', sqlHas === libHas, {
    schema: sqlHas,
    lib: libHas,
  });
}

console.log('\n' + (fails ? fails + ' of ' + count + ' checks FAILED' : count + ' checks passed'));
process.exit(fails ? 1 : 0);
