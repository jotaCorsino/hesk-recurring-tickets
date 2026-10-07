ALTER TABLE recurrence_executions
    ADD COLUMN attempt_count INTEGER NOT NULL DEFAULT 0
        CHECK (attempt_count >= 0);

ALTER TABLE recurrence_executions
    ADD COLUMN lease_token TEXT NULL;

ALTER TABLE recurrence_executions
    ADD COLUMN lease_owner TEXT NULL;

ALTER TABLE recurrence_executions
    ADD COLUMN lease_expires_at TEXT NULL;

ALTER TABLE recurrence_executions
    ADD COLUMN last_attempt_at TEXT NULL;

CREATE INDEX idx_recurrence_executions_claimable
    ON recurrence_executions (status, lease_expires_at, scheduled_for, id);
