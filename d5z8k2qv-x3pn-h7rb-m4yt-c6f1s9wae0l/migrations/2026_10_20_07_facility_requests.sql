-- ============================================================================
-- Victory Bacolod — Facility requests + append-only approval history
--
-- Implements the request form (policy section 7 A–F), the Facilities review
-- (section 8), the Minister decision (section 9) and the final Facilities
-- confirmation (section 6 step 4) that together make a reservation official.
--
-- THE ONE RULE THIS SCHEMA EXISTS TO PROTECT
-- Policy section 3.6 and section 20:
--     "Submitting a request does not mean that the request has been approved."
--     "A reservation becomes official only after:
--        Minister Approval -> Facilities Confirmation -> Calendar Entry"
-- So `status` carries BOTH 'approved' and 'confirmed' as separate states. The
-- calendar treats only 'confirmed' as an official reservation that blocks a
-- room. Everything earlier is visible in another colour so Facilities can spot
-- a looming clash, but it reserves nothing.
--
-- WHY THE REVIEW COLUMNS LIVE ON THE REQUEST
-- The Facilities review and the Minister decision each happen at most once per
-- request and are printed on the form itself, so they are columns, not rows.
-- The HISTORY of how the request moved between states is a separate
-- append-only table — section N: "Maintain an approval/status history rather
-- than silently overwriting decisions."
--
-- WHY starts_at / ends_at EXIST ALONGSIDE date_needed + start_time + end_time
-- The church fills in a date and two times; that is what the form captures and
-- what gets displayed back. But conflict detection needs a single comparable
-- instant, and a half-open [starts_at, ends_at) overlap test on an indexed
-- DATETIME pair is one cheap range scan. They are derived on write, never
-- typed, and are the only thing the overlap query looks at.
--
-- WHY facility_id IS NULLABLE
-- Both real requests in the church's current FACILITIES REQUEST FORM.xlsx are
-- "TYPE OF REQUEST: Equipment" with the room field blank. A schema that
-- insisted on a room would reject the church's own live examples. When
-- facility_id is NULL no conflict check runs, because nothing is being
-- occupied.
--
-- NO PERSONAL DATA IS SEEDED HERE. Requestor names, numbers and addresses
-- enter only at runtime through the staging table.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS. Creates no rows.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_07_facility_requests.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `facility_requests` (
    `id`   INT(11)     NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,

    -- ── A. Requestor information (policy section 7A) ────────────────────────
    -- requestor_member_id is nullable: church staff making a request are not
    -- necessarily rows in `members`, and the request must not be blocked on it.
    `requestor_member_id` INT(11)      DEFAULT NULL,
    `requestor_name`      VARCHAR(255) NOT NULL DEFAULT '',
    `ministry`            VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Matches ministries.name, same convention as members.ministry.',
    `ministry_leader`     VARCHAR(255) DEFAULT NULL,
    `contact_number`      VARCHAR(100) DEFAULT NULL,
    `email`               VARCHAR(255) DEFAULT NULL,

    -- ── B. Request details (section 7B) ─────────────────────────────────────
    -- SET, not ENUM: section 4 says "Requests may fall under one or more of
    -- the following categories".
    `request_type`        SET('facility_room','furniture','equipment','office_resource')
                          NOT NULL DEFAULT 'facility_room',
    `event_activity`      VARCHAR(255) NOT NULL DEFAULT '',
    `purpose`             TEXT         DEFAULT NULL COMMENT 'Policy asks this be framed against the 4Es of Discipleship.',
    `date_needed`         DATE         DEFAULT NULL,
    `start_time`          TIME         DEFAULT NULL,
    `end_time`            TIME         DEFAULT NULL,
    `expected_attendance` INT(11)      DEFAULT NULL,
    -- Derived from date_needed + start_time / end_time on every write.
    `starts_at`           DATETIME     DEFAULT NULL COMMENT 'Derived. The only column the conflict check reads.',
    `ends_at`             DATETIME     DEFAULT NULL COMMENT 'Derived. Half-open interval: a 10-11 and an 11-12 booking do NOT conflict.',

    -- ── C. Facility / room (section 7C) ─────────────────────────────────────
    `facility_id`         INT(11)      DEFAULT NULL COMMENT 'NULL for equipment-only requests. No conflict check when NULL.',
    `preferred_setup`     VARCHAR(255) DEFAULT NULL,
    `num_chairs`          INT(11)      DEFAULT NULL,
    `num_tables`          INT(11)      DEFAULT NULL,
    `other_requirements`  TEXT         DEFAULT NULL,

    -- ── D. Equipment (section 7D) ───────────────────────────────────────────
    `equipment_requested`          TEXT         DEFAULT NULL,
    `equipment_quantity`           VARCHAR(100) DEFAULT NULL COMMENT 'Free text — the church writes "1 pc. each", not a number.',
    `person_responsible`           VARCHAR(255) DEFAULT NULL,
    `technical_assistance`         ENUM('yes','no') NOT NULL DEFAULT 'no',
    `technical_assistance_details` TEXT         DEFAULT NULL,

    -- ── E. Additional requirements (section 7E) ─────────────────────────────
    `needs_setup`       TINYINT(1) NOT NULL DEFAULT 0,
    `needs_technical`   TINYINT(1) NOT NULL DEFAULT 0,
    `needs_security`    TINYINT(1) NOT NULL DEFAULT 0,
    `needs_cleaning`    TINYINT(1) NOT NULL DEFAULT 0,
    `needs_other`       TINYINT(1) NOT NULL DEFAULT 0,
    `additional_details` TEXT      DEFAULT NULL,

    -- ── F. Requestor's acknowledgment (section 7F) ──────────────────────────
    `acknowledged`      TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Requestor accepted that approval is subject to availability and the Minister.',
    `acknowledged_name` VARCHAR(255) DEFAULT NULL,
    `acknowledged_at`   DATETIME     DEFAULT NULL,

    -- ── Workflow state ──────────────────────────────────────────────────────
    `status` ENUM('draft','submitted','facilities_review','pending_minister',
                  'for_clarification','approved','declined','confirmed','cancelled')
             NOT NULL DEFAULT 'submitted'
        COMMENT 'approved = Minister said yes. confirmed = Facilities confirmed it; ONLY THEN is it an official reservation.',

    -- Lead time (policy section 10/11). Recorded, warned about, never enforced.
    `lead_days_actual`  INT(11)    DEFAULT NULL COMMENT 'DATEDIFF(date_needed, submitted_at) at submission.',
    `lead_time_warning` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = below the facility recommended lead time. Advisory only.',

    -- ── Facilities review block (section 8) ─────────────────────────────────
    -- Facilities Personnel assess logistics. They do NOT give ministry approval.
    `fac_available`            ENUM('yes','no')  DEFAULT NULL,
    `fac_schedule_conflict`    ENUM('none','yes') DEFAULT NULL,
    `fac_setup_required`       ENUM('no','yes')  DEFAULT NULL,
    `fac_technical_required`   ENUM('no','yes')  DEFAULT NULL,
    `fac_other_considerations` TEXT              DEFAULT NULL,
    `fac_recommendation`       ENUM('no_concern','requires_adjustment','requires_discussion') DEFAULT NULL,
    `fac_reviewed_by`          INT(11)           DEFAULT NULL COMMENT 'accounts.id',
    `fac_reviewed_at`          DATETIME          DEFAULT NULL,

    -- ── Minister decision block (section 9) ─────────────────────────────────
    `minister_decision` ENUM('approved','declined','for_clarification') DEFAULT NULL,
    `minister_comments` TEXT     DEFAULT NULL,
    `minister_by`       INT(11)  DEFAULT NULL COMMENT 'accounts.id',
    `minister_at`       DATETIME DEFAULT NULL,

    -- ── Facilities confirmation (section 6 step 4) ──────────────────────────
    -- This is the step that creates an official reservation.
    `confirmed_by` INT(11)  DEFAULT NULL COMMENT 'accounts.id',
    `confirmed_at` DATETIME DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,

    -- ── Provenance ──────────────────────────────────────────────────────────
    `source`       ENUM('portal','excel_import') NOT NULL DEFAULT 'portal',
    `source_label` VARCHAR(255) DEFAULT NULL COMMENT 'Original spreadsheet sheet name when imported. Never overwritten.',

    `submitted_at` DATETIME DEFAULT NULL,
    `dateadded`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dateupdated`  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_facility_request_uuid` (`uuid`),
    KEY `idx_fr_status` (`status`),
    KEY `idx_fr_date` (`date_needed`),
    KEY `idx_fr_ministry` (`ministry`),
    KEY `idx_fr_requestor_member` (`requestor_member_id`),
    -- The conflict-detection index: facility first, then the time range.
    KEY `idx_fr_facility_time` (`facility_id`, `starts_at`, `ends_at`),
    CONSTRAINT `fk_fr_facility` FOREIGN KEY (`facility_id`)
        REFERENCES `facilities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    -- Matches the existing members FK convention: losing a member record must
    -- never delete the booking history that references it.
    CONSTRAINT `fk_fr_member` FOREIGN KEY (`requestor_member_id`)
        REFERENCES `members` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Append-only status history ─────────────────────────────────────────────
-- Same pattern as historical_completion_reviews, which already works well in
-- this codebase: one row INSERTed per transition, never UPDATEd, never DELETEd.
-- facility_requests holds current state; this holds how it got there.
CREATE TABLE IF NOT EXISTS `facility_request_history` (
    `id`         INT(11)     NOT NULL AUTO_INCREMENT,
    `request_id` INT(11)     NOT NULL,
    `action`     VARCHAR(50) NOT NULL
        COMMENT 'submitted | facilities_reviewed | approved | declined | clarification_requested | confirmed | cancelled | edited',
    `from_status` ENUM('draft','submitted','facilities_review','pending_minister',
                       'for_clarification','approved','declined','confirmed','cancelled')
                  DEFAULT NULL COMMENT 'NULL on the creation row.',
    `to_status`   ENUM('draft','submitted','facilities_review','pending_minister',
                       'for_clarification','approved','declined','confirmed','cancelled') NOT NULL,
    `performed_by` INT(11)      DEFAULT NULL COMMENT 'accounts.id of whoever acted.',
    `performed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`        VARCHAR(500) DEFAULT NULL COMMENT 'Comments / conditions recorded with the decision.',
    PRIMARY KEY (`id`),
    KEY `idx_frh_request` (`request_id`),
    KEY `idx_frh_performed_at` (`performed_at`),
    CONSTRAINT `fk_frh_request` FOREIGN KEY (`request_id`)
        REFERENCES `facility_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_07_facility_requests.sql', 'New: facility_requests + facility_request_history (append-only approval trail).');
