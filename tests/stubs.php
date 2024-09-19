<?php
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Events {
	class BotInstallEvent extends \OCP\EventDispatcher\Event {
		public function getName(): string {
		}

		public function getSecret(): string {
		}

		public function getUrl(): string {
		}

		public function getDescription(): string {
		}
	}

	class BotUninstallEvent extends \OCP\EventDispatcher\Event {
		public function getSecret(): string {
		}

		public function getUrl(): string {
		}
	}
}
