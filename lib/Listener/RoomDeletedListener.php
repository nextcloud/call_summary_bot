<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Listener;

use OCA\CallSummaryBot\Model\SummaryMapper;
use OCA\Talk\Events\RoomDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<Event>
 */
class RoomDeletedListener implements IEventListener {
	public function __construct(
		protected SummaryMapper $summaryMapper,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RoomDeletedEvent) {
			return;
		}

		$this->summaryMapper->deleteByConversation($event->getRoom()->getToken());
	}
}
