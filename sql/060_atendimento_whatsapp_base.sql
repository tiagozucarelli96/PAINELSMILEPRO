CREATE TABLE IF NOT EXISTS wa_users (
    id BIGSERIAL PRIMARY KEY,
    display_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'offline'
        CHECK (status IN ('available', 'busy', 'away', 'offline')),
    is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_departments (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#1d4ed8',
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_user_departments (
    user_id BIGINT NOT NULL REFERENCES wa_users(id) ON DELETE CASCADE,
    department_id BIGINT NOT NULL REFERENCES wa_departments(id) ON DELETE CASCADE,
    role VARCHAR(20) NOT NULL DEFAULT 'agent'
        CHECK (role IN ('agent', 'supervisor')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, department_id)
);

CREATE TABLE IF NOT EXISTS wa_inboxes (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    session_key VARCHAR(120) NOT NULL UNIQUE,
    phone_number VARCHAR(40) NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'baileys',
    connection_mode VARCHAR(30) NOT NULL DEFAULT 'qr'
        CHECK (connection_mode IN ('qr', 'pairing_code')),
    status VARCHAR(20) NOT NULL DEFAULT 'disconnected'
        CHECK (status IN ('disconnected', 'connecting', 'connected', 'error')),
    department_id BIGINT NULL REFERENCES wa_departments(id) ON DELETE SET NULL,
    notes TEXT NULL,
    last_qr_at TIMESTAMPTZ NULL,
    connected_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_contacts (
    id BIGSERIAL PRIMARY KEY,
    full_name VARCHAR(190) NOT NULL,
    phone_e164 VARCHAR(40) NOT NULL UNIQUE,
    avatar_url TEXT NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(80) NULL,
    last_message_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_conversations (
    id BIGSERIAL PRIMARY KEY,
    inbox_id BIGINT NOT NULL REFERENCES wa_inboxes(id) ON DELETE CASCADE,
    contact_id BIGINT NOT NULL REFERENCES wa_contacts(id) ON DELETE CASCADE,
    department_id BIGINT NULL REFERENCES wa_departments(id) ON DELETE SET NULL,
    assigned_user_id BIGINT NULL REFERENCES wa_users(id) ON DELETE SET NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'waiting', 'pending', 'closed')),
    priority VARCHAR(20) NOT NULL DEFAULT 'normal'
        CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    subject VARCHAR(190) NULL,
    last_message_preview TEXT NULL,
    unread_count INT NOT NULL DEFAULT 0,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_message_at TIMESTAMPTZ NULL,
    closed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT NOT NULL REFERENCES wa_conversations(id) ON DELETE CASCADE,
    direction VARCHAR(20) NOT NULL
        CHECK (direction IN ('inbound', 'outbound', 'internal')),
    message_type VARCHAR(20) NOT NULL DEFAULT 'text'
        CHECK (message_type IN ('text', 'image', 'audio', 'video', 'file', 'template', 'system')),
    body TEXT NULL,
    media_url TEXT NULL,
    author_user_id BIGINT NULL REFERENCES wa_users(id) ON DELETE SET NULL,
    external_message_id VARCHAR(190) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_quick_replies (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    shortcut VARCHAR(50) NOT NULL UNIQUE,
    body TEXT NOT NULL,
    department_id BIGINT NULL REFERENCES wa_departments(id) ON DELETE SET NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wa_connection_events (
    id BIGSERIAL PRIMARY KEY,
    inbox_id BIGINT NOT NULL REFERENCES wa_inboxes(id) ON DELETE CASCADE,
    event_type VARCHAR(60) NOT NULL,
    payload JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_wa_users_active ON wa_users(is_active);
CREATE INDEX IF NOT EXISTS idx_wa_departments_active ON wa_departments(is_active);
CREATE INDEX IF NOT EXISTS idx_wa_inboxes_status ON wa_inboxes(status);
CREATE INDEX IF NOT EXISTS idx_wa_conversations_status ON wa_conversations(status);
CREATE INDEX IF NOT EXISTS idx_wa_conversations_department ON wa_conversations(department_id);
CREATE INDEX IF NOT EXISTS idx_wa_conversations_assigned_user ON wa_conversations(assigned_user_id);
CREATE INDEX IF NOT EXISTS idx_wa_messages_conversation ON wa_messages(conversation_id, created_at);
CREATE INDEX IF NOT EXISTS idx_wa_contacts_last_message ON wa_contacts(last_message_at);
