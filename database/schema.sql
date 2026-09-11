-- =====================================================================
-- Hotel Booking Management System — Database Schema
-- Engine: InnoDB | Charset: utf8mb4
--
-- NOTE ON CHECK CONSTRAINTS:
-- CHECK constraints below are only *enforced* on MySQL 8.0.16+ and
-- MariaDB 10.2.1+. Older bundled versions (common in some XAMPP/WAMP
-- setups) will accept the CREATE TABLE statement but silently ignore
-- the constraint. Run `SELECT VERSION();` and confirm before relying
-- on this — and validate the same rules in PHP regardless.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS hotel_booking_system;
CREATE DATABASE hotel_booking_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hotel_booking_system;

-- =====================================================================
-- LOOKUP TABLES
-- =====================================================================

CREATE TABLE booking_statuses (
    status_id       TINYINT UNSIGNED PRIMARY KEY,
    status_name     VARCHAR(30) NOT NULL UNIQUE,
    description     VARCHAR(150) NULL
) ENGINE=InnoDB;

INSERT INTO booking_statuses (status_id, status_name, description) VALUES
    (1, 'New',       'Booking submitted, awaiting admin review'),
    (2, 'Approved',  'Booking confirmed by admin'),
    (3, 'Cancelled', 'Booking cancelled by admin or user'),
    (4, 'Completed', 'Guest has checked out'),
    (5, 'No-Show',   'Guest did not arrive');

CREATE TABLE enquiry_statuses (
    status_id       TINYINT UNSIGNED PRIMARY KEY,
    status_name     VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO enquiry_statuses (status_id, status_name) VALUES
    (1, 'Unread'),
    (2, 'Read');

-- room_statuses is now purely ADMINISTRATIVE state. "Occupied" was
-- removed on purpose: whether a room is free for a given date range
-- is always computed live from the `bookings` table (see the overlap
-- trigger below). Storing occupancy here as well would create a
-- second source of truth that goes stale the moment a booking
-- completes/cancels and nobody flips this flag back manually.
CREATE TABLE room_statuses (
    status_id       TINYINT UNSIGNED PRIMARY KEY,
    status_name     VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO room_statuses (status_id, status_name) VALUES
    (1, 'Available'),
    (2, 'Maintenance'),
    (3, 'Inactive');

-- =====================================================================
-- ADMIN
-- =====================================================================
-- A single administrator account. No role/permission columns — this
-- schema does not model a staff hierarchy.

CREATE TABLE admins (
    admin_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(150) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

-- =====================================================================
-- USERS (public/registered customers)
-- =====================================================================
-- users.phone is intentionally NOT unique (only indexed) — a shared
-- household/family phone number booking under one contact is a valid
-- case. Revisit if your requirements say otherwise.

CREATE TABLE users (
    user_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(150) NOT NULL,
    phone               VARCHAR(20) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    address             VARCHAR(255) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_phone (phone)
) ENGINE=InnoDB;

-- =====================================================================
-- ROOM CATEGORIES
-- =====================================================================
-- SOFT DELETE + UNIQUENESS FIX:
-- A plain UNIQUE(category_name) breaks the moment a category is
-- soft-deleted, because the deleted row still occupies that name.
-- `active_category_name` is a STORED generated column that collapses
-- to NULL whenever deleted_at is set. MySQL/MariaDB unique indexes
-- treat every NULL as distinct, so any number of soft-deleted rows
-- can share a name, but only one ACTIVE row can ever hold it.

CREATE TABLE room_categories (
    category_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name           VARCHAR(80) NOT NULL,
    description              TEXT NULL,
    deleted_at               DATETIME NULL,
    active_category_name    VARCHAR(80) GENERATED ALWAYS AS (
                                 CASE WHEN deleted_at IS NULL THEN category_name ELSE NULL END
                             ) STORED,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_active_name (active_category_name)
) ENGINE=InnoDB;

-- =====================================================================
-- ROOMS
-- =====================================================================
-- Same soft-delete + uniqueness fix applied to room_no as above.

CREATE TABLE rooms (
    room_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id         INT UNSIGNED NOT NULL,
    room_no             VARCHAR(20) NOT NULL,
    price_per_night     DECIMAL(10,2) NOT NULL,
    capacity            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    amenities           TEXT NULL,
    description         TEXT NULL,
    status_id           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at          DATETIME NULL,
    active_room_no      VARCHAR(20) GENERATED ALWAYS AS (
                             CASE WHEN deleted_at IS NULL THEN room_no ELSE NULL END
                         ) STORED,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_active_room_no (active_room_no),
    KEY idx_rooms_category (category_id),
    KEY idx_rooms_status (status_id),
    CONSTRAINT chk_rooms_price_positive CHECK (price_per_night > 0),
    CONSTRAINT chk_rooms_capacity_positive CHECK (capacity > 0),
    CONSTRAINT fk_rooms_category
        FOREIGN KEY (category_id) REFERENCES room_categories(category_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rooms_status
        FOREIGN KEY (status_id) REFERENCES room_statuses(status_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_images (
    image_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id         INT UNSIGNED NOT NULL,
    image_path      VARCHAR(255) NOT NULL,
    is_primary      TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_room_images_room (room_id),
    CONSTRAINT fk_room_images_room
        FOREIGN KEY (room_id) REFERENCES rooms(room_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- BOOKINGS
-- =====================================================================
-- No total_amount, no created_by_admin_id — the booking stops at
-- reservation + status. Price, if shown to the guest, is read live
-- from rooms.price_per_night rather than stored here (documented
-- assumption: no payment/invoicing requirement in this project).

CREATE TABLE bookings (
    booking_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_no          VARCHAR(30) NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    room_id             INT UNSIGNED NOT NULL,
    check_in_date       DATE NOT NULL,
    check_out_date      DATE NOT NULL,
    num_guests          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status_id           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_booking_no (booking_no),
    KEY idx_bookings_user (user_id),
    KEY idx_bookings_room_dates (room_id, check_in_date, check_out_date),
    KEY idx_bookings_status (status_id),
    CONSTRAINT chk_bookings_dates CHECK (check_out_date > check_in_date),
    CONSTRAINT fk_bookings_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_room
        FOREIGN KEY (room_id) REFERENCES rooms(room_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_status
        FOREIGN KEY (status_id) REFERENCES booking_statuses(status_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Status change history + remark. No changed_by_admin_id — with a
-- single admin account, attributing each change to "who" adds a
-- column that can only ever hold one value.

CREATE TABLE booking_status_history (
    history_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT UNSIGNED NOT NULL,
    status_id       TINYINT UNSIGNED NOT NULL,
    remark          VARCHAR(500) NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bsh_booking (booking_id),
    CONSTRAINT fk_bsh_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(booking_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bsh_status
        FOREIGN KEY (status_id) REFERENCES booking_statuses(status_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- ENQUIRIES (public contact form)
-- =====================================================================

CREATE TABLE enquiries (
    enquiry_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    mobile          VARCHAR(20) NOT NULL,
    email           VARCHAR(150) NULL,
    message         TEXT NOT NULL,
    status_id       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_enquiries_mobile (mobile),
    KEY idx_enquiries_status (status_id),
    CONSTRAINT fk_enquiries_status
        FOREIGN KEY (status_id) REFERENCES enquiry_statuses(status_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- STATIC CONTENT: GALLERY
-- =====================================================================
-- No unique-name constraint here, so no soft-delete/uniqueness
-- conflict to solve — captions aren't required to be unique.

CREATE TABLE gallery (
    image_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_path      VARCHAR(255) NOT NULL,
    caption         VARCHAR(200) NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    deleted_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- TRIGGERS
-- =====================================================================

DELIMITER $$

-- ---------------------------------------------------------------------
-- Bookings: prevent overlapping reservations for the same room AND
-- enforce guest count within room capacity. Combined into one trigger
-- per event (INSERT / UPDATE) rather than stacking multiple triggers
-- on the same table/event, which keeps execution order unambiguous.
-- ---------------------------------------------------------------------

CREATE TRIGGER trg_bookings_validate_insert
BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE conflict_count INT;
    DECLARE room_capacity TINYINT UNSIGNED;

    SELECT capacity INTO room_capacity FROM rooms WHERE room_id = NEW.room_id;

    IF NEW.num_guests > room_capacity THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Number of guests exceeds room capacity.';
    END IF;

    IF NEW.status_id IN (1, 2) THEN
        SELECT COUNT(*) INTO conflict_count
        FROM bookings
        WHERE room_id = NEW.room_id
          AND status_id IN (1, 2)
          AND check_in_date < NEW.check_out_date
          AND check_out_date > NEW.check_in_date;

        IF conflict_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Room is already booked for the selected date range.';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_bookings_validate_update
BEFORE UPDATE ON bookings
FOR EACH ROW
BEGIN
    DECLARE conflict_count INT;
    DECLARE room_capacity TINYINT UNSIGNED;

    SELECT capacity INTO room_capacity FROM rooms WHERE room_id = NEW.room_id;

    IF NEW.num_guests > room_capacity THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Number of guests exceeds room capacity.';
    END IF;

    IF NEW.status_id IN (1, 2) THEN
        SELECT COUNT(*) INTO conflict_count
        FROM bookings
        WHERE room_id = NEW.room_id
          AND booking_id <> NEW.booking_id
          AND status_id IN (1, 2)
          AND check_in_date < NEW.check_out_date
          AND check_out_date > NEW.check_in_date;

        IF conflict_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Room is already booked for the selected date range.';
        END IF;
    END IF;
END$$

-- ---------------------------------------------------------------------
-- Soft-delete guards.
-- A FOREIGN KEY ... ON DELETE RESTRICT only fires on a real DELETE.
-- Soft delete is just an UPDATE (setting deleted_at), so the FK never
-- sees it. These triggers close that gap explicitly.
-- ---------------------------------------------------------------------

CREATE TRIGGER trg_room_categories_guard_soft_delete
BEFORE UPDATE ON room_categories
FOR EACH ROW
BEGIN
    DECLARE active_room_count INT;

    IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
        SELECT COUNT(*) INTO active_room_count
        FROM rooms
        WHERE category_id = OLD.category_id
          AND deleted_at IS NULL;

        IF active_room_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot delete category: active rooms still reference it.';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_rooms_guard_soft_delete
BEFORE UPDATE ON rooms
FOR EACH ROW
BEGIN
    DECLARE active_booking_count INT;

    IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
        SELECT COUNT(*) INTO active_booking_count
        FROM bookings
        WHERE room_id = OLD.room_id
          AND status_id IN (1, 2); -- New, Approved

        IF active_booking_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot delete room: active or upcoming bookings exist.';
        END IF;
    END IF;
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;