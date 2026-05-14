-- Run once on kostify_db: adds optional room photo for tenant-facing views.
ALTER TABLE room ADD COLUMN photo_path VARCHAR(500) NULL DEFAULT NULL;
