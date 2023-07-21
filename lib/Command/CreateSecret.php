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
