# TODO

## Admin Node Management

- [ ] Add node auto-deploy token generation.
- [ ] Add node allocation notes editing support.
- [ ] Add node server status display to the node Servers tab.
- [ ] Replace Live Daemon Details card in System Information tab with live telemetry: Daemon Status, Node Uptime (Host Uptime + rolling 30-day availability), Load Average (Host CPU), RAM Used (Host Memory), Disk Used (Host Disk).

## Admin Mounts Management

- [ ] Add admin mount list page with name, source, target, read-only, user-mountable, egg count, and node count.
- [ ] Add admin mount create/edit flow for name, description, source path, target path, read-only, and user-mountable options.
- [ ] Add mount detail view showing assigned eggs and assigned nodes.
- [ ] Add mount egg assignment controls for linking and unlinking compatible eggs.
- [ ] Add mount node assignment controls for linking and unlinking nodes.
- [ ] Add mount delete behavior or document why deletes should remain panel-only.

## Frontend Invoice System

- [ ] Fix invoice/server notification email CSS rendering issues in the iOS Gmail app.
- [ ] Improve invoice PDF Unicode support beyond the current Windows-1252/Latin-oriented text handling.

## Registration Security

- [ ] Add registration security logging without passwords, plaintext tokens, token hashes, or session data.
- [ ] Add or document coverage for legitimate registration, bot rejection paths, resend, manual approval, cleanup, and stats.

### Admin Server Delete Tab

- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server rental/history records should remain for billing/audit history.