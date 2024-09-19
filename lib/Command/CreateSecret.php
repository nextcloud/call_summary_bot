<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Command;

use OC\Core\Command\Base;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSecret extends Base {
	public function __construct(
		private IConfig $config,
		private ISecureRandom $random,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('call-summary-bot:create')
			->setDescription('Create a secret to connect a new Nextcloud server to this bot')
			->addArgument(
				'backend',
				InputArgument::REQUIRED,
				'The Nextcloud server this secret will be connected with (e.g. "https://example.tld/")'
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$backend = rtrim($input->getArgument('backend'), '/') . '/';

		if (!str_starts_with($backend, 'http://') && !str_starts_with($backend, 'https://')) {
			$output->writeln('<error>Backend URL must start with http:// or https:// - Please use the full "overwrite.cli.url" value from the config of the Nextcloud server</error>');
			return 1;
		}

		$id = sha1($backend);
		$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);

		$this->config->setAppValue('call_summary_bot', 'secret_' . $id, json_encode([
			'id' => $id,
			'secret' => $secret,
			'backend' => $backend,
		], JSON_THROW_ON_ERROR));

		$output->writeln('Secret:');
		$output->writeln($secret);

		return 0;
	}
}
