# TODO

## Admin Node Management

- [ ] Add admin node list page with search, location, FQDN, scheme, maintenance, memory, disk, allocations, and server counts.
- [ ] Add admin node create flow with name, location, FQDN, scheme, public/private visibility, behind-proxy, daemon ports, upload size, memory/disk limits, and overallocate settings.
- [ ] Add admin node detail tabs for About, Settings, Configuration, Allocations, Servers, and System Information.
- [ ] Add node settings update flow with validation that matches Pterodactyl's node edit behavior.
- [ ] Add node configuration view with Wings configuration output and auto-deploy token generation.
- [ ] Add node allocation management for creating IP/port ranges, setting aliases/notes, and deleting single or multiple allocations.
- [ ] Add node servers tab showing servers assigned to the node with owner, allocation, status, and quick admin links.
- [ ] Add node delete flow with safeguards when allocations or servers are still attached.

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

- [ ] Add order detail pages for completed balance uploads, server rentals, and renewals.

## Frontend Invoice System

- [ ] Improve invoice PDF Unicode support beyond the current Windows-1252/Latin-oriented text handling.

## Registration Security

- [ ] Add registration security logging without passwords, plaintext tokens, token hashes, or session data.
- [ ] Add or document coverage for legitimate registration, bot rejection paths, resend, manual approval, cleanup, and stats.

### Admin Server Delete Tab

- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server rental/history records should remain for billing/audit history.
