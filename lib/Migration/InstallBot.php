<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CallSummaryBot\Migration;

use OCA\Talk\Events\BotInstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ISecureRandom;

class InstallBot implements IRepairStep {
	public function __construct(
		protected IConfig $config,
		protected IEventDispatcher $dispatcher,
		protected ISecureRandom $random,
		protected IURLGenerator $url,
	) {
	}

	public function getName(): string {
		return 'Install as Talk bot';
	}

	public function run(IOutput $output): void {
		$backend = $this->url->getAbsoluteURL('');
		$id = sha1($backend);

		$secret = $this->config->getAppValue('call_summary_bot', 'secret_' . $id);
		if ($secret === '') {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		}

		$event = new BotInstallEvent(
			'Call summary', # FIXME translate
			$secret,
			$this->url->linkToOCSRouteAbsolute('call_summary_bot.Bot.receiveWebhook'),
			'', # FIXME add and translate
		);
		$this->dispatcher->dispatchTyped($event);

		$this->config->setAppValue('call_summary_bot', 'secret_' . $id, json_encode([
			'id' => $id,
			'secret' => $secret,
			'backend' => $backend,
		], JSON_THROW_ON_ERROR));
	}
}
