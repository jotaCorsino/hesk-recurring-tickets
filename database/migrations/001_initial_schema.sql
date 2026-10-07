CREATE TABLE recurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    timezone TEXT NOT NULL CHECK (length(trim(timezone)) > 0),
    interval_value INTEGER NOT NULL CHECK (interval_value > 0),
    interval_unit TEXT NOT NULL CHECK (interval_unit IN ('day', 'week', 'month', 'year')),
    next_run_at TEXT NOT NULL CHECK (length(trim(next_run_at)) > 0),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    customer_id INTEGER NOT NULL CHECK (customer_id > 0),
    category_id INTEGER NOT NULL CHECK (category_id > 0),
    priority_name TEXT NOT NULL CHECK (length(trim(priority_name)) > 0),
    status_id INTEGER NOT NULL CHECK (status_id >= 0),
    owner_id INTEGER NOT NULL CHECK (owner_id > 0),
    openedby_id INTEGER NOT NULL CHECK (openedby_id > 0),
    subject TEXT NOT NULL CHECK (length(trim(subject)) > 0),
    message TEXT NOT NULL CHECK (length(trim(message)) > 0),
    notify_customer INTEGER NOT NULL DEFAULT 0 CHECK (notify_customer IN (0, 1)),
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_recurrences_enabled_next_run
    ON recurrences (enabled, next_run_at);

CREATE TABLE recurrence_executions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recurrence_id INTEGER NOT NULL,
    scheduled_for TEXT NOT NULL CHECK (length(trim(scheduled_for)) > 0),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'partial')),
    expected_count INTEGER NOT NULL CHECK (expected_count > 0),
    created_count INTEGER NOT NULL DEFAULT 0
        CHECK (created_count >= 0 AND created_count <= expected_count),
    started_at TEXT,
    finished_at TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CONSTRAINT uq_recurrence_executions_schedule
        UNIQUE (recurrence_id, scheduled_for),
    CONSTRAINT fk_recurrence_executions_recurrence
        FOREIGN KEY (recurrence_id)
        REFERENCES recurrences (id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
);

CREATE INDEX idx_recurrence_executions_recurrence
    ON recurrence_executions (recurrence_id, scheduled_for);

CREATE INDEX idx_recurrence_executions_status
    ON recurrence_executions (status);
