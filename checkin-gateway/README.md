# Command Bridge Check-In Gateway

> **Public beta (0.9.0-beta.1).** Part of the [Command Bridge repository](../README.md); the root README explains how this gateway and the main app work together. Run it on a **publicly reachable** server. Licensed under the GNU AGPL-3.0 ([LICENSE.txt](../LICENSE.txt)).

A standalone, English-language public gateway for authorized Check-In Recorders and Staging Area Managers. It receives only the minimal location/expected-resource manifest from CommandBridge through HMAC-authenticated JSON synchronization, records public check-in or exception events locally, and sends those events back through the internal receipt API.

## Safety boundary

- No manual resource creation, authorization, assignment, release, or demobilization.
- Unscheduled arrivals are alert events only.
- Authorized CIR/SAM users can access an active location only through its linked location URL and their individually issued PIN.
- The gateway receives only the CIR/SAM display name, role, authorized location IDs, active/revoked state, and bcrypt PIN verifier required for gateway-side authorization.
- Each check-in and exception event is attributed to the authenticated CommandBridge gateway user ID, display name, role, and location.
- The primary CommandBridge database is never exposed or connected to by this application.

Install with `sudo ./install.sh` on a separate Debian 12/13 server (see `docs/INSTALL.md`); upgrade with `./update.sh` (`docs/UPDATING.md`). Test in a non-production scenario before enabling operational use.

## CIR/SAM companion-user synchronization

CommandBridge must send `companion_user.upsert` for each active, non-revoked CIR/SAM user with at least one authorized active check-in location. The gateway accepts the synchronized bcrypt `pin_verifier`, but never receives or stores a plaintext PIN.

A `companion_user.revoke` event immediately disables that user’s gateway access while retaining the gateway audit history. New and existing deployments must apply the gateway database migration before the CommandBridge companion-user backfill/worker is enabled.

The shared location access-code hash remains in the location synchronization record for transition compatibility only; it is not used by the CIR/SAM PIN sign-in flow.

## PIN sign-in throttling

Failed PIN attempts are throttled per address, per repeat offender, and per location (5 failures in 15 minutes per address or guessed PIN; 15 in 24 hours from one address; 40 in 15 minutes across a location). Administrators can list and clear blocks with `bin/clear_lockouts.php --list|--ip=|--location=|--all`. See `CHANGELOG.md` and `docs/INSTALL.md`.
