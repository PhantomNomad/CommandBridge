# Internal Check-In Pull Synchronization

The public gateway lives in [`checkin-gateway/`](../../checkin-gateway/) in this repository; install it first on a separate,
publicly reachable server (its installer prints the values used below).

## Enabling the timer

The Command Bridge installer installs but does not enable the sync timer. After editing the settings file below:

```bash
sudo systemctl enable --now commandbridge-external-checkin-sync.timer
systemctl list-timers commandbridge-external-checkin-sync.timer
journalctl -u commandbridge-external-checkin-sync.service -n 30
```

CommandBridge remains internal-only. The existing `commandbridge-external-checkin-sync.timer`
runs the worker once per minute. The worker pushes location and expected-resource updates to
the gateway, then makes an outbound authenticated HTTPS poll to retrieve queued check-ins.

Set these values in `/etc/commandbridge/external_checkin_sync.env`:

```ini
EXTERNAL_CHECKIN_ENABLED=1
EXTERNAL_CHECKIN_GATEWAY_URL=https://checkin.example.ca/api/v1/sync/events.php
EXTERNAL_CHECKIN_GATEWAY_PULL_URL=https://checkin.example.ca/api/v1/sync/pull.php
EXTERNAL_CHECKIN_GATEWAY_ACK_URL=https://checkin.example.ca/api/v1/sync/ack.php
EXTERNAL_CHECKIN_HMAC_KEY_ID=commandbridge-1
EXTERNAL_CHECKIN_HMAC_SECRET=...
```

Use the same key ID and HMAC secret on the Check-In Gateway. The gateway must be configured
for pull delivery. The internal server needs outbound HTTPS access to the gateway; it does not
need inbound Internet access.

A successfully processed report updates the Resources Unit intake record and creates or updates
the linked ICS 211 entry for personnel/crew resources or ICS 218 entry for vehicle/equipment
resources. Submitted ICS forms are not altered; those reports remain queued for review.
