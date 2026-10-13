-- More mock data for a demo and for the end-to-end walkthrough (docs/walkthrough.md).
-- It goes on top of seed.sql: 5 more customers, 10 more buildings, 2 more mat types,
-- 5 more draft contracts and 200 more mats (MOCK-0041 to MOCK-0240).
--
-- Load it after schema.sql and seed.sql:
--   mysql -u USER -p DBNAME < db/demo.sql
-- or through Laravel:
--   php artisan db:seed --class=DemoSeeder
--
-- Everything is mock: names start with "Mock", and every tag id starts with 4D4F434B ("MOCK" in hex).
-- No users: a password must not sit in a file.

SET NAMES utf8mb4;

INSERT INTO areas (id, name) VALUES
  (3, 'Mock East'),
  (4, 'Mock West');

INSERT INTO customers (id, name, contact_name, contact_phone, contact_email) VALUES
  (3, 'Mock Hotel Group',      'Mock Contact Three', '000-0000003', 'three@example.test'),
  (4, 'Mock Clinic',           'Mock Contact Four',  '000-0000004', 'four@example.test'),
  (5, 'Mock School',           'Mock Contact Five',  '000-0000005', 'five@example.test'),
  (6, 'Mock Garage BV',        'Mock Contact Six',   '000-0000006', 'six@example.test'),
  (7, 'Mock Supermarkets NV',  'Mock Contact Seven', '000-0000007', 'seven@example.test');

INSERT INTO buildings (id, customer_id, area_id, name, street, postcode, city) VALUES
  (4,  3, 3, 'Mock Hotel Central',      'Mockplein 1',     '0000 CC', 'Mockstad'),
  (5,  3, 3, 'Mock Hotel Station',      'Mockspoor 4',     '0000 CD', 'Mockstad'),
  (6,  4, 1, 'Mock Clinic main',        'Mockzorg 20',     '0000 DD', 'Mockstad'),
  (7,  5, 4, 'Mock School building A',  'Mockschool 2',    '0000 EE', 'Mockveen'),
  (8,  5, 4, 'Mock School sports hall', 'Mockschool 6',    '0000 EE', 'Mockveen'),
  (9,  6, 2, 'Mock Garage workshop',    'Mockweg 88',      '0000 FF', 'Mockdorp'),
  (10, 7, 3, 'Mock Supermarket Centrum', 'Mockmarkt 3',    '0000 GG', 'Mockstad'),
  (11, 7, 4, 'Mock Supermarket West',   'Mockwest 15',     '0000 GH', 'Mockveen'),
  (12, 7, 1, 'Mock Supermarket Noord',  'Mocknoord 7',     '0000 GJ', 'Mockstad'),
  (13, 4, 1, 'Mock Clinic annex',       'Mockzorg 24',     '0000 DD', 'Mockstad');

INSERT INTO zones (id, building_id, name) VALUES
  (6,  4,  'Main entrance'),
  (7,  4,  'Restaurant door'),
  (8,  4,  'Staff entrance'),
  (9,  5,  'Main entrance'),
  (10, 5,  'Terrace door'),
  (11, 6,  'Reception'),
  (12, 6,  'Ambulance entrance'),
  (13, 7,  'Main entrance'),
  (14, 7,  'Canteen'),
  (15, 8,  'Changing rooms'),
  (16, 9,  'Workshop door'),
  (17, 9,  'Showroom entrance'),
  (18, 10, 'Entrance'),
  (19, 10, 'Bakery counter'),
  (20, 11, 'Entrance'),
  (21, 11, 'Staff entrance'),
  (22, 12, 'Entrance'),
  (23, 13, 'Entrance');

INSERT INTO item_types (id, name) VALUES
  (4, 'Mock mat 150x240 grey'),
  (5, 'Mock standing mat 60x90');

-- 200 mats, numbered on from the 40 of seed.sql: 120 small, 60 large, 30 logo, 20 extra large, 10 standing mats.
INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary, status)
WITH RECURSIVE seq (n) AS (
  SELECT 41 UNION ALL SELECT n + 1 FROM seq WHERE n < 240
)
SELECT
  CASE WHEN n <= 160 THEN 1 WHEN n <= 190 THEN 2 WHEN n <= 210 THEN 3 WHEN n <= 230 THEN 4 ELSE 5 END,
  CONCAT('MOCK-', LPAD(n, 4, '0')),
  CONCAT('4D4F434B',     LPAD(HEX(n), 16, '0')),
  CONCAT('4D4F434B000A', LPAD(HEX(n), 12, '0')),
  CONCAT('4D4F434B000B', LPAD(HEX(n), 12, '0')),
  'ready'
FROM seq;

INSERT INTO contracts (id, customer_id, swap_interval_days, status, start_date) VALUES
  (3, 3, 7,  'draft', '2026-10-19'),
  (4, 4, 14, 'draft', '2026-10-19'),
  (5, 5, 28, 'draft', '2026-10-19'),
  (6, 6, 14, 'draft', '2026-10-19'),
  (7, 7, 7,  'draft', '2026-10-19');

INSERT INTO contract_lines (contract_id, zone_id, item_type_id, quantity) VALUES
  (3, 6,  3, 1),
  (3, 6,  2, 2),
  (3, 7,  1, 2),
  (3, 8,  1, 1),
  (3, 9,  3, 1),
  (3, 9,  2, 1),
  (3, 10, 1, 2),
  (4, 11, 2, 2),
  (4, 12, 4, 1),
  (4, 23, 1, 1),
  (5, 13, 4, 1),
  (5, 13, 1, 2),
  (5, 14, 1, 2),
  (5, 15, 2, 1),
  (6, 16, 5, 2),
  (6, 17, 3, 1),
  (7, 18, 2, 2),
  (7, 19, 5, 1),
  (7, 20, 2, 1),
  (7, 21, 1, 1),
  (7, 22, 1, 2);
