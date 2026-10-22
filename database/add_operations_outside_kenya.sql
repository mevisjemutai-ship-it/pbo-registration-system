-- ---------------------------------------------------------------------------
-- Head office outside Kenya, and areas of operation outside Kenya
-- ---------------------------------------------------------------------------
--
--   sudo mysql pbo_portal < database/add_operations_outside_kenya.sql
--
-- Two changes, both additions. Nothing existing is altered or removed, and
-- every current record keeps working exactly as it did.
--
-- 1. pbo_applications.headquarters_in_kenya
--
--    Testing found organizations registered as international whose head
--    office is in Kenya and which have no body abroad at all - so staff
--    were filling the overseas fields with the Kenyan head office details
--    to get past the page, and the register ended up saying a Kenyan
--    organization was the foreign parent of itself.
--
--    The form now asks the question outright. NULL means "not asked yet",
--    which is every record entered before today; the pages treat an
--    international record with NULL as "head office abroad", which is what
--    the old form assumed.
--
-- 2. application_foreign_locations
--
--    Organizations operate in Canada, the United States, France. The only
--    place to record where an organization works was a list of the 47
--    Kenyan counties, so anywhere outside Kenya simply went unrecorded.
--
--    Open to national organizations too, not only international ones: a
--    Kenyan organization running a programme abroad is an ordinary thing
--    and the register could not say so.
--
-- Safe to run twice.
-- ---------------------------------------------------------------------------

SET @database := DATABASE();


-- 1 --------------------------------------------------------------------------

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE pbo_applications
            ADD COLUMN headquarters_in_kenya TINYINT(1) NULL DEFAULT NULL
            AFTER registration_type',
        'SELECT ''headquarters_in_kenya already exists'' AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database
      AND TABLE_NAME = 'pbo_applications'
      AND COLUMN_NAME = 'headquarters_in_kenya'
);

PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;


-- 2 --------------------------------------------------------------------------
--
-- Mirrors application_operating_locations, with country in place of county
-- and city in place of sub-county. Same shape on purpose: the pages that
-- read one can read the other without a second way of doing things.

CREATE TABLE IF NOT EXISTS `application_foreign_locations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` BIGINT UNSIGNED NOT NULL,
    `country` VARCHAR(100) NOT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `physical_address` VARCHAR(255) DEFAULT NULL,
    `postal_address` VARCHAR(100) DEFAULT NULL,
    `postal_code` VARCHAR(30) DEFAULT NULL,
    `phone_number` VARCHAR(50) DEFAULT NULL,
    `alternative_phone_number` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_foreign_location_application` (`application_id`),
    CONSTRAINT `fk_foreign_location_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `pbo_applications` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- What it looks like afterwards.

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pbo_applications'
  AND COLUMN_NAME IN ('registration_type', 'headquarters_in_kenya')
ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS foreign_location_rows FROM application_foreign_locations;
