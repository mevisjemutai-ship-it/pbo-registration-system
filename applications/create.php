<?php 
 
declare(strict_types=1); 
 
require_once __DIR__ . '/../config/database.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
 
requireLogin(); 
requirePasswordChanged(); 
 
$pageTitle = 'PBO Application'; 
$activePage = 'applications'; 
 
$connection = null; 
 
if (isset($pdo) && $pdo instanceof PDO) { 
    $connection = $pdo; 
} elseif (isset($db) && $db instanceof PDO) { 
    $connection = $db; 
} 
 
if (!$connection instanceof PDO) { 
    throw new RuntimeException( 
        'Database connection was not initialized as a PDO instance.' 
    ); 
} 
 
$userId = (int) ($_SESSION['user_id'] ?? 0); 
 
if ($userId <= 0) { 
    header('Location: /pbo-portal/login.php'); 
    exit; 
} 
 
/* 
|-------------------------------------------------------------------------- 
| CSRF 
|-------------------------------------------------------------------------- 
*/ 
 
if (empty($_SESSION['csrf_token'])) { 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
} 
 
$csrfToken = (string) $_SESSION['csrf_token']; 
 
/* 
|-------------------------------------------------------------------------- 
| Helpers 
|-------------------------------------------------------------------------- 
*/ 
 
function e(?string $value): string 
{ 
    return htmlspecialchars( 
        $value ?? '', 
        ENT_QUOTES | ENT_SUBSTITUTE, 
        'UTF-8' 
    ); 
} 
 
function old(array $form, string $field): string 
{ 
    return e((string) ($form[$field] ?? '')); 
} 
 
function slugifyCounty(string $county): string 
{ 
    $slug = strtolower(trim($county)); 
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? ''; 
 
    return trim($slug, '-'); 
} 
 
function normalizeCountyName(string $county): string
{
    $county = trim($county);
    $county = preg_replace('/\s+/', ' ', $county) ?? '';
    $county = strtolower($county);
    $county = preg_replace('/\s+county$/', '', $county) ?? $county;
    $county = preg_replace('/[^a-z0-9]+/', '', $county) ?? $county;

    return $county;
}

/* 
|-------------------------------------------------------------------------- 
| Kenyan counties 
|-------------------------------------------------------------------------- 
*/ 
 
$countyOptions = [ 
    'Mombasa', 
    'Kwale', 
    'Kilifi', 
    'Tana River', 
    'Lamu', 
    'Taita-Taveta', 
    'Garissa', 
    'Wajir', 
    'Mandera', 
    'Marsabit', 
    'Isiolo', 
    'Meru', 
    'Tharaka-Nithi', 
    'Embu', 
    'Kitui', 
    'Machakos', 
    'Makueni', 
    'Nyandarua', 
    'Nyeri', 
    'Kirinyaga', 
    "Murang'a", 
    'Kiambu', 
    'Turkana', 
    'West Pokot', 
    'Samburu', 
    'Trans Nzoia', 
    'Uasin Gishu', 
    'Elgeyo-Marakwet', 
    'Nandi', 
    'Baringo', 
    'Laikipia', 
    'Nakuru', 
    'Narok', 
    'Kajiado', 
    'Kericho', 
    'Bomet', 
    'Kakamega', 
    'Vihiga', 
    'Bungoma', 
    'Busia', 
    'Siaya', 
    'Kisumu', 
    'Homa Bay', 
    'Migori', 
    'Kisii', 
    'Nyamira', 
    'Nairobi City County', 
]; 
 
$countyMap = []; 
 
foreach ($countyOptions as $county) { 
    $countyMap[slugifyCounty($county)] = $county; 
} 
 
/* 
|-------------------------------------------------------------------------- 
| Form 
|-------------------------------------------------------------------------- 
*/ 
 
$form = [ 
    'pbo_name' => '', 
    'pbo_registration_number' => '', 
    'registration_date' => '', 
    'registration_type' => '', 
 
    'county' => '', 
    'city_town' => '', 
    'sub_county' => '', 
    'physical_address' => '', 
    'postal_address' => '', 
    'postal_code' => '', 
    'phone_number' => '', 
    'alternative_phone_number' => '', 
    'email' => '', 
 
    'registration_status' => '', 
    'parent_organization_name' => '', 
    'country_of_origin' => '', 
    'registration_number' => '', 
    'parent_physical_address' => '', 
    'parent_postal_address' => '', 
    'parent_postal_code' => '', 
    'parent_phone_number' => '', 
    'parent_email' => '', 
    'contact_person_name' => '', 
    'contact_person_phone' => '', 
    'contact_person_email' => '', 
]; 
 
$operatingLocations = []; 
$operatesOtherLocations = 'no'; 
 
$errors = []; 
 
$applicationId = isset($_GET['id']) 
    ? (int) $_GET['id'] 
    : (int) ($_POST['id'] ?? 0); 
 
$isEdit = false; 
 
/* 
|-------------------------------------------------------------------------- 
| Generate application reference 
|-------------------------------------------------------------------------- 
*/ 
 
function generateApplicationReference(PDO $connection): string 
{ 
    $year = (int) date('Y'); 
 
    $statement = $connection->prepare( 
        " 
        INSERT INTO application_reference_sequences 
        ( 
            reference_year, 
            last_number 
        ) 
        VALUES 
        ( 
            :reference_year, 
            0 
        ) 
        ON DUPLICATE KEY UPDATE 
            reference_year = 
                VALUES(reference_year) 
        " 
    ); 
 
    $statement->execute([ 
        ':reference_year' => $year, 
    ]); 
 
    $statement = $connection->prepare( 
        " 
        SELECT 
            last_number 
        FROM application_reference_sequences 
        WHERE reference_year = 
            :reference_year 
        FOR UPDATE 
        " 
    ); 
 
    $statement->execute([ 
        ':reference_year' => $year, 
    ]); 
 
    $lastNumber = (int) $statement->fetchColumn(); 
    $nextNumber = $lastNumber + 1; 
 
    $statement = $connection->prepare( 
        " 
        UPDATE application_reference_sequences 
        SET 
            last_number = 
                :last_number 
        WHERE reference_year = 
            :reference_year 
        " 
    ); 
 
    $statement->execute([ 
        ':last_number' => $nextNumber, 
        ':reference_year' => $year, 
    ]); 
 
    return 
        'PBO.REG.' . 
        $year . 
        '.' . 
        str_pad( 
            (string) $nextNumber, 
            4, 
            '0', 
            STR_PAD_LEFT 
        ); 
} 
 
/* 
|-------------------------------------------------------------------------- 
| Load existing application 
|-------------------------------------------------------------------------- 
*/ 
 
if ($applicationId > 0) { 
 
    $statement = $connection->prepare( 
        " 
        SELECT 
            * 
        FROM pbo_applications 
        WHERE id = :id 
          AND created_by = :created_by 
        LIMIT 1 
        " 
    ); 
 
    $statement->execute([ 
        ':id' => $applicationId, 
        ':created_by' => $userId, 
    ]); 
 
    $application = $statement->fetch(PDO::FETCH_ASSOC); 
 
    if (!$application) { 
        http_response_code(404); 
        exit('Application not found.'); 
    } 
 
    if (($application['status'] ?? '') !== 'draft') { 
 
        $_SESSION['flash_error'] = 
            'Only draft applications can be edited.'; 
 
        header( 
            'Location: /pbo-portal/applications/view.php?id=' . 
            urlencode((string) $applicationId) 
        ); 
 
        exit; 
    } 
 
    $isEdit = true; 
 
    foreach ($form as $field => $defaultValue) { 
 
        if (array_key_exists($field, $application)) { 
 
            $form[$field] = 
                (string) ($application[$field] ?? ''); 
 
        } 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Headquarters / head office address 
    |-------------------------------------------------------------------------- 
    */ 
 
    $statement = $connection->prepare( 
        " 
        SELECT 
            county, 
            city_town, 
            sub_county, 
            physical_address, 
            postal_address, 
            postal_code, 
            phone_number, 
            alternative_phone_number, 
            email 
        FROM application_addresses 
        WHERE application_id = :application_id 
        LIMIT 1 
        " 
    ); 
 
    $statement->execute([ 
        ':application_id' => $applicationId, 
    ]); 
 
    $address = 
        $statement->fetch(PDO::FETCH_ASSOC); 
 
    if ($address) { 
 
        foreach ($address as $field => $value) { 
 
            if (array_key_exists($field, $form)) { 
 
                $form[$field] = 
                    (string) ($value ?? ''); 
 
            } 
        } 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | International details 
    |-------------------------------------------------------------------------- 
    */ 
 
    $statement = $connection->prepare( 
        " 
        SELECT 
            registration_status, 
            parent_organization_name, 
            country_of_origin, 
            registration_number, 
            parent_physical_address, 
            parent_postal_address, 
            parent_postal_code, 
            parent_phone_number, 
            parent_email, 
            contact_person_name, 
            contact_person_phone, 
            contact_person_email 
        FROM application_international_details 
        WHERE application_id = :application_id 
        LIMIT 1 
        " 
    ); 
 
    $statement->execute([ 
        ':application_id' => $applicationId, 
    ]); 
 
    $internationalDetails = 
        $statement->fetch(PDO::FETCH_ASSOC); 
 
    if ($internationalDetails) { 
 
        foreach ( 
            $internationalDetails as $field => $value 
        ) { 
 
            if (array_key_exists($field, $form)) { 
 
                $form[$field] = 
                    (string) ($value ?? ''); 
 
            } 
        } 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Operating locations 
    |-------------------------------------------------------------------------- 
    */ 
 
    $statement = $connection->prepare( 
        " 
        SELECT 
            county, 
            sub_county, 
            physical_address, 
            phone_number, 
            alternative_phone_number, 
            email, 
            postal_address, 
            postal_code 
        FROM application_operating_locations 
        WHERE application_id = :application_id 
        ORDER BY id ASC 
        " 
    ); 
 
    $statement->execute([ 
        ':application_id' => $applicationId, 
    ]); 
 
    $existingOperatingLocations = 
        $statement->fetchAll(PDO::FETCH_ASSOC); 
 
    foreach ( 
        $existingOperatingLocations 
        as $location 
    ) { 
 
        $county = 
            (string) ($location['county'] ?? ''); 
 
        $slug = 
            slugifyCounty($county); 
 
        if ($slug === '') { 
            continue; 
        } 
 
        $operatingLocations[$slug] = [ 
            'county' => 
                $county, 
 
            'sub_county' => 
                (string) ($location['sub_county'] ?? ''), 
 
            'physical_address' => 
                (string) ($location['physical_address'] ?? ''), 
 
            'phone_number' => 
                (string) ($location['phone_number'] ?? ''), 
 
            'alternative_phone_number' => 
                (string) ( 
                    $location['alternative_phone_number'] 
                    ?? '' 
                ), 
 
            'email' => 
                (string) ($location['email'] ?? ''), 
 
            'postal_address' => 
                (string) ($location['postal_address'] ?? ''), 
 
            'postal_code' => 
                (string) ($location['postal_code'] ?? ''), 
        ]; 
    } 
 
    if (count($operatingLocations) > 0) { 
        $operatesOtherLocations = 'yes'; 
    } 
} 
 
/* 
|-------------------------------------------------------------------------- 
| POST 
|-------------------------------------------------------------------------- 
*/ 
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
 
    $postedToken = 
        (string) ($_POST['csrf_token'] ?? ''); 
 
    if ( 
        !hash_equals( 
            $csrfToken, 
            $postedToken 
        ) 
    ) { 
 
        $errors[] = 
            'Invalid security token. Please reload the page and try again.'; 
    } 
 
    $formAction = 
        (string) ( 
            $_POST['form_action'] 
            ?? 'save_continue' 
        ); 
 
    $isWizardNavigation = 
        $formAction === 'wizard_navigation'; 
 
    $wizardDestination = 
        (int) ( 
            $_POST['wizard_destination'] 
            ?? 1 
        ); 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Main form 
    |-------------------------------------------------------------------------- 
    */ 
 
    foreach ( 
        $form as $field => $defaultValue 
    ) { 
 
        if ( 
            array_key_exists( 
                $field, 
                $_POST 
            ) 
        ) { 
 
            $form[$field] = 
                trim( 
                    (string) $_POST[$field] 
                ); 
        } 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Other operating locations 
    |-------------------------------------------------------------------------- 
    */ 
 
    $operatesOtherLocations = 
        ( 
            (string) ( 
                $_POST['operates_other_locations'] 
                ?? 'no' 
            ) 
        ) === 'yes' 
            ? 'yes' 
            : 'no'; 
 
    $postedOperatingLocations = 
        $_POST['operating_locations'] 
        ?? []; 
 
    if (!is_array($postedOperatingLocations)) { 
        $postedOperatingLocations = []; 
    } 
 
    $operatingLocations = []; 
 
    foreach ( 
        $postedOperatingLocations 
        as $countySlug => $location 
    ) { 
 
        if (!is_string($countySlug)) { 
            continue; 
        } 
 
        if (!isset($countyMap[$countySlug])) { 
            continue; 
        } 
 
        if (!is_array($location)) { 
            $location = []; 
        } 
 
        $operatingLocations[$countySlug] = [ 
 
            'county' => 
                $countyMap[$countySlug], 
 
            'sub_county' => 
                trim( 
                    (string) ( 
                        $location['sub_county'] 
                        ?? '' 
                    ) 
                ), 
 
            'physical_address' => 
                trim( 
                    (string) ( 
                        $location['physical_address'] 
                        ?? '' 
                    ) 
                ), 
 
            'postal_address' => 
                trim( 
                    (string) ( 
                        $location['postal_address'] 
                        ?? '' 
                    ) 
                ), 
 
            'postal_code' => 
                trim( 
                    (string) ( 
                        $location['postal_code'] 
                        ?? '' 
                    ) 
                ), 
 
            'phone_number' => 
                trim( 
                    (string) ( 
                        $location['phone_number'] 
                        ?? '' 
                    ) 
                ), 
 
            'alternative_phone_number' => 
                trim( 
                    (string) ( 
                        $location['alternative_phone_number'] 
                        ?? '' 
                    ) 
                ), 
 
            'email' => 
                trim( 
                    (string) ( 
                        $location['email'] 
                        ?? '' 
                    ) 
                ), 
        ]; 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Validation 
    |-------------------------------------------------------------------------- 
    */ 
 
    if ($isWizardNavigation) {

        /*
        |--------------------------------------------------------------------------
        | Wizard navigation validation
        |--------------------------------------------------------------------------
        | Only the original first-step fields are required to move between
        | wizard pages: organization name, PBO registration number and type.
        | Address, registration date, international details and operating
        | locations are validated during normal Save & Continue.
        */

        if ($form['pbo_name'] === '') {
            $errors[] =
                'Name of the Public Benefit Organization is required.';
        }

        if ($form['pbo_registration_number'] === '') {
            $errors[] =
                'PBO Registration Number is required.';
        }

        if (!in_array(
            $form['registration_type'],
            ['national', 'international'],
            true
        )) {
            $errors[] =
                'Please select the Type of Organization.';
        }

    } else { 
 
        /* 
        |-------------------------------------------------------------------------- 
        | Organization validation 
        |-------------------------------------------------------------------------- 
        */ 
 
        if ($form['pbo_name'] === '') { 
 
            $errors[] = 
                'Name of the Public Benefit Organization is required.'; 
        } 
 
        if ($form['pbo_registration_number'] === '') { 
 
            $errors[] = 
                'PBO Registration Number is required.'; 
        } 
 
        if ($form['registration_date'] === '') { 
 
            $errors[] = 
                'Registration Date is required.'; 
        } 
 
        if ( 
            !in_array( 
                $form['registration_type'], 
                [ 
                    'national', 
                    'international' 
                ], 
                true 
            ) 
        ) { 
 
            $errors[] = 
                'Please select the Type of Organization.'; 
        } 
 
        /* 
        |-------------------------------------------------------------------------- 
        | Headquarters required fields 
        |-------------------------------------------------------------------------- 
        */ 
 
        if ($form['county'] === '') { 
 
            $errors[] = 
                'Head Office County is required.'; 
        } 
 
        if ($form['physical_address'] === '') { 
 
            $errors[] = 
                'Head Office Physical Address is required.'; 
        } 
 
        if ($form['postal_address'] === '') { 
 
            $errors[] = 
                'Head Office Postal Address is required.'; 
        } 
 
        if ($form['phone_number'] === '') { 
 
            $errors[] = 
                'Head Office Organization Phone Number is required.'; 
        } 
 
        if ($form['email'] === '') { 
 
            $errors[] = 
                'Head Office Email of the Organization is required.'; 
 
        } elseif ( 
            !filter_var( 
                $form['email'], 
                FILTER_VALIDATE_EMAIL 
            ) 
        ) { 
 
            $errors[] = 
                'Please enter a valid Head Office organization email address.'; 
        } 
 
        /* 
        |-------------------------------------------------------------------------- 
        | National operating locations 
        |-------------------------------------------------------------------------- 
        */ 
 
        if ( 
            $form['registration_type'] 
            === 
            'national' 
        ) { 
 
            if ( 
                $operatesOtherLocations 
                === 
                'yes' 
            ) { 
 
                if ( 
                    count($operatingLocations) 
                    === 
                    0 
                ) { 
 
                    $errors[] = 
                        'Please select at least one county for the other operating locations.'; 
                } 
 
                foreach ( 
                    $operatingLocations 
                    as $countySlug => $location 
                ) { 
 
                    $selectedCounty = 
                        $countyMap[$countySlug] 
                        ?? ''; 
 
                    if ( 
                        $selectedCounty === '' 
                    ) { 
                        continue; 
                    } 
 
                    /* 
                    |-------------------------------------------------------------------------- 
                    | Prevent duplicate headquarters county 
                    |-------------------------------------------------------------------------- 
                    */ 
 
                    if ( 
                        normalizeCountyName($selectedCounty) !== '' && normalizeCountyName($selectedCounty) === normalizeCountyName($form['county']) 
                    ) { 
 
                        $errors[] = 
                            'The Head Office County cannot also be selected as an additional operating location: ' . 
                            $selectedCounty . 
                            '.'; 
                    } 
 
                    /* 
                    |-------------------------------------------------------------------------- 
                    | Required operating-location fields 
                    |-------------------------------------------------------------------------- 
                    */ 
 
                    if ( 
                        $location['sub_county'] 
                        === 
                        '' 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Sub-County is required.'; 
                    } 
 
                    if ( 
                        $location['physical_address'] 
                        === 
                        '' 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Physical Address is required.'; 
                    } 
 
                    if ( 
                        $location['postal_address'] 
                        === 
                        '' 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Postal Address is required.'; 
                    } 
 
                    if ( 
                        $location['phone_number'] 
                        === 
                        '' 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Organization Phone Number is required.'; 
                    } 
 
                    if ( 
                        $location['email'] 
                        === 
                        '' 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Email of the Organization is required.'; 
 
                    } elseif ( 
                        !filter_var( 
                            $location['email'], 
                            FILTER_VALIDATE_EMAIL 
                        ) 
                    ) { 
 
                        $errors[] = 
                            $selectedCounty . 
                            ': Please enter a valid organization email address.'; 
                    } 
                } 
            } 
 
        } else { 
 
            /* 
            |-------------------------------------------------------------------------- 
            | International 
            |-------------------------------------------------------------------------- 
            */ 
 
            $operatingLocations = []; 
            $operatesOtherLocations = 'no'; 
 
            $internationalRequiredFields = [ 
 
 
                'parent_organization_name' => 
                    'Parent Organization Name', 
 
                'country_of_origin' => 
                    'Country of Origin', 
 
                'registration_number' => 
                    'Registration Number', 
 
                'parent_physical_address' => 
                    'Parent Physical Address', 
 
                'parent_postal_address' => 
                    'Parent Postal Address', 
 
                'parent_postal_code' => 
                    'Parent Postal Code', 
 
                'parent_phone_number' => 
                    'Parent Phone Number', 
 
                'parent_email' => 
                    'Parent Email', 
 
                'contact_person_name' => 
                    'Contact Person Name', 
 
                'contact_person_phone' => 
                    'Contact Person Phone', 
 
                'contact_person_email' => 
                    'Contact Person Email', 
            ]; 
 
            foreach ( 
                $internationalRequiredFields 
                as $field => $label 
            ) { 
 
                if ( 
                    ($form[$field] ?? '') 
                    === 
                    '' 
                ) { 
 
                    $errors[] = 
                        $label . 
                        ' is required.'; 
                } 
            } 
 
            $internationalEmails = [ 
 
                'parent_email' => 
                    'Parent Email', 
 
                'contact_person_email' => 
                    'Contact Person Email', 
            ]; 
 
            foreach ( 
                $internationalEmails 
                as $field => $label 
            ) { 
 
                if ( 
                    ($form[$field] ?? '') 
                    !== 
                    '' 
                    && 
                    !filter_var( 
                        $form[$field], 
                        FILTER_VALIDATE_EMAIL 
                    ) 
                ) { 
 
                    $errors[] = 
                        $label . 
                        ' must be a valid email address.'; 
                } 
            } 
        } 
    } 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Save 
    |-------------------------------------------------------------------------- 
    */ 
 
    if (count($errors) === 0) { 
 
        try { 
 
            $connection->beginTransaction(); 
 
            /* 
            |-------------------------------------------------------------------------- 
            | Create 
            |-------------------------------------------------------------------------- 
            */ 
 
            $targetStep = $isWizardNavigation
                ? max(1, min(6, $wizardDestination))
                : 2;

            if (!$isEdit) { 
 
                $referenceNumber = 
                    generateApplicationReference( 
                        $connection 
                    ); 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO pbo_applications 
                    ( 
                        application_reference, 
                        pbo_name, 
                        pbo_registration_number, 
                        registration_date, 
                        registration_type, 
                        status, 
                        current_step, 
                        created_by, 
                        updated_by, 
                        created_at, 
                        updated_at 
                    ) 
                    VALUES 
                    ( 
                        :application_reference, 
                        :pbo_name, 
                        :pbo_registration_number, 
                        :registration_date, 
                        :registration_type, 
                        'draft', 
                        :current_step, 
                        :created_by, 
                        :updated_by, 
                        NOW(), 
                        NOW() 
                    ) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_reference' => 
                        $referenceNumber, 
 
                    ':pbo_name' => 
                        $form['pbo_name'], 
 
                    ':pbo_registration_number' => 
                        $form['pbo_registration_number'], 
 
                    ':registration_date' =>
                        $form['registration_date'] !== ''
                            ? $form['registration_date']
                            : null, 
 
                    ':registration_type' => 
                        $form['registration_type'], 
 

                    ':current_step' => $targetStep,

                    ':created_by' => 
                        $userId, 
 
                    ':updated_by' => 
                        $userId, 
                ]); 
 
                $applicationId = 
                    (int) $connection->lastInsertId(); 
 
                /* 
                |-------------------------------------------------------------------------- 
                | Headquarters address 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO application_addresses 
                    ( 
                        application_id, 
                        county, 
                        city_town, 
                        sub_county, 
                        physical_address, 
                        postal_address, 
                        postal_code, 
                        phone_number, 
                        alternative_phone_number, 
                        email 
                    ) 
                    VALUES 
                    ( 
                        :application_id, 
                        :county, 
                        :city_town, 
                        :sub_county, 
                        :physical_address, 
                        :postal_address, 
                        :postal_code, 
                        :phone_number, 
                        :alternative_phone_number, 
                        :email 
                    ) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
 
                    ':county' =>
                        $form['county'], 
 
                    ':city_town' => 
                        $form['city_town'] !== '' 
                            ? $form['city_town'] 
                            : null, 
 
                    ':sub_county' => 
                        $form['sub_county'] !== '' 
                            ? $form['sub_county'] 
                            : null, 
 
                    ':physical_address' =>
                        $form['physical_address'], 
 
                    ':postal_address' =>
                        $form['postal_address'], 
 
                    ':postal_code' => 
                        $form['postal_code'] !== '' 
                            ? $form['postal_code'] 
                            : null, 
 
                    ':phone_number' =>
                        $form['phone_number'], 
 
                    ':alternative_phone_number' => 
                        $form['alternative_phone_number'] !== '' 
                            ? $form['alternative_phone_number'] 
                            : null, 
 
                    ':email' =>
                        $form['email'], 
                ]); 
 
            } else { 
 
                /* 
                |-------------------------------------------------------------------------- 
                | Lock application 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    SELECT 
                        id, 
                        status 
                    FROM pbo_applications 
                    WHERE id = :id 
                      AND created_by = :created_by 
                    FOR UPDATE 
                    " 
                ); 
 
                $statement->execute([ 
                    ':id' => 
                        $applicationId, 
 
                    ':created_by' => 
                        $userId, 
                ]); 
 
                $lockedApplication = 
                    $statement->fetch(PDO::FETCH_ASSOC); 
 
                if ( 
                    !$lockedApplication 
                    || 
                    ( 
                        $lockedApplication['status'] 
                        ?? 
                        '' 
                    ) !== 'draft' 
                ) { 
 
                    throw new RuntimeException( 
                        'This application can no longer be edited.' 
                    ); 
                } 
 
                /* 
                |-------------------------------------------------------------------------- 
                | Update application 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    UPDATE pbo_applications 
                    SET 
                        pbo_name = 
                            :pbo_name, 
 
                        pbo_registration_number = 
                            :pbo_registration_number, 
 
                        registration_date = 
                            :registration_date, 
 
                        registration_type = 
                            :registration_type, 
 
                        current_step =
                            :current_step,

                        updated_by = 
                            :updated_by, 
 
                        updated_at = 
                            NOW() 
 
                    WHERE id = 
                        :id 
 
                      AND created_by = 
                        :created_by 
                    " 
                ); 
 
                $statement->execute([ 
                    ':pbo_name' => 
                        $form['pbo_name'], 
 
                    ':pbo_registration_number' => 
                        $form['pbo_registration_number'], 
 
                    ':registration_date' =>
                        $form['registration_date'] !== ''
                            ? $form['registration_date']
                            : null, 
 
                    ':registration_type' => 
                        $form['registration_type'], 
 

                    ':current_step' => $targetStep,

                    ':updated_by' => 
                        $userId, 
 
                    ':id' => 
                        $applicationId, 
 
                    ':created_by' => 
                        $userId, 
                ]); 
 
                /* 
                |-------------------------------------------------------------------------- 
                | Headquarters address 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO application_addresses 
                    ( 
                        application_id, 
                        county, 
                        city_town, 
                        sub_county, 
                        physical_address, 
                        postal_address, 
                        postal_code, 
                        phone_number, 
                        alternative_phone_number, 
                        email 
                    ) 
                    VALUES 
                    ( 
                        :application_id, 
                        :county, 
                        :city_town, 
                        :sub_county, 
                        :physical_address, 
                        :postal_address, 
                        :postal_code, 
                        :phone_number, 
                        :alternative_phone_number, 
                        :email 
                    ) 
                    ON DUPLICATE KEY UPDATE 
 
                        county = 
                            VALUES(county), 
 
                        city_town = 
                            VALUES(city_town), 
 
                        sub_county = 
                            VALUES(sub_county), 
 
                        physical_address = 
                            VALUES(physical_address), 
 
                        postal_address = 
                            VALUES(postal_address), 
 
                        postal_code = 
                            VALUES(postal_code), 
 
                        phone_number = 
                            VALUES(phone_number), 
 
                        alternative_phone_number = 
                            VALUES(alternative_phone_number), 
 
                        email = 
                            VALUES(email) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
 
                    ':county' =>
                        $form['county'], 
 
                    ':city_town' => 
                        $form['city_town'] !== '' 
                            ? $form['city_town'] 
                            : null, 
 
                    ':sub_county' => 
                        $form['sub_county'] !== '' 
                            ? $form['sub_county'] 
                            : null, 
 
                    ':physical_address' =>
                        $form['physical_address'], 
 
                    ':postal_address' =>
                        $form['postal_address'], 
 
                    ':postal_code' => 
                        $form['postal_code'] !== '' 
                            ? $form['postal_code'] 
                            : null, 
 
                    ':phone_number' =>
                        $form['phone_number'], 
 
                    ':alternative_phone_number' => 
                        $form['alternative_phone_number'] !== '' 
                            ? $form['alternative_phone_number'] 
                            : null, 
 
                    ':email' =>
                        $form['email'], 
                ]); 
            } 
 
            /* 
            |-------------------------------------------------------------------------- 
            | International details 
            |-------------------------------------------------------------------------- 
            */ 
 
            if ( 
                $form['registration_type'] 
                === 
                'international' 
            ) { 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO application_international_details 
                    ( 
                        application_id, 
                        registration_status, 
                        parent_organization_name, 
                        country_of_origin, 
                        registration_number, 
                        parent_physical_address, 
                        parent_postal_address, 
                        parent_postal_code, 
                        parent_phone_number, 
                        parent_email, 
                        contact_person_name, 
                        contact_person_phone, 
                        contact_person_email 
                    ) 
                    VALUES 
                    ( 
                        :application_id, 
                        :registration_status, 
                        :parent_organization_name, 
                        :country_of_origin, 
                        :registration_number, 
                        :parent_physical_address, 
                        :parent_postal_address, 
                        :parent_postal_code, 
                        :parent_phone_number, 
                        :parent_email, 
                        :contact_person_name, 
                        :contact_person_phone, 
                        :contact_person_email 
                    ) 
                    ON DUPLICATE KEY UPDATE 
 
                        registration_status = 
                            VALUES(registration_status), 
 
                        parent_organization_name = 
                            VALUES(parent_organization_name), 
 
                        country_of_origin = 
                            VALUES(country_of_origin), 
 
                        registration_number = 
                            VALUES(registration_number), 
 
                        parent_physical_address = 
                            VALUES(parent_physical_address), 
 
                        parent_postal_address = 
                            VALUES(parent_postal_address), 
 
                        parent_postal_code = 
                            VALUES(parent_postal_code), 
 
                        parent_phone_number = 
                            VALUES(parent_phone_number), 
 
                        parent_email = 
                            VALUES(parent_email), 
 
                        contact_person_name = 
                            VALUES(contact_person_name), 
 
                        contact_person_phone = 
                            VALUES(contact_person_phone), 
 
                        contact_person_email = 
                            VALUES(contact_person_email) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
 
                    ':registration_status' => 
                        $form['registration_status'], 
 
                    ':parent_organization_name' => 
                        $form['parent_organization_name'], 
 
                    ':country_of_origin' => 
                        $form['country_of_origin'], 
 
                    ':registration_number' => 
                        $form['registration_number'], 
 
                    ':parent_physical_address' => 
                        $form['parent_physical_address'], 
 
                    ':parent_postal_address' => 
                        $form['parent_postal_address'], 
 
                    ':parent_postal_code' => 
                        $form['parent_postal_code'], 
 
                    ':parent_phone_number' => 
                        $form['parent_phone_number'], 
 
                    ':parent_email' => 
                        $form['parent_email'], 
 
                    ':contact_person_name' => 
                        $form['contact_person_name'], 
 
                    ':contact_person_phone' => 
                        $form['contact_person_phone'], 
 
                    ':contact_person_email' => 
                        $form['contact_person_email'], 
                ]); 
 
                /* 
                |-------------------------------------------------------------------------- 
                | International organizations have no operating locations 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    DELETE FROM application_operating_locations 
                    WHERE application_id = 
                        :application_id 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
                ]); 
 
            } else { 
 
                /* 
                |-------------------------------------------------------------------------- 
                | National organizations have no international details 
                |-------------------------------------------------------------------------- 
                */ 
 
                $statement = $connection->prepare( 
                    " 
                    DELETE FROM application_international_details 
                    WHERE application_id = 
                        :application_id 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
                ]); 
 
                /* 
                |-------------------------------------------------------------------------- 
                | Operating locations 
                |-------------------------------------------------------------------------- 
                */ 
 
                if ( 
                    $operatesOtherLocations 
                    === 
                    'no' 
                ) { 
 
                    $statement = $connection->prepare( 
                        " 
                        DELETE FROM application_operating_locations 
                        WHERE application_id = 
                            :application_id 
                        " 
                    ); 
 
                    $statement->execute([ 
                        ':application_id' => 
                            $applicationId, 
                    ]); 
 
                } else { 
 
                    /* 
                    |-------------------------------------------------------------------------- 
                    | Delete counties no longer selected 
                    |-------------------------------------------------------------------------- 
                    */ 
 
                    $selectedCounties = []; 
 
                    foreach ( 
                        $operatingLocations 
                        as $countySlug => $location 
                    ) { 
 
                        if ( 
                            isset( 
                                $countyMap[$countySlug] 
                            ) 
                        ) { 
 
                            $selectedCounties[] = 
                                $countyMap[$countySlug]; 
                        } 
                    } 
 
                    if ( 
                        count($selectedCounties) 
                        === 
                        0 
                    ) { 
 
                        $statement = $connection->prepare( 
                            " 
                            DELETE FROM application_operating_locations 
                            WHERE application_id = 
                                :application_id 
                            " 
                        ); 
 
                        $statement->execute([ 
                            ':application_id' => 
                                $applicationId, 
                        ]); 
 
                    } else { 
 
                        $placeholders = []; 
 
                        $params = [ 
                            ':application_id' => 
                                $applicationId, 
                        ]; 
 
                        foreach ( 
                            $selectedCounties 
                            as $index => $county 
                        ) { 
 
                            $parameter = 
                                ':county_' . $index; 
 
                            $placeholders[] = 
                                $parameter; 
 
                            $params[$parameter] = 
                                $county; 
                        } 
 
                        $statement = $connection->prepare( 
                            " 
                            DELETE FROM application_operating_locations 
                            WHERE application_id = 
                                :application_id 
 
                              AND county NOT IN 
                                (" . 
                                implode( 
                                    ',', 
                                    $placeholders 
                                ) . 
                                ") 
                            " 
                        ); 
 
                        $statement->execute( 
                            $params 
                        ); 
                    } 
 
                    /* 
                    |-------------------------------------------------------------------------- 
                    | Save selected operating locations 
                    |-------------------------------------------------------------------------- 
                    */ 
 
                    foreach ( 
                        $operatingLocations 
                        as $countySlug => $location 
                    ) { 
 
                        $county = 
                            $countyMap[$countySlug]; 
 
                        $statement = $connection->prepare( 
                            " 
                            INSERT INTO application_operating_locations 
                            ( 
                                application_id, 
                                county, 
                                sub_county, 
                                physical_address, 
                                phone_number, 
                                alternative_phone_number, 
                                email, 
                                postal_address, 
                                postal_code 
                            ) 
                            VALUES 
                            ( 
                                :application_id, 
                                :county, 
                                :sub_county, 
                                :physical_address, 
                                :phone_number, 
                                :alternative_phone_number, 
                                :email, 
                                :postal_address, 
                                :postal_code 
                            ) 
                            ON DUPLICATE KEY UPDATE 
 
                                sub_county = 
                                    VALUES(sub_county), 
 
                                physical_address = 
                                    VALUES(physical_address), 
 
                                phone_number = 
                                    VALUES(phone_number), 
 
                                alternative_phone_number = 
                                    VALUES(alternative_phone_number), 
 
                                email = 
                                    VALUES(email), 
 
                                postal_address = 
                                    VALUES(postal_address), 
 
                                postal_code = 
                                    VALUES(postal_code) 
                            " 
                        ); 
 
                        $statement->execute([ 
                            ':application_id' => 
                                $applicationId, 
 
                            ':county' => 
                                $county, 
 
                            ':sub_county' => 
                                $location['sub_county'] 
                                !== '' 
                                    ? $location['sub_county'] 
                                    : null, 
 
                            ':physical_address' => 
                                $location['physical_address'] 
                                !== '' 
                                    ? $location['physical_address'] 
                                    : null, 
 
                            ':phone_number' => 
                                $location['phone_number'] 
                                !== '' 
                                    ? $location['phone_number'] 
                                    : null, 
 
                            ':alternative_phone_number' => 
                                $location[ 
                                    'alternative_phone_number' 
                                ] !== '' 
                                    ? $location[ 
                                        'alternative_phone_number' 
                                    ] 
                                    : null, 
 
                            ':email' => 
                                $location['email'] 
                                !== '' 
                                    ? $location['email'] 
                                    : null, 
 
                            ':postal_address' => 
                                $location['postal_address'] 
                                !== '' 
                                    ? $location['postal_address'] 
                                    : null, 
 
                            ':postal_code' => 
                                $location['postal_code'] 
                                !== '' 
                                    ? $location['postal_code'] 
                                    : null, 
                        ]); 
                    } 
                } 
            } 
 
            /* 
            |-------------------------------------------------------------------------- 
            | Audit 
            |-------------------------------------------------------------------------- 
            */ 
 
            if (!$isEdit) { 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO application_audit_log 
                    ( 
                        application_id, 
                        user_id, 
                        action, 
                        description, 
                        created_at 
                    ) 
                    VALUES 
                    ( 
                        :application_id, 
                        :user_id, 
                        'APPLICATION_CREATED', 
                        :description, 
                        NOW() 
                    ) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
 
                    ':user_id' => 
                        $userId, 
 
                    ':description' => 
                        'Application created.', 
                ]); 
 
            } else { 
 
                $statement = $connection->prepare( 
                    " 
                    INSERT INTO application_audit_log 
                    ( 
                        application_id, 
                        user_id, 
                        action, 
                        description, 
                        created_at 
                    ) 
                    VALUES 
                    ( 
                        :application_id, 
                        :user_id, 
                        'ORGANIZATION_DETAILS_UPDATED', 
                        :description, 
                        NOW() 
                    ) 
                    " 
                ); 
 
                $statement->execute([ 
                    ':application_id' => 
                        $applicationId, 
 
                    ':user_id' => 
                        $userId, 
 
                    ':description' => 
                        'Organization details, headquarters and operating locations updated.', 
                ]); 
            } 
 
            $connection->commit(); 
 
            /* 
            |-------------------------------------------------------------------------- 
            | Wizard navigation 
            |-------------------------------------------------------------------------- 
            */ 
 
            if ($isWizardNavigation) { 
 
                $wizardPages = [ 
                    1 => 'create.php', 
                    2 => 'objectives.php', 
                    3 => 'beneficiaries.php', 
                    4 => 'financial-year.php', 
                    5 => 'officials.php', 
                    6 => 'review.php', 
                ]; 
 
                $destinationPage = 
                    $wizardPages[ 
                        $wizardDestination 
                    ] 
                    ?? 'create.php'; 
 
                header( 
                    'Location: /pbo-portal/applications/' . 
                    $destinationPage . 
                    '?id=' . 
                    urlencode( 
                        (string) $applicationId 
                    ) 
                ); 
 
                exit; 
            } 
 
            header( 
                'Location: /pbo-portal/applications/objectives.php?id=' . 
                urlencode( 
                    (string) $applicationId 
                ) 
            ); 
 
            exit; 
 
        } catch (Throwable $exception) { 
 
            if ($connection->inTransaction()) { 
                $connection->rollBack(); 
            } 
 
            error_log( 
                'PBO application save error: ' . 
                $exception->getMessage() 
            ); 
 
            $errors[] = 
                'The application could not be saved. ' . 
                'Please check the information entered and try again.'; 
        } 
    } 
} 
 
require_once __DIR__ . '/../includes/header.php'; 
?> 
 
<style> 
/* 
|-------------------------------------------------------------------------- 
| Scoped improvements for the application form 
|-------------------------------------------------------------------------- 
| 
| These styles only affect this page and preserve the existing 
| application-page design language. 
| 
*/ 
 
.applications-page { 
    max-width: 1180px; 
    margin: 0 auto; 
    padding: 0 1rem 3rem; 
} 
 
.applications-page .application-section { 
    background: #ffffff; 
    border: 1px solid rgba(30, 50, 80, 0.10); 
    border-radius: 10px; 
    padding: 2rem; 
    margin-bottom: 1.5rem; 
    box-shadow: 0 2px 8px rgba(20, 35, 55, 0.05); 
} 
 
.applications-page .application-section-heading { 
    margin-bottom: 1.5rem; 
} 
 
.applications-page .application-section-heading h2, 
.applications-page .application-section-heading h3 { 
    margin-top: 0; 
    margin-bottom: 0.35rem; 
} 
 
.applications-page .application-section-heading p { 
    margin: 0; 
    line-height: 1.55; 
} 
 
.applications-page .form-group { 
    margin-bottom: 1.25rem; 
} 
 
.applications-page .form-group label { 
    display: block; 
    margin-bottom: 0.45rem; 
    font-weight: 600; 
} 
 
.applications-page .form-help { 
    line-height: 1.55; 
    margin-top: 0.35rem; 
} 
 
.applications-page input[type="text"], 
.applications-page input[type="date"], 
.applications-page input[type="email"], 
.applications-page input[type="tel"], 
.applications-page select, 
.applications-page textarea { 
    width: 100%; 
    box-sizing: border-box; 
} 
 
.applications-page .headquarters-address-heading { 
    margin-top: 0.75rem; 
    margin-bottom: 1.5rem; 
    padding-bottom: 0.8rem; 
    border-bottom: 1px solid rgba(30, 50, 80, 0.10); 
} 
 
.applications-page .headquarters-address-heading h3 { 
    margin: 0 0 0.35rem; 
} 
 
.applications-page .headquarters-address-heading p { 
    margin: 0; 
} 
 
.applications-page .other-locations-container { 
    margin-top: 2rem; 
    padding-top: 1.75rem; 
    border-top: 1px solid rgba(30, 50, 80, 0.12); 
} 
 
.applications-page .county-selection-grid { 
    background: #f8fafc; 
    border: 1px solid rgba(30, 50, 80, 0.10); 
    border-radius: 8px; 
    padding: 1rem; 
} 
 
.applications-page .operating-county-option { 
    min-height: 42px; 
    padding: 0.55rem 0.65rem; 
    border-radius: 6px; 
    box-sizing: border-box; 
} 
 
.applications-page .operating-county-option:hover { 
    background: rgba(30, 50, 80, 0.04); 
} 
 
.applications-page .operating-location-block { 
    background: #ffffff; 
    border: 1px solid rgba(30, 50, 80, 0.12); 
    border-left: 4px solid rgba(30, 50, 80, 0.45); 
    box-shadow: 0 2px 7px rgba(20, 35, 55, 0.04); 
} 
 
.applications-page .operating-location-block 
.application-section-heading { 
    padding-bottom: 0.9rem; 
    border-bottom: 1px solid rgba(30, 50, 80, 0.09); 
} 
 
.applications-page .required { 
    font-weight: 700; 
} 
 
.applications-page .alert { 
    margin-bottom: 1.5rem; 
} 
 
.applications-page .alert ul { 
    margin-bottom: 0; 
} 
 
@media (max-width: 760px) { 
 
    .applications-page .application-section { 
        padding: 1.25rem; 
    } 
 
    .applications-page .form-row { 
        display: block; 
    } 
 
    .applications-page .county-selection-grid { 
        grid-template-columns: 1fr !important; 
    } 
} 
</style> 
 
 
<div class="applications-page"> 
 
    <div class="page-header"> 
 
        <div> 
 
            <div class="section-eyebrow"> 
                PUBLIC BENEFIT ORGANIZATIONS 
            </div> 
 
            <h1> 
                PBO Application 
            </h1> 
 
            <p> 
                Enter the organization's registration and address details. 
            </p> 
 
        </div> 
 
    </div> 
 
 
    <?php require_once __DIR__ . '/../includes/application-wizard.php'; ?> 
 
 
    <?php if (count($errors) > 0): ?> 
 
        <div class="alert alert-danger" role="alert"> 
 
            <strong> 
                Please correct the following: 
            </strong> 
 
            <ul> 
 
                <?php foreach ($errors as $error): ?> 
 
                    <li> 
                        <?= e($error) ?> 
                    </li> 
 
                <?php endforeach; ?> 
 
            </ul> 
 
        </div> 
 
    <?php endif; ?> 
 
 
    <form 
        method="post" 
        id="application-form" 
        novalidate 
    > 
 
        <input 
            type="hidden" 
            name="csrf_token" 
            value="<?= e($csrfToken) ?>" 
        > 
 
        <?php if ($applicationId > 0): ?> 
 
            <input 
                type="hidden" 
                name="id" 
                value="<?= (int) $applicationId ?>" 
            > 
 
        <?php endif; ?> 
 
 
        <input 
            type="hidden" 
            name="form_action" 
            id="form_action" 
            value="save_continue" 
        > 
 
        <input 
            type="hidden" 
            name="wizard_destination" 
            id="wizard_destination" 
            value="" 
        > 
 
 
        <!-- ========================================================== 
             SECTION 1 
             ========================================================== --> 
 
        <section class="application-section"> 
 
            <div class="application-section-heading"> 
 
                <div class="application-section-number"> 
                    1 
                </div> 
 
                <div> 
 
                    <h2> 
                        Organization Details 
                    </h2> 
 
                    <p> 
                        Enter the basic information of the proposed Public Benefit Organization. 
                    </p> 
 
                </div> 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label for="pbo_name"> 
 
                    Name of Public Benefit Organization 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    id="pbo_name" 
                    name="pbo_name" 
                    value="<?= old($form, 'pbo_name') ?>" 
                    required 
                > 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="pbo_registration_number"> 
 
                        PBO Registration Number 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="pbo_registration_number" 
                        name="pbo_registration_number" 
                        value="<?= old($form, 'pbo_registration_number') ?>" 
                        required 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="registration_date"> 
 
                        Registration Date 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="date" 
                        id="registration_date" 
                        name="registration_date" 
                        value="<?= old($form, 'registration_date') ?>" 
                        required 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label> 
 
                    Type of Organization 
 
                    <span class="required">*</span> 
 
                </label> 
 
 
                <div class="radio-group"> 
 
                    <label class="radio-option"> 
 
                        <input 
                            type="radio" 
                            name="registration_type" 
                            value="national" 
                            <?= $form['registration_type'] === 'national' 
                                ? 'checked' 
                                : '' ?> 
                            required 
                        > 
 
                        <span> 
                            National 
                        </span> 
 
                    </label> 
 
 
                    <label class="radio-option"> 
 
                        <input 
                            type="radio" 
                            name="registration_type" 
                            value="international" 
                            <?= $form['registration_type'] === 'international' 
                                ? 'checked' 
                                : '' ?> 
                            required 
                        > 
 
                        <span> 
                            International 
                        </span> 
 
                    </label> 
 
                </div> 
 
            </div> 
 
        </section> 
 
 
        <!-- ========================================================== 
             SECTION 2 
             HEADQUARTERS 
             ========================================================== --> 
 
        <section class="application-section"> 
 
            <div class="application-section-heading"> 
 
                <div class="application-section-number"> 
                    2 
                </div> 
 
                <div> 
 
                    <h2> 
                        HEADQUARTERS 
                    </h2> 
 
                    <p> 
                        Enter the organization's principal physical, postal and contact information. 
                    </p> 
 
                </div> 
 
            </div> 
 
 
            <!-- HEAD OFFICE ADDRESS --> 
 
            <div class="headquarters-address-heading"> 
 
                <h3> 
                    Head Office Address 
                </h3> 
 
                <p> 
                    This is the principal physical location of the organization. 
                </p> 
 
            </div> 
 
 
            <!-- COUNTY + CITY/TOWN --> 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="county"> 
 
                        County 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <!-- 
                    County is intentionally a text field. 
                    Staff can type the county instead of scrolling. 
                    --> 
 
                    <input 
                        type="text" 
                        id="county" 
                        name="county" 
                        value="<?= old($form, 'county') ?>" 
                        placeholder="Enter County" 
                        required 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="city_town"> 
 
                        City / Town 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="city_town" 
                        name="city_town" 
                        value="<?= old($form, 'city_town') ?>" 
                        placeholder="Enter City / Town" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <!-- SUB COUNTY + POSTAL CODE --> 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="sub_county"> 
 
                        Sub-County 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="sub_county" 
                        name="sub_county" 
                        value="<?= old($form, 'sub_county') ?>" 
                        placeholder="Enter Sub-County" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="postal_code"> 
 
                        Postal Code 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="postal_code" 
                        name="postal_code" 
                        value="<?= old($form, 'postal_code') ?>" 
                        placeholder="Enter Postal Code" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <!-- PHYSICAL ADDRESS --> 
 
            <div class="form-group"> 
 
                <label for="physical_address"> 
 
                    Physical Address 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    id="physical_address" 
                    name="physical_address" 
                    value="<?= old($form, 'physical_address') ?>" 
                    placeholder="Enter Physical Address" 
                    required 
                > 
 
            </div> 
 
 
            <!-- POSTAL ADDRESS --> 
 
            <div class="form-group"> 
 
                <label for="postal_address"> 
 
                    Postal Address 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    id="postal_address" 
                    name="postal_address" 
                    value="<?= old($form, 'postal_address') ?>" 
                    placeholder="Enter Postal Address" 
                    required 
                > 
 
            </div> 
 
 
            <!-- PHONE NUMBERS --> 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="phone_number"> 
 
                        Organization Phone Number 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        id="phone_number" 
                        name="phone_number" 
                        value="<?= old($form, 'phone_number') ?>" 
                        placeholder="Enter Organization Phone Number" 
                        required 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="alternative_phone_number"> 
 
                        Alternative Phone Number 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        id="alternative_phone_number" 
                        name="alternative_phone_number" 
                        value="<?= old($form, 'alternative_phone_number') ?>" 
                        placeholder="Enter Alternative Phone Number" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <!-- EMAIL --> 
 
            <div class="form-group"> 
 
                <label for="email"> 
 
                    Email of the Organization 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    value="<?= old($form, 'email') ?>" 
                    placeholder="Enter Organization Email" 
                    required 
                > 
 
            </div> 
 
 
            <!-- ====================================================== 
                 OTHER OPERATING LOCATIONS 
                 ====================================================== --> 
 
            <div 
                id="other-locations-section" 
                class="other-locations-container" 
                style=" 
                    display: <?= $form['registration_type'] === 'national' 
                        ? 'block' 
                        : 'none' ?>; 
                " 
            > 
 
                <div class="application-section-heading"> 
 
                    <div> 
 
                        <h3> 
                            Other Areas of Operation 
                        </h3> 
 
                        <p> 
                            Does the organization operate from other locations? 
                        </p> 
 
                    </div> 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <div class="radio-group"> 
 
                        <label class="radio-option"> 
 
                            <input 
                                type="radio" 
                                name="operates_other_locations" 
                                value="yes" 
                                <?= $operatesOtherLocations === 'yes' 
                                    ? 'checked' 
                                    : '' ?> 
                            > 
 
                            <span> 
                                Yes 
                            </span> 
 
                        </label> 
 
 
                        <label class="radio-option"> 
 
                            <input 
                                type="radio" 
                                name="operates_other_locations" 
                                value="no" 
                                <?= $operatesOtherLocations !== 'yes' 
                                    ? 'checked' 
                                    : '' ?> 
                            > 
 
                            <span> 
                                No 
                            </span> 
 
                        </label> 
 
                    </div> 
 
                </div> 
 
 
                <div 
                    id="operating-counties-wrapper" 
                    style=" 
                        display: <?= $operatesOtherLocations === 'yes' 
                            ? 'block' 
                            : 'none' ?>; 
                    " 
                > 
 
                    <div class="form-group"> 
 
                        <label> 
                            Select Other Operating Locations 
                        </label> 
 
                        <p class="form-help"> 
                            Select the counties where the organization has other operating locations. 
                        </p> 
 
                    </div> 
 
 
                    <div 
                        class="county-selection-grid" 
                        id="county-selection-grid" 
                        style=" 
                            display: grid; 
                            grid-template-columns: 
                                repeat( 
                                    auto-fit, 
                                    minmax(190px, 1fr) 
                                ); 
                            gap: 0.5rem 1rem; 
                            margin-bottom: 1.5rem; 
                        " 
                    > 
 
                        <?php foreach ( 
                            $countyOptions 
                            as $county 
                        ): ?> 
 
                            <?php 
 
                            $countySlug = 
                                slugifyCounty( 
                                    $county 
                                ); 
 
                            $isSelected = 
                                isset( 
                                    $operatingLocations[ 
                                        $countySlug 
                                    ] 
                                ); 
 
                            $isHeadOffice = 
                                $form['county'] !== '' 
                                && 
                                strcasecmp( 
                                    trim( 
                                        $form['county'] 
                                    ), 
                                    trim( 
                                        $county 
                                    ) 
                                ) === 0; 
 
                            ?> 
 
                            <label 
                                class="radio-option operating-county-option" 
                                data-county-slug="<?= e($countySlug) ?>" 
                            > 
 
                                <input 
                                    type="checkbox" 
                                    name="operating_county_selection[]" 
                                    value="<?= e($countySlug) ?>" 
                                    data-operating-county="<?= e($countySlug) ?>" 
                                    <?= $isSelected 
                                        ? 'checked' 
                                        : '' ?> 
                                    <?= $isHeadOffice 
                                        ? 'disabled' 
                                        : '' ?> 
                                > 
 
                                <span> 
 
                                    <?= e($county) ?> 
 
                                    <?php if ( 
                                        $isHeadOffice 
                                    ): ?> 
 
                                        <small> 
                                            (Head Office) 
                                        </small> 
 
                                    <?php endif; ?> 
 
                                </span> 
 
                            </label> 
 
                        <?php endforeach; ?> 
 
                    </div> 
 
 
                    <div id="operating-location-fields"> 
 
                        <?php foreach ( 
                            $operatingLocations 
                            as $countySlug => $location 
                        ): ?> 
 
                            <?php 
 
                            $county = 
                                $countyMap[ 
                                    $countySlug 
                                ] 
                                ?? $location['county']; 
 
                            ?> 
 
                            <div 
                                class="application-section operating-location-block" 
                                data-operating-location="<?= e($countySlug) ?>" 
                            > 
 
                                <div class="application-section-heading"> 
 
                                    <div> 
 
                                        <h3> 
                                            <?= e($county) ?> 
                                            — Operating Location 
                                        </h3> 
 
                                        <p> 
                                            Enter the address and contact details for this operating location. 
                                        </p> 
 
                                    </div> 
 
                                </div> 
 
 
                                <input 
                                    type="hidden" 
                                    name="operating_locations[<?= e($countySlug) ?>][county]" 
                                    value="<?= e($county) ?>" 
                                > 
 
 
                                <!-- 
                                IMPORTANT: 
                                Operating locations start with 
                                Sub-County. City / Town is NOT included. 
                                --> 
 
                                <div class="form-row"> 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Sub-County 
 
                                            <span class="required">*</span> 
 
                                        </label> 
 
                                        <input 
                                            type="text" 
                                            name="operating_locations[<?= e($countySlug) ?>][sub_county]" 
                                            value="<?= e($location['sub_county']) ?>" 
                                            placeholder="Enter Sub-County" 
                                            required 
                                        > 
 
                                    </div> 
 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Physical Address 
 
                                            <span class="required">*</span> 
 
                                        </label> 
 
                                        <input 
                                            type="text" 
                                            name="operating_locations[<?= e($countySlug) ?>][physical_address]" 
                                            value="<?= e($location['physical_address']) ?>" 
                                            placeholder="Enter Physical Address" 
                                            required 
                                        > 
 
                                    </div> 
 
                                </div> 
 
 
                                <div class="form-group"> 
 
                                    <label> 
 
                                        Postal Address 
 
                                        <span class="required">*</span> 
 
                                    </label> 
 
                                    <input 
                                        type="text" 
                                        name="operating_locations[<?= e($countySlug) ?>][postal_address]" 
                                        value="<?= e($location['postal_address']) ?>" 
                                        placeholder="Enter Postal Address" 
                                        required 
                                    > 
 
                                </div> 
 
 
                                <div class="form-row"> 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Postal Code 
 
                                        </label> 
 
                                        <input 
                                            type="text" 
                                            name="operating_locations[<?= e($countySlug) ?>][postal_code]" 
                                            value="<?= e($location['postal_code']) ?>" 
                                            placeholder="Enter Postal Code" 
                                        > 
 
                                    </div> 
 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Organization Phone Number 
 
                                            <span class="required">*</span> 
 
                                        </label> 
 
                                        <input 
                                            type="tel" 
                                            name="operating_locations[<?= e($countySlug) ?>][phone_number]" 
                                            value="<?= e($location['phone_number']) ?>" 
                                            placeholder="Enter Organization Phone Number" 
                                            required 
                                        > 
 
                                    </div> 
 
                                </div> 
 
 
                                <div class="form-row"> 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Alternative Phone Number 
 
                                        </label> 
 
                                        <input 
                                            type="tel" 
                                            name="operating_locations[<?= e($countySlug) ?>][alternative_phone_number]" 
                                            value="<?= e($location['alternative_phone_number']) ?>" 
                                            placeholder="Enter Alternative Phone Number" 
                                        > 
 
                                    </div> 
 
 
                                    <div class="form-group"> 
 
                                        <label> 
 
                                            Email of the Organization 
 
                                            <span class="required">*</span> 
 
                                        </label> 
 
                                        <input 
                                            type="email" 
                                            name="operating_locations[<?= e($countySlug) ?>][email]" 
                                            value="<?= e($location['email']) ?>" 
                                            placeholder="Enter Organization Email" 
                                            required 
                                        > 
 
                                    </div> 
 
                                </div> 
 
                            </div> 
 
                        <?php endforeach; ?> 
 
                    </div> 
 
                </div> 
 
            </div> 
 
        </section> 
 
 
        <!-- ========================================================== 
             SECTION 3 
             INTERNATIONAL DETAILS 
             ========================================================== --> 
 
        <section 
            class="application-section" 
            id="international-section" 
            style=" 
                display: <?= $form['registration_type'] === 'international' 
                    ? 'block' 
                    : 'none' ?>; 
            " 
        > 
 
            <div class="application-section-heading"> 
 
                <div class="application-section-number"> 
                    3 
                </div> 
 
                <div> 
 
                    <h2> 
                        International Organization Details 
                    </h2> 
 
                    <p> 
                        Provide the registration and parent organization details. 
                    </p> 
 
                </div> 
 
            </div>  
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="parent_organization_name"> 
 
                        Parent Organization Name 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="parent_organization_name" 
                        name="parent_organization_name" 
                        value="<?= old( 
                            $form, 
                            'parent_organization_name' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="country_of_origin"> 
 
                        Country of Origin 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="country_of_origin" 
                        name="country_of_origin" 
                        value="<?= old( 
                            $form, 
                            'country_of_origin' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label for="registration_number"> 
 
                    Registration Number 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    id="registration_number" 
                    name="registration_number" 
                    value="<?= old( 
                        $form, 
                        'registration_number' 
                    ) ?>" 
                    data-international-required="true" 
                > 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label for="parent_physical_address"> 
 
                    Parent Physical Address 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    id="parent_physical_address" 
                    name="parent_physical_address" 
                    value="<?= old( 
                        $form, 
                        'parent_physical_address' 
                    ) ?>" 
                    data-international-required="true" 
                > 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="parent_postal_address"> 
 
                        Parent Postal Address 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="parent_postal_address" 
                        name="parent_postal_address" 
                        value="<?= old( 
                            $form, 
                            'parent_postal_address' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="parent_postal_code"> 
 
                        Parent Postal Code 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="parent_postal_code" 
                        name="parent_postal_code" 
                        value="<?= old( 
                            $form, 
                            'parent_postal_code' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="parent_phone_number"> 
 
                        Parent Phone Number 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        id="parent_phone_number" 
                        name="parent_phone_number" 
                        value="<?= old( 
                            $form, 
                            'parent_phone_number' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="parent_email"> 
 
                        Parent Email 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="email" 
                        id="parent_email" 
                        name="parent_email" 
                        value="<?= old( 
                            $form, 
                            'parent_email' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label for="contact_person_name"> 
 
                        Contact Person Name 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        id="contact_person_name" 
                        name="contact_person_name" 
                        value="<?= old( 
                            $form, 
                            'contact_person_name' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label for="contact_person_phone"> 
 
                        Contact Person Phone 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        id="contact_person_phone" 
                        name="contact_person_phone" 
                        value="<?= old( 
                            $form, 
                            'contact_person_phone' 
                        ) ?>" 
                        data-international-required="true" 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label for="contact_person_email"> 
 
                    Contact Person Email 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="email" 
                    id="contact_person_email" 
                    name="contact_person_email" 
                    value="<?= old( 
                        $form, 
                        'contact_person_email' 
                    ) ?>" 
                    data-international-required="true" 
                > 
 
            </div> 
 
        </section> 
 
 
        <!-- ========================================================== 
             ACTIONS 
             ========================================================== --> 
 
        <div class="application-actions"> 
 
            <button 
                type="submit" 
                class="btn btn-primary" 
                id="save-button" 
            > 
                Save &amp; Continue 
            </button> 
 
        </div> 
 
    </form> 
 
</div> 
 
 
<script> 
 
(function () { 
 
    'use strict'; 
 
 
    const form = 
        document.getElementById( 
            'application-form' 
        ); 
 
 
    if (!form) { 
        return; 
    } 
 
 
    const registrationTypeInputs = 
        document.querySelectorAll( 
            'input[name="registration_type"]' 
        ); 
 
 
    const internationalSection = 
        document.getElementById( 
            'international-section' 
        ); 
 
 
    const otherLocationsSection = 
        document.getElementById( 
            'other-locations-section' 
        ); 
 
 
    const operatingCountiesWrapper = 
        document.getElementById( 
            'operating-counties-wrapper' 
        ); 
 
 
    const operatingLocationFields = 
        document.getElementById( 
            'operating-location-fields' 
        ); 
 
 
    const countyInput = 
        document.getElementById( 
            'county' 
        ); 
 
 
    const countyMap = 
        <?= json_encode( 
            $countyMap, 
            JSON_UNESCAPED_UNICODE | 
            JSON_UNESCAPED_SLASHES 
        ) ?>; 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Escape HTML 
    |-------------------------------------------------------------------------- 
    */ 
 
    function escapeHtml(value) { 
 
        const div = 
            document.createElement( 
                'div' 
            ); 
 
        div.textContent = 
            String(value ?? ''); 
 
        return div.innerHTML; 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Registration type 
    |-------------------------------------------------------------------------- 
    */ 
 
    function getRegistrationType() { 
 
        const selected = 
            document.querySelector( 
                'input[name="registration_type"]:checked' 
            ); 
 
        return selected 
            ? selected.value 
            : ''; 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | International / National visibility 
    |-------------------------------------------------------------------------- 
    */ 
 
    function updateRegistrationType() { 
 
        const type = 
            getRegistrationType(); 
 
        const international = 
            type === 'international'; 
 
        const national = 
            type === 'national'; 
 
 
        if (internationalSection) { 
 
            internationalSection.style.display = 
                international 
                    ? 'block' 
                    : 'none'; 
 
        } 
 
 
        if (otherLocationsSection) { 
 
            otherLocationsSection.style.display = 
                national 
                    ? 'block' 
                    : 'none'; 
 
        } 
 
 
        document 
            .querySelectorAll( 
                '[data-international-required="true"]' 
            ) 
            .forEach( 
                function (field) { 
 
                    field.required = 
                        international; 
 
                } 
            ); 
 
 
        if (!national) { 
 
            const noRadio = 
                document.querySelector( 
                    'input[name="operates_other_locations"][value="no"]' 
                ); 
 
            if (noRadio) { 
                noRadio.checked = true; 
            } 
 
            if (operatingCountiesWrapper) { 
 
                operatingCountiesWrapper.style.display = 
                    'none'; 
 
            } 
 
        } else { 
 
            updateOtherLocationsVisibility(); 
 
        } 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Other locations visibility 
    |-------------------------------------------------------------------------- 
    */ 
 
    function updateOtherLocationsVisibility() { 
 
        const selected = 
            document.querySelector( 
                'input[name="operates_other_locations"]:checked' 
            ); 
 
        const show = 
            selected 
            && 
            selected.value === 'yes'; 
 
 
        if (operatingCountiesWrapper) { 
 
            operatingCountiesWrapper.style.display = 
                show 
                    ? 'block' 
                    : 'none'; 
 
        } 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Create operating location 
    |-------------------------------------------------------------------------- 
    */ 
 
    function createOperatingLocationBlock( 
        countySlug, 
        countyName, 
        existingValues 
    ) { 
 
        if (!operatingLocationFields) { 
            return; 
        } 
 
 
        let block = 
            operatingLocationFields.querySelector( 
                '[data-operating-location="' + 
                CSS.escape(countySlug) + 
                '"]' 
            ); 
 
 
        /* 
        |-------------------------------------------------------------------------- 
        | If the block already exists, simply show it again. 
        |-------------------------------------------------------------------------- 
        */ 
 
        if (block) { 
 
            block.style.display = 
                'block'; 
 
            block 
                .querySelectorAll( 
                    'input, select, textarea' 
                ) 
                .forEach( 
                    function (field) { 
 
                        field.disabled = 
                            false; 
 
                    } 
                ); 
 
            return; 
        } 
 
 
        existingValues = 
            existingValues || {}; 
 
 
        block = 
            document.createElement( 
                'div' 
            ); 
 
        block.className = 
            'application-section operating-location-block'; 
 
        block.dataset.operatingLocation = 
            countySlug; 
 
 
        block.innerHTML = ` 
 
            <div class="application-section-heading"> 
 
                <div> 
 
                    <h3> 
                        ${escapeHtml(countyName)} 
                        — Operating Location 
                    </h3> 
 
                    <p> 
                        Enter the address and contact details for this operating location. 
                    </p> 
 
                </div> 
 
            </div> 
 
 
            <input 
                type="hidden" 
                name="operating_locations[${escapeHtml(countySlug)}][county]" 
                value="${escapeHtml(countyName)}" 
            > 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Sub-County 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        name="operating_locations[${escapeHtml(countySlug)}][sub_county]" 
                        value="${escapeHtml(existingValues.sub_county || '')}" 
                        placeholder="Enter Sub-County" 
                        required 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Physical Address 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="text" 
                        name="operating_locations[${escapeHtml(countySlug)}][physical_address]" 
                        value="${escapeHtml(existingValues.physical_address || '')}" 
                        placeholder="Enter Physical Address" 
                        required 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-group"> 
 
                <label> 
 
                    Postal Address 
 
                    <span class="required">*</span> 
 
                </label> 
 
                <input 
                    type="text" 
                    name="operating_locations[${escapeHtml(countySlug)}][postal_address]" 
                    value="${escapeHtml(existingValues.postal_address || '')}" 
                    placeholder="Enter Postal Address" 
                    required 
                > 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Postal Code 
 
                    </label> 
 
                    <input 
                        type="text" 
                        name="operating_locations[${escapeHtml(countySlug)}][postal_code]" 
                        value="${escapeHtml(existingValues.postal_code || '')}" 
                        placeholder="Enter Postal Code" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Organization Phone Number 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        name="operating_locations[${escapeHtml(countySlug)}][phone_number]" 
                        value="${escapeHtml(existingValues.phone_number || '')}" 
                        placeholder="Enter Organization Phone Number" 
                        required 
                    > 
 
                </div> 
 
            </div> 
 
 
            <div class="form-row"> 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Alternative Phone Number 
 
                    </label> 
 
                    <input 
                        type="tel" 
                        name="operating_locations[${escapeHtml(countySlug)}][alternative_phone_number]" 
                        value="${escapeHtml(existingValues.alternative_phone_number || '')}" 
                        placeholder="Enter Alternative Phone Number" 
                    > 
 
                </div> 
 
 
                <div class="form-group"> 
 
                    <label> 
 
                        Email of the Organization 
 
                        <span class="required">*</span> 
 
                    </label> 
 
                    <input 
                        type="email" 
                        name="operating_locations[${escapeHtml(countySlug)}][email]" 
                        value="${escapeHtml(existingValues.email || '')}" 
                        placeholder="Enter Organization Email" 
                        required 
                    > 
 
                </div> 
 
            </div> 
        `; 
 
 
        operatingLocationFields.appendChild( 
            block 
        ); 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Hide operating location without deleting its entered values 
    |-------------------------------------------------------------------------- 
    */ 
 
    function hideOperatingLocationBlock( 
        countySlug 
    ) { 
 
        if (!operatingLocationFields) { 
            return; 
        } 
 
 
        const block = 
            operatingLocationFields.querySelector( 
                '[data-operating-location="' + 
                CSS.escape(countySlug) + 
                '"]' 
            ); 
 
 
        if (!block) { 
            return; 
        } 
 
 
        block.style.display = 
            'none'; 
 
 
        block 
            .querySelectorAll( 
                'input, select, textarea' 
            ) 
            .forEach( 
                function (field) { 
 
                    field.disabled = 
                        true; 
 
                } 
            ); 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | County selection 
    |-------------------------------------------------------------------------- 
    */ 
 
    document 
        .querySelectorAll( 
            'input[data-operating-county]' 
        ) 
        .forEach( 
            function (checkbox) { 
 
                checkbox.addEventListener( 
                    'change', 
                    function () { 
 
                        const countySlug = 
                            checkbox.dataset.operatingCounty; 
 
                        const countyName = 
                            countyMap[countySlug]; 
 
 
                        if (!countyName) { 
                            return; 
                        } 
 
 
                        if (checkbox.checked) { 
 
                            createOperatingLocationBlock( 
                                countySlug, 
                                countyName 
                            ); 
 
                        } else { 
 
                            hideOperatingLocationBlock( 
                                countySlug 
                            ); 
 
                        } 
 
                    } 
                ); 
 
            } 
        ); 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Head office county 
    |-------------------------------------------------------------------------- 
    | 
    | The head office county is now a text field. 
    | When staff enter a canonical county name, the matching checkbox 
    | is disabled. 
    | 
    */ 
 
    function updateHeadOfficeCounty() { 
 
        if (!countyInput) { 
            return; 
        } 
 
 
        const headOfficeCounty =
            countyInput.value
                .trim()
                .toLowerCase()
                .replace(/\s+county$/i, '')
                .replace(/[^a-z0-9]+/g, ''); 
 
 
        document 
            .querySelectorAll( 
                'input[data-operating-county]' 
            ) 
            .forEach( 
                function (checkbox) { 
 
                    const countySlug = 
                        checkbox.dataset.operatingCounty; 
 
                    const countyName = 
                        countyMap[countySlug] 
                        || ''; 
 
                    const normalizedCountyName =
                        countyName
                            .toLowerCase()
                            .replace(/\s+county$/i, '')
                            .replace(/[^a-z0-9]+/g, '');

                    const isHeadOffice =
                        headOfficeCounty !== ''
                        &&
                        normalizedCountyName === headOfficeCounty; 
 
 
                    checkbox.disabled = 
                        isHeadOffice; 
 
 
                    const label = 
                        checkbox.closest( 
                            '.operating-county-option' 
                        ); 
 
 
                    if (label) { 
 
                        const span = 
                            label.querySelector( 
                                'span' 
                            ); 
 
 
                        if ( 
                            span 
                            && 
                            !span.querySelector( 
                                'small[data-head-office-marker]' 
                            ) 
                        ) { 
 
                            const marker = 
                                document.createElement( 
                                    'small' 
                                ); 
 
                            marker.dataset.headOfficeMarker = 
                                'true'; 
 
                            marker.textContent = 
                                ' (Head Office)'; 
 
                            marker.style.display = 
                                isHeadOffice 
                                    ? 'inline' 
                                    : 'none'; 
 
                            span.appendChild( 
                                marker 
                            ); 
 
                        } 
 
                    } 
 
 
                    if (isHeadOffice) { 
 
                        if (checkbox.checked) { 
                            checkbox.checked = 
                                false; 
                        } 
 
                        hideOperatingLocationBlock( 
                            countySlug 
                        ); 
 
                    } 
 
                } 
            ); 
 
    } 
 
 
    if (countyInput) { 
 
        countyInput.addEventListener( 
            'input', 
            updateHeadOfficeCounty 
        ); 
 
        countyInput.addEventListener( 
            'change', 
            updateHeadOfficeCounty 
        ); 
 
    } 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Other locations radio buttons 
    |-------------------------------------------------------------------------- 
    */ 
 
    document 
        .querySelectorAll( 
            'input[name="operates_other_locations"]' 
        ) 
        .forEach( 
            function (radio) { 
 
                radio.addEventListener( 
                    'change', 
                    updateOtherLocationsVisibility 
                ); 
 
            } 
        ); 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Wizard links 
    |-------------------------------------------------------------------------- 
    */ 
 
    document 
        .querySelectorAll( 
            '[data-wizard-link="true"]' 
        ) 
        .forEach( 
            function (link) { 
 
                link.addEventListener( 
                    'click', 
                    function (event) { 
 
                        event.preventDefault(); 
 
 
                        const destination = 
                            parseInt( 
                                link.dataset 
                                    .wizardDestination 
                                    || 
                                    '1', 
                                10 
                            ); 
 
 
                        document.getElementById( 
                            'form_action' 
                        ).value = 
                            'wizard_navigation'; 
 
 
                        document.getElementById( 
                            'wizard_destination' 
                        ).value = 
                            String( 
                                destination 
                            ); 
 
 
                        /* 
                        |-------------------------------------------------------------------------- 
                        | Preserve original wizard behavior. 
                        |-------------------------------------------------------------------------- 
                        */ 
 
                        form.noValidate = 
                            true; 
 
 
                        form.submit(); 
 
                    } 
                ); 
 
            } 
        ); 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Normal submission 
    |-------------------------------------------------------------------------- 
    */ 
 
    form.addEventListener( 
        'submit', 
        function (event) { 
 
            const formAction = 
                document.getElementById( 
                    'form_action' 
                ).value; 
 
 
            if ( 
                formAction !== 
                'wizard_navigation' 
            ) { 
 
                form.noValidate = 
                    false; 
 
 
                if (!form.checkValidity()) { 
 
                    event.preventDefault(); 
 
                    form.reportValidity(); 
 
                    return; 
 
                } 
 
 
                const saveButton = 
                    document.getElementById( 
                        'save-button' 
                    ); 
 
 
                if (saveButton) { 
 
                    saveButton.disabled = 
                        true; 
 
                    saveButton.textContent = 
                        'Saving...'; 
 
                } 
 
            } 
 
        } 
    ); 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Initial state 
    |-------------------------------------------------------------------------- 
    */ 
 
    updateRegistrationType(); 
 
    updateHeadOfficeCounty(); 
 
    updateOtherLocationsVisibility(); 
 
 
    /* 
    |-------------------------------------------------------------------------- 
    | Restore existing selected operating locations 
    |-------------------------------------------------------------------------- 
    */ 
 
    document 
        .querySelectorAll( 
            'input[data-operating-county]:checked' 
        ) 
        .forEach( 
            function (checkbox) { 
 
                const countySlug = 
                    checkbox.dataset.operatingCounty; 
 
                const block = 
                    operatingLocationFields 
                        ?.querySelector( 
                            '[data-operating-location="' + 
                            CSS.escape( 
                                countySlug 
                            ) + 
                            '"]' 
                        ); 
 
 
                if (block) { 
 
                    block.style.display = 
                        'block'; 
 
 
                    block 
                        .querySelectorAll( 
                            'input, select, textarea' 
                        ) 
                        .forEach( 
                            function (field) { 
 
                                field.disabled = 
                                    false; 
 
                            } 
                        ); 
 
                } 
 
            } 
        ); 
 
})(); 
 
</script> 
 
 
<?php require_once __DIR__ . '/../includes/footer.php'; ?>    