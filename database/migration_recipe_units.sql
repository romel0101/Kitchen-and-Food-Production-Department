-- Run ONCE on an existing database (phpMyAdmin > SQL tab). New installs use schema.sql instead.
-- Recipes can now be written in their own unit: pcs ingredients use pcs, kg ingredients use kg or g,
-- L ingredients use L or mL. Existing recipe lines keep meaning exactly what they meant: they get the
-- ingredient's own unit.

ALTER TABLE recipe_ingredients ADD COLUMN unit VARCHAR(20) NULL AFTER quantity_required;

UPDATE recipe_ingredients ri
JOIN ingredients i ON i.id = ri.ingredient_id
SET ri.unit = i.unit
WHERE ri.unit IS NULL;

ALTER TABLE ingredients ALTER COLUMN storage_location SET DEFAULT 'General';
