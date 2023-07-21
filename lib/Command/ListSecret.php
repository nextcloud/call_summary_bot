<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Joas Schilling <coding@schilljs.com>
 *
 * @author  Joas Schilling <coding@schilljs.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
