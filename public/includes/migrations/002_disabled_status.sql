-- Accounts are never deleted, only disabled: rename the "blocked" status to "disabled".
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check;
UPDATE users SET status = 'disabled' WHERE status = 'blocked';
ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending', 'approved', 'disabled'));
