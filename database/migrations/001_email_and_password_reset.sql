-- adds email + forgot password columns to users
-- run this ONLY if your db was made with the old schema.sql (it keeps your data):
--   sudo mysql kalasag_db < database/migrations/001_email_and_password_reset.sql
-- if you're making the db fresh, just use schema.sql, it already has these

ALTER TABLE users
    ADD COLUMN email            VARCHAR(120) NULL UNIQUE AFTER contact_no,
    ADD COLUMN reset_token_hash CHAR(64) NULL AFTER created_at,
    ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token_hash;
