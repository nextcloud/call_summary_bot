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

use OCA\CallSummaryBot\Service\BotService;
use OCA\Talk\Events\BotInstallEvent;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class InstallBot implements IRepairStep {
	public function __construct(
		protected IURLGenerator $url,
		protected BotService $service,
	) {
	}

	public function getName(): string {
		return 'Install as Talk bot';
	}

	public function run(IOutput $output): void {
		if (!class_exists(BotInstallEvent::class)) {
			$output->warning('Talk not found, not installing bots');
			return;
		}

		$backend = $this->url->getAbsoluteURL('');
		$this->service->installBot($backend);
	}
}
