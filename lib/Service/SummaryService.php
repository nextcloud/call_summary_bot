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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CallSummaryBot\Service;

use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeFormatter;
use OCP\L10N\IFactory;

class SummaryService {
	public function __construct(
		protected LogEntryMapper $logEntryMapper,
		protected ITimeFactory $timeFactory,
		protected IDateTimeFormatter $dateTimeFormatter,
		protected IFactory $l10nFactory,
	) {
	}

	public function summarize(string $server, string $token, string $roomName, string $language = 'en'): ?string {
		$logEntries = $this->logEntryMapper->findByConversation($server, $token);
		$this->logEntryMapper->deleteByConversation($server, $token);

		$libL10N = $this->l10nFactory->get('lib', $language);
		$l = $this->l10nFactory->get('call_summary_bot', $language);

		$endTimestamp = $this->timeFactory->now()->getTimestamp();
		$startTimestamp = $endTimestamp;

		$attendees = [];
		$todos = [];

		foreach ($logEntries as $logEntry) {
			if ($logEntry->getType() === LogEntry::TYPE_START) {
				$time = (int) $logEntry->getDetails();
				if ($startTimestamp > $time) {
					$startTimestamp = $time;
				}
			} elseif ($logEntry->getType() === LogEntry::TYPE_ATTENDEE) {
				$attendees[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_TODO) {
				$todos[] = $logEntry->getDetails();
			}
		}

		if (($endTimestamp - $startTimestamp) < 60) { // FIXME
			// No call summary for calls below 1 minute
			return null;
		}

		$attendees = array_unique($attendees);
		$todos = array_unique($todos);

		$startDate = $this->dateTimeFormatter->formatDate($startTimestamp, 'full', null, $libL10N);
		$startTime = $this->dateTimeFormatter->formatTime($startTimestamp, 'short', null, $libL10N);
		$endTime = $this->dateTimeFormatter->formatTime($endTimestamp, 'short', null, $libL10N);


		$summary = '# ' . str_replace('{title}', $roomName, $l->t('Call summary - {title}')) . "\n\n";
		$summary .= $startDate . ' · ' . $startTime  . ' – ' . $endTime . "\n";

		$summary .= "\n";
		$summary .= '## ' . $l->t('Attendees') . "\n";
		foreach ($attendees as $attendee) {
			$summary .= '- ' . $attendee . "\n";
		}

		if (!empty($todos)) {
			$summary .= "\n";
			$summary .= '## ' . $l->t('Tasks') . "\n";
			foreach ($todos as $todo) {
				$summary .= '- ' . $todo . "\n";
			}
		}

		return $summary;
	}
}
