# Internal Pull Synchronization API

Use this API when CommandBridge is internal-only and cannot accept inbound requests from the Check-In Gateway. The internal CommandBridge server initiates both HTTPS requests to the gateway.

## Gateway configuration

```ini
GATEWAY_SYNC_ENABLED=1
GATEWAY_DELIVERY_MODE=pull
GATEWAY_PULL_LEASE_SECONDS=300
```

In pull mode, the legacy gateway dispatcher does not post queued check-ins to `COMMANDBRIDGE_EVENTS_URL`.

## Request authentication

Both endpoints use the existing shared HMAC key and headers:

- `X-CommandBridge-Timestamp`: current Unix timestamp
- `X-CommandBridge-Nonce`: a unique 16–128 character nonce
- `X-CommandBridge-Key-Id`: `EXTERNAL_CHECKIN_HMAC_KEY_ID`
- `X-CommandBridge-Signature`: Base64 HMAC-SHA256 of `timestamp + "\n" + nonce + "\n" + exact request body`

The HMAC key is `EXTERNAL_CHECKIN_HMAC_SECRET`. The internal poller must validate the gateway TLS certificate.

## Pull events

`POST /api/v1/sync/pull.php`

```json
{
  "protocol_version": "1.0",
  "request_id": "a-unique-32-or-more-character-lowercase-hex-value",
  "action": "events.pull",
  "limit": 25
}
```

The response includes an `events` array containing queued `checkin.recorded` events. Processing must be idempotent using each event's `message_id`.

## Acknowledge an event

`POST /api/v1/sync/ack.php`

```json
{
  "protocol_version": "1.0",
  "request_id": "a-new-unique-32-or-more-character-lowercase-hex-value",
  "action": "events.ack",
  "message_id": "message_id from the pulled event",
  "outcome": "processed"
}
```

Use `retry` for a transient failure. Use `rejected` only for a permanent processing failure; it prevents further delivery and retains the event as failed for operational review.

## CIR/SAM companion-user inbound synchronization

The same signed request format is used by CommandBridge when it sends synchronization events to:

```txt
POST /api/v1/sync/events.php
```

In addition to location and expected-resource records, the gateway accepts:

- `companion_user.upsert`
- `companion_user.revoke`

A companion-user upsert requires a CommandBridge `gateway_user_id`, display name, one or more `CIR`/`SAM` roles, at least one authorized location ID, active/non-revoked booleans, a bcrypt `pin_verifier`, and an optional PIN reset timestamp. Each authorized location must already be synchronized for the same incident.

A revoke requires the incident ID and `gateway_user_id`. It immediately disables all location assignments for that user but preserves audit records and prior event attribution. The gateway does not accept plaintext PINs.
