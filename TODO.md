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

## Admin Nest/Egg Management

- [ ] Add admin nest list page with nest name, description, author, and egg count.
- [ ] Add admin nest create/edit/delete flow with name, description, and author fields.
- [ ] Add nest detail view listing eggs with author, Docker images, startup command, and export action.
- [ ] Add egg import flow for Pterodactyl egg JSON files.
- [ ] Add egg create/edit flow for nest, name, description, author, UUID, Docker images, startup command, config files, startup config, logs config, stop command, and file denylist.
- [ ] Add egg variable management for create, edit, delete, validation rules, default values, user-viewable, and user-editable flags.
- [ ] Add egg install script management for copy-from behavior, script container/image, entrypoint, and install script body.
- [ ] Add egg export/download support.
- [ ] Add egg delete flow with safeguards when servers or shop plans still reference the egg.

## Frontend Shop

- [ ] Add post-purchase confirmation modals for server rentals, renewals, and balance uploads that show the friendly purchase summary, pricing/tax/total, remaining/new wallet balance, invoice link, and next-step actions.

## Frontend Invoice System

- [ ] Fix invoice/server notification email CSS rendering issues in the iOS Gmail app.
- [ ] Improve invoice PDF Unicode support beyond the current Windows-1252/Latin-oriented text handling.

## Registration Security

- [ ] Add registration security logging without passwords, plaintext tokens, token hashes, or session data.
- [ ] Add or document coverage for legitimate registration, bot rejection paths, resend, manual approval, cleanup, and stats.

### Admin Server Delete Tab

- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server rental/history records should remain for billing/audit history.