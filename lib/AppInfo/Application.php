<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\AppInfo;

use OCA\CallSummaryBot\Listener\BotInvokeListener;
use OCA\CallSummaryBot\Listener\ChatMessageSentListener;
use OCA\CallSummaryBot\Listener\RoomDeletedListener;
use OCA\CallSummaryBot\Notification\Notifier;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Events\RoomDeletedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'call_summary_bot';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BotInvokeEvent::class, BotInvokeListener::class);
		$context->registerEventListener(RoomDeletedEvent::class, RoomDeletedListener::class);
		$context->registerEventListener(ChatMessageSentEvent::class, ChatMessageSentListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
