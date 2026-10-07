ALTER TABLE recurrence_executions ADD COLUMN error_code TEXT NULL;

ALTER TABLE recurrence_execution_items ADD COLUMN error_code TEXT NULL;
