import { query, withTransaction } from "./database.js";

export async function listInboxes() {
  const result = await query(
    `
      SELECT
        i.id,
        i.name,
        i.session_key,
        i.phone_number,
        i.provider,
        i.connection_mode,
        i.status,
        i.department_id,
        d.name AS department_name,
        sc.runtime_meta,
        sc.updated_at AS credential_updated_at
      FROM wa_inboxes i
      LEFT JOIN wa_departments d ON d.id = i.department_id
      LEFT JOIN wa_session_credentials sc ON sc.session_key = i.session_key
      ORDER BY i.name ASC
    `
  );

  return result.rows;
}

export async function getInboxBySessionKey(sessionKey) {
  const result = await query(
    `
      SELECT
        i.id,
        i.name,
        i.session_key,
        i.phone_number,
        i.provider,
        i.connection_mode,
        i.status,
        i.department_id,
        i.notes,
        d.name AS department_name
      FROM wa_inboxes i
      LEFT JOIN wa_departments d ON d.id = i.department_id
      WHERE i.session_key = $1
      LIMIT 1
    `,
    [sessionKey]
  );

  return result.rows[0] || null;
}

export async function updateInboxStatus(sessionKey, status, extra = {}) {
  const fields = [];
  const values = [sessionKey, status];
  let index = 3;

  if (Object.prototype.hasOwnProperty.call(extra, "lastQrAt")) {
    fields.push(`last_qr_at = $${index++}`);
    values.push(extra.lastQrAt);
  }
  if (Object.prototype.hasOwnProperty.call(extra, "connectedAt")) {
    fields.push(`connected_at = $${index++}`);
    values.push(extra.connectedAt);
  }
  if (Object.prototype.hasOwnProperty.call(extra, "phoneNumber")) {
    fields.push(`phone_number = $${index++}`);
    values.push(extra.phoneNumber);
  }

  fields.push("updated_at = NOW()");

  await query(
    `
      UPDATE wa_inboxes
      SET status = $2,
          ${fields.join(", ")}
      WHERE session_key = $1
    `,
    values
  );
}

export async function saveSessionRuntime(sessionKey, provider, authState, runtimeMeta) {
  await query(
    `
      INSERT INTO wa_session_credentials (session_key, provider, auth_state, runtime_meta, updated_at)
      VALUES ($1, $2, $3, $4, NOW())
      ON CONFLICT (session_key) DO UPDATE
      SET provider = EXCLUDED.provider,
          auth_state = EXCLUDED.auth_state,
          runtime_meta = EXCLUDED.runtime_meta,
          updated_at = NOW()
    `,
    [sessionKey, provider, authState, runtimeMeta]
  );
}

export async function getSessionRuntime(sessionKey) {
  const result = await query(
    `
      SELECT session_key, provider, auth_state, runtime_meta, updated_at
      FROM wa_session_credentials
      WHERE session_key = $1
      LIMIT 1
    `,
    [sessionKey]
  );

  return result.rows[0] || null;
}

export async function storeConnectionEvent(sessionKey, eventType, payload) {
  await query(
    `
      INSERT INTO wa_connection_events (inbox_id, event_type, payload, created_at)
      SELECT id, $2, $3, NOW()
      FROM wa_inboxes
      WHERE session_key = $1
    `,
    [sessionKey, eventType, payload]
  );
}

export async function storeGatewayDelivery(sessionKey, direction, externalMessageId, payload) {
  await query(
    `
      INSERT INTO wa_gateway_deliveries (session_key, direction, external_message_id, payload)
      VALUES ($1, $2, $3, $4)
    `,
    [sessionKey, direction, externalMessageId, payload]
  );
}

async function upsertContact(client, fullName, phoneE164) {
  const result = await client.query(
    `
      INSERT INTO wa_contacts (full_name, phone_e164, last_message_at, updated_at)
      VALUES ($1, $2, NOW(), NOW())
      ON CONFLICT (phone_e164) DO UPDATE
      SET full_name = COALESCE(NULLIF(EXCLUDED.full_name, ''), wa_contacts.full_name),
          last_message_at = NOW(),
          updated_at = NOW()
      RETURNING id
    `,
    [fullName, phoneE164]
  );

  return result.rows[0].id;
}

async function upsertConversation(client, inbox, contactId, preview, assignedUserId = null) {
  const openConversation = await client.query(
    `
      SELECT id
      FROM wa_conversations
      WHERE inbox_id = $1
        AND contact_id = $2
        AND status IN ('open', 'waiting', 'pending')
      ORDER BY updated_at DESC
      LIMIT 1
    `,
    [inbox.id, contactId]
  );

  if (openConversation.rows[0]?.id) {
    const conversationId = openConversation.rows[0].id;
    await client.query(
      `
        UPDATE wa_conversations
        SET department_id = COALESCE($2, department_id),
            assigned_user_id = COALESCE($3, assigned_user_id),
            status = 'open',
            last_message_preview = $4,
            unread_count = unread_count + 1,
            last_message_at = NOW(),
            updated_at = NOW()
        WHERE id = $1
      `,
      [conversationId, inbox.department_id, assignedUserId, preview]
    );
    return conversationId;
  }

  const insertResult = await client.query(
    `
      INSERT INTO wa_conversations (
        inbox_id,
        contact_id,
        department_id,
        assigned_user_id,
        status,
        priority,
        subject,
        last_message_preview,
        unread_count,
        started_at,
        last_message_at,
        created_at,
        updated_at
      )
      VALUES ($1, $2, $3, $4, 'open', 'normal', $5, $6, 1, NOW(), NOW(), NOW(), NOW())
      RETURNING id
    `,
    [
      inbox.id,
      contactId,
      inbox.department_id,
      assignedUserId,
      `${inbox.name} • ${inbox.department_name || "Sem departamento"}`,
      preview,
    ]
  );

  return insertResult.rows[0].id;
}

export async function ingestInboundMessage({
  sessionKey,
  contactName,
  phoneE164,
  body,
  messageType = "text",
  externalMessageId = null,
  rawPayload = {},
}) {
  return withTransaction(async (client) => {
    const inboxResult = await client.query(
      `
        SELECT i.id, i.name, i.session_key, i.department_id, d.name AS department_name
        FROM wa_inboxes i
        LEFT JOIN wa_departments d ON d.id = i.department_id
        WHERE i.session_key = $1
        LIMIT 1
      `,
      [sessionKey]
    );

    const inbox = inboxResult.rows[0];
    if (!inbox) {
      throw new Error(`Inbox nao encontrada para session_key ${sessionKey}.`);
    }

    const contactId = await upsertContact(
      client,
      contactName || phoneE164,
      phoneE164
    );
    const conversationId = await upsertConversation(
      client,
      inbox,
      contactId,
      body || "[mensagem sem texto]"
    );

    const messageResult = await client.query(
      `
        INSERT INTO wa_messages (
          conversation_id,
          direction,
          message_type,
          body,
          external_message_id,
          created_at
        )
        VALUES ($1, 'inbound', $2, $3, $4, NOW())
        RETURNING id
      `,
      [conversationId, messageType, body || null, externalMessageId]
    );

    await client.query(
      `
        UPDATE wa_contacts
        SET last_message_at = NOW(),
            updated_at = NOW()
        WHERE id = $1
      `,
      [contactId]
    );

    await client.query(
      `
        INSERT INTO wa_gateway_deliveries (session_key, direction, external_message_id, payload, created_at)
        VALUES ($1, 'inbound', $2, $3, NOW())
      `,
      [sessionKey, externalMessageId, rawPayload]
    );

    return {
      conversationId,
      messageId: messageResult.rows[0].id,
      contactId,
    };
  });
}

export async function ingestOutboundMessage({
  sessionKey,
  contactName,
  phoneE164,
  body,
  messageType = "text",
  externalMessageId = null,
  authorUserId = null,
  rawPayload = {},
}) {
  return withTransaction(async (client) => {
    const inboxResult = await client.query(
      `
        SELECT i.id, i.name, i.session_key, i.department_id, d.name AS department_name
        FROM wa_inboxes i
        LEFT JOIN wa_departments d ON d.id = i.department_id
        WHERE i.session_key = $1
        LIMIT 1
      `,
      [sessionKey]
    );

    const inbox = inboxResult.rows[0];
    if (!inbox) {
      throw new Error(`Inbox nao encontrada para session_key ${sessionKey}.`);
    }

    const contactId = await upsertContact(
      client,
      contactName || phoneE164,
      phoneE164
    );
    const conversationId = await upsertConversation(
      client,
      inbox,
      contactId,
      body || "[mensagem sem texto]",
      authorUserId
    );

    const messageResult = await client.query(
      `
        INSERT INTO wa_messages (
          conversation_id,
          direction,
          message_type,
          body,
          author_user_id,
          external_message_id,
          created_at
        )
        VALUES ($1, 'outbound', $2, $3, $4, $5, NOW())
        RETURNING id
      `,
      [conversationId, messageType, body || null, authorUserId, externalMessageId]
    );

    await client.query(
      `
        UPDATE wa_conversations
        SET last_message_preview = $2,
            last_message_at = NOW(),
            updated_at = NOW()
        WHERE id = $1
      `,
      [conversationId, body || "[mensagem sem texto]"]
    );

    await client.query(
      `
        INSERT INTO wa_gateway_deliveries (session_key, direction, external_message_id, payload, created_at)
        VALUES ($1, 'outbound', $2, $3, NOW())
      `,
      [sessionKey, externalMessageId, rawPayload]
    );

    return {
      conversationId,
      messageId: messageResult.rows[0].id,
      contactId,
    };
  });
}

export async function fetchOverview() {
  const result = await query(
    `
      SELECT
        (SELECT COUNT(*) FROM wa_inboxes) AS inboxes,
        (SELECT COUNT(*) FROM wa_inboxes WHERE status = 'connected') AS connected_inboxes,
        (SELECT COUNT(*) FROM wa_conversations WHERE status = 'open') AS open_conversations,
        (SELECT COUNT(*) FROM wa_messages WHERE created_at >= NOW() - INTERVAL '1 day') AS messages_last_day
    `
  );

  return result.rows[0];
}
