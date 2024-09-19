<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Command;

use OC\Core\Command\Base;
use OCA\CallSummaryBot\Service\BotService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Repair extends Base {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $url,
		private BotService $service,
		private ISecureRandom $random,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('call-summary-bot:repair')
			->setDescription('Removes previous secrets and connects to the local server only')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$backend = rtrim($this->url->getAbsoluteURL(''), '/') . '/';

		if (!str_starts_with($backend, 'http://') && !str_starts_with($backend, 'https://')) {
			$output->writeln('<error>Backend URL must start with http:// or https:// - Please use the full "overwrite.cli.url" value from the config of the Nextcloud server</error>');
			return 1;
		}

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');

		$question = new ConfirmationQuestion('<comment>Is ' . $backend . ' the correct server URL?</comment> [y/N] ', false);
		if (!$helper->ask($input, $output, $question)) {
			return 1;
		}

		$keys = $this->config->getAppKeys('call_summary_bot');

		$secrets = [];
		foreach ($keys as $key) {
			if (str_starts_with($key, 'secret_')) {
				$secrets[$key] = json_decode($this->config->getAppValue('call_summary_bot', $key), true, 512, JSON_THROW_ON_ERROR);
			}
		}

		$this->writeTableInOutputFormat($input, $output, $secrets);

		$question = new ConfirmationQuestion('<comment>Are you sure these secrets shall be deleted?</comment> [y/N] ', false);
		if (!$helper->ask($input, $output, $question)) {
			$output->writeln('<comment>Aborted repairing</comment>');
			return 1;
		}

		foreach ($secrets as $configKey => $secret) {
			$this->service->uninstallBot($secret['secret'], $secret['backend']);
			$this->config->deleteAppValue('call_summary_bot', $configKey);
			$output->writeln('<info>Deleted bot for backend: ' . $secret['backend'] . '</info>');
		}

		$this->service->installbot($backend);
		$output->writeln('<info>Installed bot for backend: ' . $backend . '</info>');

		return 0;
	}
}
