<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Listener;

use OCA\Talk\Events\BotNotifyEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<Event>
 */
class BotNotifyListener implements IEventListener {
	public function __construct(
		protected readonly SummaryService $service,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BotNotifyEvent) {
			return;
		}

		$event->registerOperation($this->operation);
		Util::addScript('files_accesscontrol', 'admin');
	}
}
