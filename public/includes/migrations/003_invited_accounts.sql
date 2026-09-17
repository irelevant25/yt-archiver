-- Administrators can create accounts by email before the person ever signs in. Such an account has no Google
-- subject yet; the first Google sign-in with a verified, matching email address claims it.
ALTER TABLE users ALTER COLUMN google_sub DROP NOT NULL;
ALTER TABLE users ADD COLUMN created_by bigint REFERENCES users (id) ON DELETE SET NULL;
-- One unclaimed account per email address (case-insensitive)
CREATE UNIQUE INDEX users_unclaimed_email_idx ON users (lower(email)) WHERE google_sub IS NULL;
