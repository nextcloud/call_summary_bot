<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks {
	/**
	 * Private parent of OCP\Files\IRootFolder before Nextcloud 35
	 */
	interface Emitter {
		public function listen($scope, $method, callable $callback);

		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}

namespace OCA\Talk {
	class Room {
		public function getToken(): string {
		}
	}
}

namespace OCA\Talk\Events {
	class BotInstallEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			protected string $name,
			protected string $secret,
			protected string $url,
			protected string $description = '',
			protected ?int $features = null,
		) {
			parent::__construct();
		}

		public function getName(): string {
		}

		public function getSecret(): string {
		}

		public function getUrl(): string {
		}

		public function getDescription(): string {
		}

		public function getFeatures(): int {
		}
	}

	class RoomDeletedEvent extends \OCP\EventDispatcher\Event {
		public function getRoom(): \OCA\Talk\Room {
		}
	}

	class ChatMessageSentEvent extends \OCP\EventDispatcher\Event {
		public function getRoom(): \OCA\Talk\Room {
		}

		public function getComment(): \OCP\Comments\IComment {
		}
	}

	class BotUninstallEvent extends \OCP\EventDispatcher\Event {
		public function getSecret(): string {
		}

		public function getUrl(): string {
		}
	}

	/**
	 * @psalm-type ChatMessageParentData = array{
	 *     type: 'Note',
	 *     actor: array{
	 *         type: 'Person',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 *     object: array{
	 *         type: 'Note',
	 *         id: numeric-string,
	 *         name: string,
	 *         content: non-empty-string,
	 *         mediaType: 'text/markdown'|'text/plain',
	 *     },
	 * }
	 * @psalm-type ChatMessageData = array{
	 *     type: 'Activity'|'Create',
	 *     actor: array{
	 *         type: 'Person',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *         talkParticipantType: numeric-string,
	 *     },
	 *     object: array{
	 *         type: 'Note',
	 *         id: numeric-string,
	 *         name: string,
	 *         content: non-empty-string,
	 *         mediaType: 'text/markdown'|'text/plain',
	 *         inReplyTo?: ChatMessageParentData,
	 *         threadId?: int,
	 *     },
	 *     target: array{
	 *         type: 'Collection',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 * }
	 * @psalm-type ReactionMessageData = array{
	 *     type: 'Like',
	 *     actor: array{
	 *         type: 'Person',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *         talkParticipantType?: numeric-string,
	 *     },
	 *     object: array{
	 *         type: 'Note',
	 *         id: numeric-string,
	 *         name: string,
	 *         content: non-empty-string,
	 *         mediaType: 'text/markdown'|'text/plain',
	 *         inReplyTo?: ChatMessageParentData,
	 *     },
	 *     target: array{
	 *         type: 'Collection',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 *     content: string,
	 * }
	 * @psalm-type UndoReactionMessageData = array{
	 *     type: 'Undo',
	 *     actor: array{
	 *         type: 'Person',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *         talkParticipantType?: numeric-string,
	 *     },
	 *     object: ReactionMessageData,
	 *     target: array{
	 *         type: 'Collection',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 * }
	 * @psalm-type BotManagementData = array{
	 *     type: 'Join'|'Leave',
	 *     actor: array{
	 *         type: 'Application',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 *     object: array{
	 *         type: 'Collection',
	 *         id: non-empty-string,
	 *         name: non-empty-string,
	 *     },
	 * }
	 * @psalm-type InvocationData = ChatMessageData|ReactionMessageData|UndoReactionMessageData|BotManagementData
	 */
	class BotInvokeEvent extends \OCP\EventDispatcher\Event {
		public function getBotUrl(): string {
		}

		/**
		 * @return InvocationData
		 */
		public function getMessage(): array {
		}

		public function addReaction(string $emoji): void {
		}

		/**
		 * @return list<string>
		 */
		public function getReactions(): array {
		}

		public function addAnswer(string $message, bool|int $reply = false, bool $silent = false, string $referenceId = '', bool $thread = false, ?string $threadTitle = null): void {
		}

		/**
		 * @return list<array{message: string, referenceId: string, reply: bool|int, thread: bool, threadTitle: ?string, silent: bool}>
		 */
		public function getAnswers(): array {
		}
	}
}
