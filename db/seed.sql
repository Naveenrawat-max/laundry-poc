-- Laundry RFID POC: mock data, version 0.3.0.
-- Load once, after db/schema.sql, into the empty database:
--   mysql -u USER -p DBNAME < db/seed.sql
--
-- Everything here is made up. No real customer, no real mat, no real tag.
-- Every mock tag id starts with 4D4F434B (the letters "MOCK" in hex), so the
-- mock mats can be told apart from real ones and removed before go-live.
-- No users are seeded: a password must never sit in a file. The first office
-- user is created on the server (see ARCHITECTURE.md, "First login").

SET NAMES utf8mb4;

INSERT INTO areas (id, name) VALUES
  (1, 'Mock North'),
  (2, 'Mock South');

INSERT INTO customers (id, name, contact_name, contact_phone, contact_email) VALUES
  (1, 'Mock Bakery BV',  'Mock Contact One', '000-0000001', 'one@example.test'),
  (2, 'Mock Offices NV', 'Mock Contact Two', '000-0000002', 'two@example.test');

INSERT INTO buildings (id, customer_id, area_id, name, street, postcode, city) VALUES
  (1, 1, 1, 'Mock Bakery shop',        'Mockstraat 1',  '0000 AA', 'Mockstad'),
  (2, 2, 2, 'Mock Offices tower A',    'Mocklaan 10',   '0000 BB', 'Mockdorp'),
  (3, 2, 2, 'Mock Offices warehouse',  'Mocklaan 12',   '0000 BB', 'Mockdorp');

INSERT INTO zones (id, building_id, name) VALUES
  (1, 1, 'Front door'),
  (2, 1, 'Staff entrance'),
  (3, 2, 'Ground floor lobby'),
  (4, 2, 'Floor 1 lift'),
  (5, 3, 'Loading dock');

INSERT INTO item_types (id, name) VALUES
  (1, 'Mock mat 85x150 grey'),
  (2, 'Mock mat 115x180 grey'),
  (3, 'Mock logo mat 85x150');

-- 40 mock mats, registered and ready to use, wash count 0.
-- Mats 1-20 are type 1, 21-32 type 2, 33-40 type 3.
INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary, status)
WITH RECURSIVE seq (n) AS (
  SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 40
)
SELECT
  CASE WHEN n <= 20 THEN 1 WHEN n <= 32 THEN 2 ELSE 3 END,
  CONCAT('MOCK-', LPAD(n, 4, '0')),
  CONCAT('4D4F434B',     LPAD(HEX(n), 16, '0')),
  CONCAT('4D4F434B000A', LPAD(HEX(n), 12, '0')),
  CONCAT('4D4F434B000B', LPAD(HEX(n), 12, '0')),
  'ready'
FROM seq;

-- Two draft contracts. The office activates them in the web app; activation
-- is what creates the delivery tasks, so no task is seeded here.
INSERT INTO contracts (id, customer_id, swap_interval_days, status, start_date) VALUES
  (1, 1, 14, 'draft', '2026-10-19'),
  (2, 2, 28, 'draft', '2026-10-19');

INSERT INTO contract_lines (contract_id, zone_id, item_type_id, quantity) VALUES
  (1, 1, 1, 2),
  (1, 2, 1, 1),
  (2, 3, 2, 2),
  (2, 3, 3, 1),
  (2, 4, 1, 1),
  (2, 5, 2, 1);
