<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: CC0-1.0
-->
# Changelog
All notable changes to this project will be documented in this file.

## 3.0.1 - 2025-01-23
### Fixed
- Fix typo in new event handling
- Fix missing event feature flag on new install
- No longer uninstall the bot when disabling the app

## 3.0.0 - 2025-01-23
### Changed
- Nextcloud 31 required
- Rewritten to utilize the new Events for bots to reduce server requests

## 2.0.0 - 2024-09-16
### Added
- Agenda: You can now collect topics for the agenda list before the call and the bot will post it once the call was started
- Reports, Notes and Decisions: You can now post additional bullet-list items that are summarized at the end of the call
- Timezone: Admins can now configure a timezone for the summary messages

### Fixed
- Add a note for federated users and guests in the attendee list

## 1.2.0 - 2024-07-25
### Added
- Nextcloud 30 compatibility

### Removed
- Nextcloud 27 compatibility

## 1.1.1 - 2024-06-07
### Fixed
- Fix an issue with local domains and self-signed certificates

## 1.1.0 - 2024-03-08
### Added
- Nextcloud 29 compatibility

## 1.0.1 - 2023-12-13
### Added
- Added an OCC command to repair the bot

### Fixed
- Fixed a bug in the OCC command to delete a secret

## 1.0.0 - 2023-10-30
### Added
- Nextcloud 28 compatibility

## 1.0.0-rc2 - 2023-08-25
### Changed
- Initial release
