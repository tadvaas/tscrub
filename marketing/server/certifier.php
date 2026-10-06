<?php
declare(strict_types=1);

/**
 * Shared certifier/issuer resolution, split out of api.php so the certificate
 * PDF renderer and the machine-readable JSON-LD generator resolve the exact
 * same certifying party from one source of truth.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';

function fetch_user_by_id(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u === false ? null : $u;
}

/**
 * Build the certificate's certifier block from the user's profile. The company
 * name (when set) is the certifying party, falling back to the individual's
 * name; registration/address/phone appear whenever entered. tScrub is named
 * separately on the certificate as the tool provider, not the certifier.
 */
function certifier_details(array $u): array {
    $name = (string)($u['company_name'] ?? '');
    if ($name === '') { $name = (string)($u['name'] ?? ''); }
    if ($name === '') { $name = (string)($u['email'] ?? ''); }

    $reg = (($u['company_reg'] ?? '') !== '') ? 'Company No. ' . $u['company_reg'] : '';

    $addr = [];
    if (($u['addr_line1'] ?? '') !== '') { $addr[] = $u['addr_line1']; }
    if (($u['addr_line2'] ?? '') !== '') { $addr[] = $u['addr_line2']; }
    $cityLine = trim(implode(' ', array_filter([($u['city'] ?? ''), ($u['postcode'] ?? '')])));
    if ($cityLine !== '') { $addr[] = $cityLine; }
    if (($u['country'] ?? '') !== '') { $addr[] = $u['country']; }

    return [
        'name'  => $name,
        'reg'   => $reg,
        'addr'  => implode(', ', $addr),
        'phone' => (string)($u['phone'] ?? ''),
    ];
}

/**
 * Certifier block for a user, with the organisation fallback: a member whose
 * profile has no company name certifies as the organisation (the owner's
 * company details) instead, so every operator's certificate names the same
 * legal certifying party.
 */
function certifier_for_user(int $userId): array {
    $u = fetch_user_by_id($userId) ?? [];
    $details = certifier_details($u);
    if ((string)($u['company_name'] ?? '') === '') {
        $m = org_for_user($userId);
        if ($m !== null && (int)$m['owner_user_id'] !== $userId) {
            $owner = fetch_user_by_id((int)$m['owner_user_id']);
            if ($owner !== null && (string)($owner['company_name'] ?? '') !== '') {
                return certifier_details($owner);
            }
        }
    }
    return $details;
}

/** Treat the appliance's literal "N/A" sentinel (and blanks) as no value. */
function norm_na(string $v): string {
    $t = trim($v);
    return ($t === '' || strcasecmp($t, 'N/A') === 0) ? '' : $t;
}

/**
 * Resolve the operator shown on a generated PDF: the report's own operator
 * when present (and not the "N/A" sentinel), otherwise the account holder's
 * name, falling back to their email.
 */
function operator_for_pdf(array $g, int $userId): string {
    $op = norm_na((string)($g['operator'] ?? ''));
    if ($op !== '') return $op;
    $actor = fetch_user_by_id($userId);
    if ($actor === null) return '';
    return trim((string)($actor['name'] ?? '')) !== ''
        ? trim((string)$actor['name'])
        : trim((string)($actor['email'] ?? ''));
}
