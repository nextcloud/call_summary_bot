<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Command;

use OC\Core\Command\Base;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListSecret extends Base {
	public function __construct(
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('call-summary-bot:list')
			->setDescription('List all secrets')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$keys = $this->config->getAppKeys('call_summary_bot');

		$secrets = [];
		foreach ($keys as $key) {
			if (str_starts_with($key, 'secret_')) {
				$secrets[] = json_decode($this->config->getAppValue('call_summary_bot', $key), true, 512, JSON_THROW_ON_ERROR);
			}
		}

		$this->writeTableInOutputFormat($input, $output, $secrets);

		return 0;
	}
}
