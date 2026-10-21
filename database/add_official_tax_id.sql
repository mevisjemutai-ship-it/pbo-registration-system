-- ---------------------------------------------------------------------------
-- Tax identification for non-Kenyan officials
-- ---------------------------------------------------------------------------
--
--   sudo mysql pbo_portal < database/add_official_tax_id.sql
--
-- Testing against real files found officials who hold no KRA PIN, because a
-- KRA PIN is issued by the Kenya Revenue Authority and they are not Kenyan.
-- The form required one anyway, so staff entered invented numbers to get
-- past the page - which puts wrong data in a government record.
--
-- These two columns let a foreign tax number be recorded as what it is.
-- kra_pin keeps its original meaning: a KRA PIN and nothing else, so no
-- report will ever print an American SSN under the heading "KRA PIN".
--
-- Safe to run twice: the columns are only added if they are not there.
-- Nothing existing is changed; every current row gets NULL in both.
-- ---------------------------------------------------------------------------

SET @database := DATABASE();

-- tax_id_type: what kind of number it is. TIN, SSN, NIF, and so on.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE application_officials
            ADD COLUMN tax_id_type VARCHAR(100) NULL DEFAULT NULL AFTER kra_pin',
        'SELECT ''tax_id_type already exists'' AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database
      AND TABLE_NAME = 'application_officials'
      AND COLUMN_NAME = 'tax_id_type'
);

PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;


-- tax_id_number: the number itself, in whatever form that country uses.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE application_officials
            ADD COLUMN tax_id_number VARCHAR(100) NULL DEFAULT NULL AFTER tax_id_type',
        'SELECT ''tax_id_number already exists'' AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database
      AND TABLE_NAME = 'application_officials'
      AND COLUMN_NAME = 'tax_id_number'
);

PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;


SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_officials'
  AND COLUMN_NAME IN ('kra_pin', 'tax_id_type', 'tax_id_number')
ORDER BY ORDINAL_POSITION;
