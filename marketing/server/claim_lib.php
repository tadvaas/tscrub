<?php
declare(strict_types=1);

/**
 * The sanitisaton-standard claim, shared by every surface that states it.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The rule that decides whether a job may be labelled IEEE 2883-2022 was once a
 * function inside `render_cert.php`, so only the PDF could state it. Any other
 * surface that wanted to say the same thing had to re-implement the rule, and a
 * re-implemented rule drifts: this codebase has already shipped three claim
 * surfaces disagreeing at once (PDF, JSON-LD, /verify) and a helper with passing
 * tests that nothing called. The rule therefore lives here, in a file with no
 * dependencies, and the PDF and the public verification page both call it.
 *
 * THE RULE
 * --------
 * The appliance scores each drive against five gates and records a standard per
 * drive — "IEEE 2883-2022" only where every gate passed, otherwise "NIST 800-88"
 * plus the failing gate in `sanitization_shortfall`. A JOB may be labelled IEEE
 * only when EVERY scored drive earned it: one refusal takes the whole job back to
 * NIST. A drive with no recorded standard (an appliance older than the claim) is
 * not scored at all, so a pre-claim certificate makes no statement either way.
 *
 * WORDING RULE
 * ------------
 * We describe methods, we never claim to be certified. Nothing certifies a
 * product to IEEE 2883, exactly as nothing certifies one to NIST 800-88.
 */

/**
 * The sanitisation claim for a whole job.
 *
 * @param array $drives rows carrying `sanitization_standard`,
 *                      `sanitization_level` and `sanitization_shortfall`
 * @return array{standard:string,level:string,shortfall:string,ieee:bool}
 */
function cert_claim(array $drives): array
{
    $scored = 0;
    $ieee = 0;
    $levels = [];
    $shortfall = '';
    foreach ($drives as $d) {
        $std = trim((string)($d['sanitization_standard'] ?? ''));
        if ($std === '') continue;             // pre-v1.11.76 record: no claim either way
        $scored++;
        if (strcasecmp($std, 'IEEE 2883-2022') === 0) {
            $ieee++;
            $lvl = trim((string)($d['sanitization_level'] ?? ''));
            if ($lvl !== '') $levels[$lvl] = true;
        } elseif ($shortfall === '') {
            $shortfall = trim((string)($d['sanitization_shortfall'] ?? ''));
        }
    }
    if ($scored === 0 || $ieee !== $scored) {
        return ['standard' => 'NIST SP 800-88 Rev 1', 'level' => '', 'shortfall' => $shortfall, 'ieee' => false];
    }
    // Uniform level where every drive agrees. A drive whose level was not
    // recorded (an appliance older than the level column) leaves the set empty:
    // say nothing rather than guess, because "Clear, Purge" on an all-Purge job
    // would be a fabricated detail.
    $level = '';
    if (count($levels) === 1) {
        $level = (string)array_key_first($levels);
    } elseif (count($levels) > 1) {
        $level = 'Clear, Purge';
    }
    return ['standard' => 'IEEE 2883-2022', 'level' => $level, 'shortfall' => '', 'ieee' => true];
}

/** The standard as it is printed, e.g. `IEEE 2883-2022 (Clear, Purge)`. */
function cert_claim_label(array $claim): string
{
    $label = (string)($claim['standard'] ?? '');
    $level = (string)($claim['level'] ?? '');
    return $level !== '' ? $label . ' (' . $level . ')' : $label;
}

/**
 * The disclaimer that must appear wherever the IEEE claim is made. One string,
 * used by the PDF footer and the /verify page, so the two cannot diverge.
 */
function cert_claim_caveat(): string
{
    return 'IEEE 2883-2022 methods described, not IEEE certified';
}

/**
 * The claim as a surface should present it, or null when there is nothing to
 * state. A refused job returns null on purpose: the surfaces that show a claim
 * only ever ADD one, and stating "NIST" on a page that previously stated nothing
 * would be a new claim about a job nobody re-assessed.
 *
 * @return array{value:string,note:string}|null
 */
function cert_claim_display(array $claim): ?array
{
    if (empty($claim['ieee'])) return null;
    return ['value' => cert_claim_label($claim), 'note' => cert_claim_caveat()];
}
