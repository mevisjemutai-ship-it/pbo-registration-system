-- ---------------------------------------------------------------------------
-- Convert existing records to block capitals
-- ---------------------------------------------------------------------------
--
-- OPTIONAL, and NOT REVERSIBLE. Read this before running it.
--
-- New and edited records are stored in block capitals from now on, by the
-- portal itself. This script does the same to records entered BEFORE that
-- rule existed, so the register reads consistently all the way back.
--
-- It cannot be undone. Once "Murang'a" is "MURANG'A" the original casing is
-- gone, and so is the capital in names like McDonald or de Souza. Take a
-- backup first:
--
--     mysqldump <database> > before_capitals.sql
--
-- Email addresses are deliberately NOT touched here. They are lower-cased by
-- the portal on save instead, because the part before the @ is case-sensitive
-- by the standard and some mail servers honour that. Breaking delivery to
-- make a column look tidy is a bad trade.
--
-- Run:
--     mysql <database> < database/convert_to_block_capitals.sql
--
-- Running it twice does nothing the second time; UPPER() of capitals is the
-- same capitals.
-- ---------------------------------------------------------------------------

START TRANSACTION;

UPDATE pbo_applications
   SET pbo_name = UPPER(pbo_name),
       pbo_registration_number = UPPER(pbo_registration_number);

UPDATE application_addresses
   SET county = UPPER(county),
       town = UPPER(town),
       sub_county = UPPER(sub_county),
       physical_address = UPPER(physical_address),
       postal_address = UPPER(postal_address),
       postal_code = UPPER(postal_code);

UPDATE application_operating_locations
   SET county = UPPER(county),
       sub_county = UPPER(sub_county),
       physical_address = UPPER(physical_address),
       postal_address = UPPER(postal_address),
       postal_code = UPPER(postal_code);

UPDATE application_officials
   SET full_name = UPPER(full_name),
       nationality = UPPER(nationality),
       id_passport_number = UPPER(id_passport_number),
       kra_pin = UPPER(kra_pin);

UPDATE application_international_details
   SET parent_organization_name = UPPER(parent_organization_name),
       country_of_origin = UPPER(country_of_origin),
       registration_number = UPPER(registration_number),
       parent_physical_address = UPPER(parent_physical_address),
       parent_postal_address = UPPER(parent_postal_address),
       parent_postal_code = UPPER(parent_postal_code),
       contact_person_name = UPPER(contact_person_name);

-- Email columns are left exactly as they are, on purpose. See above.

COMMIT;


-- A look at the result.
SELECT 'organizations' AS table_name, COUNT(*) AS rows_now FROM pbo_applications
UNION ALL SELECT 'head offices', COUNT(*) FROM application_addresses
UNION ALL SELECT 'areas of operation', COUNT(*) FROM application_operating_locations
UNION ALL SELECT 'officials', COUNT(*) FROM application_officials;
