-- Run ONCE on an existing database to add profile fields and last_login to the users table.
-- (New installs already include these in database/schema.sql).

ALTER TABLE users
    ADD COLUMN first_name      VARCHAR(50)  NULL AFTER role,
    ADD COLUMN middle_name     VARCHAR(50)  NULL AFTER first_name,
    ADD COLUMN last_name       VARCHAR(50)  NULL AFTER middle_name,
    ADD COLUMN suffix          VARCHAR(10)  NULL AFTER last_name,
    ADD COLUMN age             INT UNSIGNED NULL AFTER suffix,
    ADD COLUMN birthdate       DATE         NULL AFTER age,
    ADD COLUMN address         VARCHAR(255) NULL AFTER birthdate,
    ADD COLUMN contact_number  VARCHAR(25)  NULL AFTER address,
    ADD COLUMN email           VARCHAR(100) NULL AFTER contact_number,
    ADD COLUMN updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN last_login      TIMESTAMP    NULL DEFAULT NULL AFTER updated_at,
    ADD CONSTRAINT uq_users_email UNIQUE (email);
