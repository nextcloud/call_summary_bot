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
use OCP\L10N\IFactory;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ISecureRandom;

class InstallBot implements IRepairStep {
	public function __construct(
		protected IConfig $config,
		protected IEventDispatcher $dispatcher,
		protected ISecureRandom $random,
		protected IURLGenerator $url,
		protected IFactory $l10nFactory,
	) {
	}

	public function getName(): string {
		return 'Install as Talk bot';
	}

	public function run(IOutput $output): void {
		if (!class_exists(BotInstallEvent::class)) {
			$output->warning('Talk not found, not installing the bot');
			return;
		}

		$backend = $this->url->getAbsoluteURL('');
		$id = sha1($backend);

		$secretData = $this->config->getAppValue('call_summary_bot', 'secret_' . $id);
		if ($secretData) {
			$secretArray = json_decode($secretData, true, 512, JSON_THROW_ON_ERROR);
			$secret = $secretArray['secret'] ?? $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		} else {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		}

		$this->installLanguage($secret, 'en');
		$this->installLanguage($secret, 'de');
		$this->installLanguage($secret, 'es');
		$this->installLanguage($secret, 'fr');
		$this->installLanguage($secret, 'ar');
		$this->installLanguage($secret, 'pt_BR');
		$this->installLanguage($secret, 'tr');
		$this->installLanguage($secret, 'zh_CN');

		$this->config->setAppValue('call_summary_bot', 'secret_' . $id, json_encode([
			'id' => $id,
			'secret' => $secret,
			'backend' => $backend,
		], JSON_THROW_ON_ERROR));
	}

	protected function installLanguage(string $secret, string $lang): void {
		$libL10n = $this->l10nFactory->get('lib', $lang);
		$langName = $libL10n->t('__language_name__');
		if ($langName === '__language_name__') {
			$langName = $lang === 'en' ? 'British English' : $lang;
		}

		$l = $this->l10nFactory->get('call_summary_bot', $lang);

		$event = new BotInstallEvent(
			$l->t('Call summary'),
			$secret,
			$this->url->linkToOCSRouteAbsolute('call_summary_bot.Bot.receiveWebhook', ['lang' => $lang]),
			$l->t('Call summary (%s)', $langName) . ' - ' . $l->t('The call summary bot posts an overview message after the call listing all participants and outlining tasks'),
		);
		$this->dispatcher->dispatchTyped($event);
	}
}
