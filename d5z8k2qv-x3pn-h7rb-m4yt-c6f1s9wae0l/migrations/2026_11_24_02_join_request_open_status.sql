-- ============================================================================
-- Victory Bacolod — Get Connected inbox: add "Open", remove "Closed"
--
-- Statuses are now: new -> open -> contacted -> placed ("Connected to a
-- Victory Group"). "Open" = someone has picked the request up but has not
-- reached the person yet, so staff can tell who still has not been contacted.
--
-- Being connected to a Victory Group is now what closes a request, so existing
-- "closed" requests become "placed" (Connected to a Victory Group).
--
-- Safe / idempotent: the first ALTER only widens the list, the UPDATE only
-- touches rows still marked closed, the last ALTER narrows it.
-- MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_24_02_join_request_open_status.sql
-- ============================================================================

ALTER TABLE `join_requests`
    MODIFY COLUMN `status` ENUM('new','open','contacted','placed','closed') NOT NULL DEFAULT 'new';

UPDATE `join_requests` SET `status` = 'placed' WHERE `status` = 'closed';

ALTER TABLE `join_requests`
    MODIFY COLUMN `status` ENUM('new','open','contacted','placed') NOT NULL DEFAULT 'new';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_24_02_join_request_open_status.sql', 'Get Connected inbox: add Open status, retire Closed (closed rows become placed).');
