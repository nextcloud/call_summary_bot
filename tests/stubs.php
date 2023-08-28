<?php

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
