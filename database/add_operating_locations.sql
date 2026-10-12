USE pbo_portal;

START TRANSACTION;

ALTER TABLE application_addresses
    ADD COLUMN city_town VARCHAR(150) NULL
    AFTER county;

CREATE TABLE IF NOT EXISTS application_operating_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    county VARCHAR(100) NOT NULL,
    city_town VARCHAR(150) NULL,
    sub_county VARCHAR(100) NULL,
    physical_address VARCHAR(255) NOT NULL,
    postal_address VARCHAR(100) NOT NULL,
    postal_code VARCHAR(30) NULL,
    phone_number VARCHAR(50) NOT NULL,
    alternative_phone_number VARCHAR(50) NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_application_operating_county
        (application_id, county),

    KEY idx_operating_locations_application
        (application_id),

    KEY idx_operating_locations_county
        (county),

    CONSTRAINT fk_operating_location_application
        FOREIGN KEY (application_id)
        REFERENCES pbo_applications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

COMMIT;
