<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Per-ID-type configuration for the ID screening pipeline.
 *
 * !!! verify against a current real sample before enforcing !!!
 *
 * Every number format below is a best-effort GUESS from public
 * information. Nothing here has been checked against a government
 * database (none is accessed). Because of that, `enforce_format` is
 * FALSE for every type: a number that doesn't match is sent to an admin
 * ("unusual format"), NOT auto-rejected. Once you have compared the
 * pattern with several real, current cards, set `enforce_format` => true
 * for that one type to let a bad format auto-reject.
 *
 * Normalisation applied before matching `number_regex`: upper-case, and
 * spaces / dashes / dots / slashes removed.  "N01-23-456789" -> "N0123456789".
 *
 * Keys:
 *   label            exact text of the <option> shown on the ID step (stored in government_ids.id_type)
 *   number_label     what the form calls the number
 *   number_example   placeholder text
 *   number_regex     matched against the NORMALISED number (null = no format known)
 *   enforce_format   true = format mismatch auto-rejects; false = admin review (default)
 *   requires_number  form must contain a number
 *   has_expiry       this ID normally carries an expiry date
 *   requires_expiry  an expiry date is required (missing = admin, expired = auto-reject)
 *   requires_mrz     passport: the two machine-readable lines must be typed in
 *   strength         strong | medium | weak   (only 'strong' can ever be auto-passed)
 *   always_admin     weak / unknown types: always escalate to an admin
 * ---------------------------------------------------------------------
 */
function id_formats(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [
        'passport' => [
            'label'           => 'Passport',
            'number_label'    => 'Passport number',
            'number_example'  => 'P1234567A',
            // MRZ document-number field is 9 chars of A-Z0-9. PH passports are
            // letter + 7 digits + letter on current books, but older books differ.
            'number_regex'    => '/^[A-Z0-9]{6,9}$/',
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => true,
            'requires_expiry' => true,
            'requires_mrz'    => true,   // check digits are verified mathematically (ICAO 9303 TD3)
            'strength'        => 'strong',
            'always_admin'    => false,
        ],
        'philsys' => [
            'label'           => 'Philippine National ID (PhilSys)',
            'number_label'    => 'PhilSys number (PSN / PCN)',
            'number_example'  => '1234-5678-9012-3456',
            // PSN is 12 digits; the card number (PCN) is printed as 16 digits.
            'number_regex'    => '/^(\d{12}|\d{16})$/',
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => false,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'medium',   // QR decode needs accreditation -> not verified, escalate
            'always_admin'    => false,
        ],
        'drivers_license' => [
            'label'           => "Driver's License",
            'number_label'    => 'License number',
            'number_example'  => 'N01-23-456789',
            // LTO pattern: 1 letter + 2 digits + 2 digits + 6 digits (e.g. N01-23-456789).
            'number_regex'    => '/^[A-Z]\d{10}$/',
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => true,
            'requires_expiry' => true,
            'requires_mrz'    => false,
            'strength'        => 'medium',
            'always_admin'    => false,
        ],
        'umid' => [
            'label'           => 'UMID',
            'number_label'    => 'CRN (Common Reference Number)',
            'number_example'  => '0028-1234567-8',
            'number_regex'    => '/^\d{12}$/',   // 12-digit CRN
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => false,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'medium',
            'always_admin'    => false,
        ],
        'sss' => [
            'label'           => 'SSS ID',
            'number_label'    => 'SS number',
            'number_example'  => '34-1234567-8',
            'number_regex'    => '/^\d{10}$/',   // 10-digit SS number
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => false,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'medium',
            'always_admin'    => false,
        ],
        'philhealth' => [
            'label'           => 'PhilHealth ID',
            'number_label'    => 'PhilHealth PIN',
            'number_example'  => '12-345678901-2',
            'number_regex'    => '/^\d{12}$/',   // 12-digit PIN
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => false,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'medium',
            'always_admin'    => false,
        ],
        'postal' => [
            'label'           => 'Postal ID',
            'number_label'    => 'ID number',
            'number_example'  => '',
            'number_regex'    => null,           // no reliable format known
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => true,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'weak',
            'always_admin'    => true,           // weak ID: always escalate
        ],
        'voters' => [
            'label'           => "Voter's ID",
            'number_label'    => 'ID number',
            'number_example'  => '',
            'number_regex'    => null,
            'enforce_format'  => false,
            'requires_number' => true,
            'has_expiry'      => false,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'weak',
            'always_admin'    => true,
        ],
        'other' => [
            'label'           => 'Other government-issued ID',
            'number_label'    => 'ID number (if any)',
            'number_example'  => '',
            'number_regex'    => null,
            'enforce_format'  => false,
            'requires_number' => false,
            'has_expiry'      => true,
            'requires_expiry' => false,
            'requires_mrz'    => false,
            'strength'        => 'weak',
            'always_admin'    => true,
        ],
    ];

    return $cfg;
}

/** Map the dropdown label stored in government_ids.id_type to a config key (null if unknown). */
function id_format_key_for_label(string $label): ?string
{
    $label = trim($label);
    foreach (id_formats() as $key => $f) {
        if (strcasecmp($f['label'], $label) === 0) {
            return $key;
        }
    }
    return null;
}

/** Upper-case, strip spaces / dashes / dots / slashes. */
function id_normalize_number(string $number): string
{
    return strtoupper(preg_replace('/[\s\-\.\/]+/', '', trim($number)) ?? '');
}

/** Which OTHER (non-weak) types would accept this number? Used for the type-mismatch hint only. */
function id_types_matching_number(string $normalized, string $exceptKey): array
{
    $hits = [];
    foreach (id_formats() as $key => $f) {
        if ($key === $exceptKey || $f['number_regex'] === null || $f['strength'] === 'weak') {
            continue;
        }
        if (preg_match($f['number_regex'], $normalized) === 1) {
            $hits[] = $key;
        }
    }
    return $hits;
}
