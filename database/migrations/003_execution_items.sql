CREATE TABLE recurrence_execution_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    execution_id INTEGER NOT NULL,
    item_index INTEGER NOT NULL CHECK (item_index > 0),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'creating', 'succeeded', 'failed')),
    hesk_trackid TEXT NULL,
    hesk_ticket_id INTEGER NULL CHECK (hesk_ticket_id IS NULL OR hesk_ticket_id > 0),
    creation_attempts INTEGER NOT NULL DEFAULT 0 CHECK (creation_attempts >= 0),
    last_attempt_at TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CONSTRAINT uq_recurrence_execution_items_index
        UNIQUE (execution_id, item_index),
    CONSTRAINT uq_recurrence_execution_items_trackid
        UNIQUE (hesk_trackid),
    CONSTRAINT uq_recurrence_execution_items_ticket
        UNIQUE (hesk_ticket_id),
    CONSTRAINT fk_recurrence_execution_items_execution
        FOREIGN KEY (execution_id)
        REFERENCES recurrence_executions (id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
);

CREATE INDEX idx_recurrence_execution_items_status
    ON recurrence_execution_items (execution_id, status, item_index);
