<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Command;

use OC\Core\Command\Base;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteSecret extends Base {
	public function __construct(
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('call-summary-bot:delete')
			->setDescription('Delete a secret to invalidate the connection of a Nextcloud server to this bot')
			->addArgument(
				'id',
				InputArgument::REQUIRED,
				'The identifier of the secret to delete'
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->config->deleteAppValue('call_summary_bot', 'secret_' . $input->getArgument('id'));

		$output->writeln('Deleted secret');

		return 0;
	}
}
