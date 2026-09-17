-- Accounts are created by signing in with Google. New accounts wait for an administrator's approval.
CREATE TABLE users (
    id            bigserial PRIMARY KEY,
    google_sub    varchar(255) NOT NULL UNIQUE,
    email         varchar(320) NOT NULL,
    name          varchar(255) NOT NULL DEFAULT '',
    picture       text         NOT NULL DEFAULT '',
    role          varchar(20)  NOT NULL DEFAULT 'user'    CHECK (role IN ('user', 'admin')),
    status        varchar(20)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'blocked')),
    created_at    timestamptz  NOT NULL DEFAULT now(),
    approved_at   timestamptz,
    approved_by   bigint       REFERENCES users (id) ON DELETE SET NULL,
    last_login_at timestamptz
);

CREATE INDEX users_status_idx ON users (status);
