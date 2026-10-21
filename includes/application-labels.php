<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Codes to readable labels
|--------------------------------------------------------------------------
|
| Objectives and beneficiaries are stored as codes: HOUSING_SETTLEMENT,
| RELIEF_PASTORALISM_MARGINALIZED, DRUG_ALCOHOL_ADDICTS. Fine in a database
| column, wrong in front of a reader - a register printed for a supervisor
| should not say RELIEF_PASTORALISM_MARGINALIZED.
|
| The real maps already existed in includes/audit.php, where the audit
| trail uses them to write readable change descriptions. Every other page
| had either its own stale copy or none at all, which is why the register
| export was printing the raw codes.
|
| This file is the one place to ask. It defers to audit.php, so there is
| still only one list of labels and it stays the list the audit trail uses.
|
*/

require_once __DIR__ . '/audit.php';


/** @return array<string, string> */
function pboObjectiveLabels(): array
{
    return pboAuditObjectiveLabels();
}


/** @return array<string, string> */
function pboBeneficiaryLabels(): array
{
    return pboAuditBeneficiaryLabels();
}


/**
 * A stored code, as a person should read it.
 *
 * An unknown code is tidied rather than dropped: a code the map has not
 * caught up with still means something, and printing nothing would lose
 * it entirely. HOUSING_SETTLEMENT would come back as "Housing Settlement"
 * even if the map forgot it.
 */
function pboCodeLabel(string $code, array $map): string
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        return '';
    }

    return $map[$code]
        ?? ucwords(strtolower(str_replace('_', ' ', $code)));
}
