<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\AppInfo;

use OCA\Talk\Events\BotNotifyEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('call_summary_bot');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BotNotifyEvent::class, FlowRegisterOperationListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
