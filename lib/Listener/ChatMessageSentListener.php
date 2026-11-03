<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Listener;

use OCA\CallSummaryBot\Model\SummaryMapper;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\Comments\IComment;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stores the message ID of a posted call summary, so `!summaries` can link to it
 *
 * @template-implements IEventListener<Event>
 */
class ChatMessageSentListener implements IEventListener {
	public function __construct(
		protected SummaryMapper $summaryMapper,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ChatMessageSentEvent) {
			return;
		}

		$this->handleComment($event->getComment());
	}

	public function handleComment(IComment $comment): void {
		$referenceId = $comment->getReferenceId();
		if ($comment->getActorType() !== 'bots' || $referenceId === null || $referenceId === '') {
			return;
		}

		$this->summaryMapper->setMessageIdByReferenceId($referenceId, (int)$comment->getId());
	}
}
