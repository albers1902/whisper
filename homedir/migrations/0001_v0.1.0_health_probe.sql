-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- H001: tabellen, /health skriver i (og ruller tilbage) for at bevise db_write (LIFECYCLE §8).
CREATE TABLE IF NOT EXISTS health_probe (
    id      TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    beat_at DATETIME(6)      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
