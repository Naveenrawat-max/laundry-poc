-- Laundry RFID POC: database schema, version 0.3.0.
-- Target: TransIP web hosting. Runs unchanged on MySQL 8.0.16+ and MariaDB 10.4+.
-- Load once into an empty database:  mysql -u USER -p DBNAME < db/schema.sql
-- Design notes are in ARCHITECTURE.md.
--
-- Conventions
--   * All DATETIME values are UTC. The app runs SET time_zone = '+00:00' on connect.
--   * DATE values (due_date, wash dates) are calendar days in Europe/Amsterdam, set by the app.
--   * Nothing is hard-deleted. Rows are switched off with is_active or a status.
--   * No ON DELETE / ON UPDATE actions: MySQL forbids them on columns used in a CHECK.
--   * Tag ids (EPC, TID) are stored as uppercase hex.

SET NAMES utf8mb4;

-- People who log in. One role per user; the role decides screens and allowed scans.
CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)    NOT NULL,
  username      VARCHAR(60)     NOT NULL,
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('office','inventory','driver','washing','outbound') NOT NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_role (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery area a building lies in. Used to group and filter tasks.
CREATE TABLE areas (
  id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(160)    NOT NULL,
  contact_name  VARCHAR(120)    NULL,
  contact_phone VARCHAR(40)     NULL,
  contact_email VARCHAR(190)    NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One building = one stop for the driver.
CREATE TABLE buildings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  area_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(160)    NOT NULL,
  street      VARCHAR(160)    NOT NULL,
  postcode    VARCHAR(12)     NOT NULL,
  city        VARCHAR(80)     NOT NULL,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_buildings_customer (customer_id),
  KEY ix_buildings_area (area_id),
  CONSTRAINT fk_buildings_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_buildings_area     FOREIGN KEY (area_id)     REFERENCES areas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Floor or zone inside a building: the spot where a mat lies.
CREATE TABLE zones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  building_id BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120)    NOT NULL,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zones_building_name (building_id, name),
  CONSTRAINT fk_zones_building FOREIGN KEY (building_id) REFERENCES buildings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named "item", not "mat", so workwear can be added later as more rows.
CREATE TABLE item_types (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name      VARCHAR(120)    NOT NULL,
  is_active TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per mat. Holds the current state; item_events holds the history.
-- The app must also check that a TID is not used as primary on one mat and
-- secondary on another (two unique keys cannot express that).
CREATE TABLE items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_type_id       BIGINT UNSIGNED NOT NULL,
  sku                VARCHAR(64)     NOT NULL,
  epc                VARCHAR(64) CHARACTER SET ascii NOT NULL,
  tid_primary        VARCHAR(64) CHARACTER SET ascii NOT NULL,
  tid_secondary      VARCHAR(64) CHARACTER SET ascii NOT NULL,
  status             ENUM('registered','ready','qc_passed','rewash','repair','scrapped',
                          'out_for_delivery','refused','at_customer','collected','in_washing')
                     NOT NULL DEFAULT 'registered',
  holder_user_id     BIGINT UNSIGNED NULL,
  zone_id            BIGINT UNSIGNED NULL,
  wash_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  current_wash_date  DATE            NULL,
  previous_wash_date DATE            NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_sku (sku),
  UNIQUE KEY uq_items_epc (epc),
  UNIQUE KEY uq_items_tid_primary (tid_primary),
  UNIQUE KEY uq_items_tid_secondary (tid_secondary),
  KEY ix_items_type_status (item_type_id, status),
  KEY ix_items_status (status),
  KEY ix_items_holder (holder_user_id),
  KEY ix_items_zone (zone_id),
  CONSTRAINT fk_items_type   FOREIGN KEY (item_type_id)   REFERENCES item_types (id),
  CONSTRAINT fk_items_holder FOREIGN KEY (holder_user_id) REFERENCES users (id),
  CONSTRAINT fk_items_zone   FOREIGN KEY (zone_id)        REFERENCES zones (id),
  CONSTRAINT ck_items_two_tags CHECK (tid_primary <> tid_secondary),
  -- A mat lies in a zone exactly when it is at a customer.
  CONSTRAINT ck_items_zone CHECK ((status = 'at_customer') = (zone_id IS NOT NULL)),
  -- A mat has a holder exactly when a driver carries it.
  CONSTRAINT ck_items_holder CHECK (
    (status IN ('out_for_delivery','refused','collected')) = (holder_user_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- swap_interval_days is n: the days between two swaps for this customer.
CREATE TABLE contracts (
  id                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  customer_id        BIGINT UNSIGNED   NOT NULL,
  swap_interval_days SMALLINT UNSIGNED NOT NULL,
  status             ENUM('draft','active','ended') NOT NULL DEFAULT 'draft',
  start_date         DATE              NOT NULL,
  end_date           DATE              NULL,
  created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_contracts_customer (customer_id, status),
  CONSTRAINT fk_contracts_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT ck_contracts_interval CHECK (swap_interval_days BETWEEN 1 AND 365),
  CONSTRAINT ck_contracts_dates CHECK (end_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How many mats of which type lie in which zone. Mats are a pool per type,
-- so a line names a type and a quantity, never a specific mat.
-- The app must check that the zone belongs to the contract's customer.
CREATE TABLE contract_lines (
  id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  contract_id  BIGINT UNSIGNED   NOT NULL,
  zone_id      BIGINT UNSIGNED   NOT NULL,
  item_type_id BIGINT UNSIGNED   NOT NULL,
  quantity     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contract_lines (contract_id, zone_id, item_type_id),
  KEY ix_contract_lines_zone (zone_id),
  CONSTRAINT fk_contract_lines_contract FOREIGN KEY (contract_id)  REFERENCES contracts (id),
  CONSTRAINT fk_contract_lines_zone     FOREIGN KEY (zone_id)      REFERENCES zones (id),
  CONSTRAINT fk_contract_lines_type     FOREIGN KEY (item_type_id) REFERENCES item_types (id),
  CONSTRAINT ck_contract_lines_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One task = one visit to one building. The expected mats come from the
-- contract lines of that building; the mats actually handled are in item_events.
-- previous_task_id is the visit that caused this one. Its unique key makes
-- "create the next swap" safe to run twice.
CREATE TABLE tasks (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type             ENUM('delivery','swap','collection') NOT NULL,
  contract_id      BIGINT UNSIGNED NOT NULL,
  building_id      BIGINT UNSIGNED NOT NULL,
  previous_task_id BIGINT UNSIGNED NULL,
  due_date         DATE            NOT NULL,
  assigned_to      BIGINT UNSIGNED NULL,
  status           ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  completed_at     DATETIME        NULL,
  photo_path       VARCHAR(255)    NULL,
  signature_path   VARCHAR(255)    NULL,
  signed_name      VARCHAR(120)    NULL,
  issue_note       VARCHAR(500)    NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tasks_previous (previous_task_id),
  KEY ix_tasks_status_due (status, due_date),
  KEY ix_tasks_assigned (assigned_to, status, due_date),
  KEY ix_tasks_contract (contract_id),
  KEY ix_tasks_building (building_id),
  CONSTRAINT fk_tasks_contract FOREIGN KEY (contract_id)      REFERENCES contracts (id),
  CONSTRAINT fk_tasks_building FOREIGN KEY (building_id)      REFERENCES buildings (id),
  CONSTRAINT fk_tasks_previous FOREIGN KEY (previous_task_id) REFERENCES tasks (id),
  CONSTRAINT fk_tasks_assigned FOREIGN KEY (assigned_to)      REFERENCES users (id),
  CONSTRAINT ck_tasks_completed CHECK ((status = 'done') = (completed_at IS NOT NULL)),
  -- A stop cannot be closed without a photo and a signature.
  CONSTRAINT ck_tasks_proof CHECK (
    status <> 'done' OR (photo_path IS NOT NULL AND signature_path IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One handover = one giver passing a pile to one receiver.
--   inventory_to_driver   Handover A
--   driver_to_washing     Handover B
--   washing_to_inventory  Handover C (the giver's scan adds 1 to the wash count; receiver scan optional)
--   driver_to_inventory   refused or undelivered mats coming back
--   inventory_to_washing  mats that failed the quality check with "rewash"
CREATE TABLE handovers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('inventory_to_driver','driver_to_washing','washing_to_inventory',
                   'driver_to_inventory','inventory_to_washing') NOT NULL,
  giver_id    BIGINT UNSIGNED NOT NULL,
  receiver_id BIGINT UNSIGNED NOT NULL,
  status      ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at   DATETIME        NULL,
  PRIMARY KEY (id),
  KEY ix_handovers_giver (giver_id, status),
  KEY ix_handovers_receiver (receiver_id, status),
  CONSTRAINT fk_handovers_giver    FOREIGN KEY (giver_id)    REFERENCES users (id),
  CONSTRAINT fk_handovers_receiver FOREIGN KEY (receiver_id) REFERENCES users (id),
  CONSTRAINT ck_handovers_parties CHECK (giver_id <> receiver_id),
  CONSTRAINT ck_handovers_closed CHECK ((status = 'closed') = (closed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per mat per handover. Both timestamps set = the mat moved.
-- One timestamp set on a closed handover = a handover issue for the office
-- (given_at only: not received; received_at only: not given).
-- The primary key makes a second scan of the same mat change nothing.
CREATE TABLE handover_items (
  handover_id     BIGINT UNSIGNED NOT NULL,
  item_id         BIGINT UNSIGNED NOT NULL,
  given_at        DATETIME        NULL,
  received_at     DATETIME        NULL,
  resolved_at     DATETIME        NULL,
  resolved_by     BIGINT UNSIGNED NULL,
  resolution_note VARCHAR(500)    NULL,
  PRIMARY KEY (handover_id, item_id),
  KEY ix_handover_items_item (item_id),
  CONSTRAINT fk_handover_items_handover FOREIGN KEY (handover_id) REFERENCES handovers (id),
  CONSTRAINT fk_handover_items_item     FOREIGN KEY (item_id)     REFERENCES items (id),
  CONSTRAINT fk_handover_items_resolver FOREIGN KEY (resolved_by) REFERENCES users (id),
  CONSTRAINT ck_handover_items_scanned CHECK (given_at IS NOT NULL OR received_at IS NOT NULL),
  CONSTRAINT ck_handover_items_resolved CHECK ((resolved_at IS NULL) = (resolved_by IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every mat: who, when, where, what changed.
-- Rows are only inserted, never updated or deleted.
-- from_status / to_status are plain text so a new item status needs no change here.
CREATE TABLE item_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  event_type  ENUM('register','ready','qc','handover_give','handover_receive',
                   'deliver','refuse','collect','correction') NOT NULL,
  from_status VARCHAR(20)     NULL,
  to_status   VARCHAR(20)     NOT NULL,
  handover_id BIGINT UNSIGNED NULL,
  task_id     BIGINT UNSIGNED NULL,
  zone_id     BIGINT UNSIGNED NULL,
  note        VARCHAR(500)    NULL,
  -- Where the scan came from: the web simulator now, the Chainway C72 later.
  source      ENUM('simulator','c72','web') NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_item_events_item (item_id, created_at),
  KEY ix_item_events_task (task_id),
  KEY ix_item_events_handover (handover_id),
  KEY ix_item_events_user (user_id, created_at),
  CONSTRAINT fk_item_events_item     FOREIGN KEY (item_id)     REFERENCES items (id),
  CONSTRAINT fk_item_events_user     FOREIGN KEY (user_id)     REFERENCES users (id),
  CONSTRAINT fk_item_events_handover FOREIGN KEY (handover_id) REFERENCES handovers (id),
  CONSTRAINT fk_item_events_task     FOREIGN KEY (task_id)     REFERENCES tasks (id),
  CONSTRAINT fk_item_events_zone     FOREIGN KEY (zone_id)     REFERENCES zones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
