<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Service;

use OCA\CallSummaryBot\AppInfo\Application;
use OCA\CallSummaryBot\Model\Summary;
use OCA\CallSummaryBot\Model\SummaryMapper;
use OCA\CallSummaryBot\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class SummaryArchiveService {
	public const LIST_LIMIT = 10;
	public const COMMAND_PATTERN = '/^!summaries(?:\s+(.*))?$/is';
	public const ARGUMENTS_PATTERN = '/^(?:(export)\s*)?(?:(\d{4})(?:-(\d{2}))?)?$/i';

	public function __construct(
		protected SummaryMapper $summaryMapper,
		protected SummaryService $summaryService,
		protected IDateTimeFormatter $dateTimeFormatter,
		protected IFactory $l10nFactory,
		protected IURLGenerator $urlGenerator,
		protected IRootFolder $rootFolder,
		protected INotificationManager $notificationManager,
		protected ITimeFactory $timeFactory,
		protected LoggerInterface $logger,
	) {
	}

	public function isCommand(string $message): bool {
		return (bool)preg_match(self::COMMAND_PATTERN, trim($message));
	}

	/**
	 * Handles `!summaries [export] [YYYY[-MM]]` and returns the answer to post,
	 * or null when the export succeeded and the user was notified
	 *
	 * @param string $actorId Actor ID as sent in the bot event, e.g. `users/admin`
	 */
	public function handleCommand(string $message, string $token, string $roomName, string $actorId, string $lang): ?string {
		$l = $this->l10nFactory->get('call_summary_bot', $lang);

		preg_match(self::COMMAND_PATTERN, trim($message), $commandMatches);
		$arguments = trim($commandMatches[1] ?? '');
		if (!preg_match(self::ARGUMENTS_PATTERN, $arguments, $matches)) {
			return $this->getUsage($l);
		}

		$export = !empty($matches[1]);
		$period = null;
		if (!empty($matches[2])) {
			$period = $this->getPeriod((int)$matches[2], isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : null);
			if ($period === null) {
				return $this->getUsage($l);
			}
		}

		if ($export) {
			return $this->export($token, $roomName, $actorId, $lang, $period);
		}
		return $this->list($token, $lang, $period);
	}

	/**
	 * @param array{from: int, to: int, label: string}|null $period
	 */
	public function list(string $token, string $lang, ?array $period = null): string {
		$l = $this->l10nFactory->get('call_summary_bot', $lang);
		$summaries = $this->summaryMapper->findByConversation($token, $period['from'] ?? null, $period['to'] ?? null, self::LIST_LIMIT);
		if (empty($summaries)) {
			return '*' . $l->t('No call summaries found') . '*';
		}

		$libL10N = $this->l10nFactory->get('lib', $lang);
		$timezone = $this->summaryService->getTimezone();
		$callUrl = $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $token]);

		$answer = '# ' . $l->t('Call summaries') . "\n\n";
		foreach ($summaries as $summary) {
			$date = $this->dateTimeFormatter->formatDate($summary->getStartTime(), 'full', $timezone, $libL10N)
				. ' · ' . $this->dateTimeFormatter->formatTime($summary->getStartTime(), 'short', $timezone, $libL10N)
				. ' – ' . $this->dateTimeFormatter->formatTime($summary->getEndTime(), 'short', $timezone, $libL10N);
			if ($summary->getMessageId() !== null) {
				$date = '[' . $date . '](' . $callUrl . '#message_' . $summary->getMessageId() . ')';
			}
			$answer .= '- ' . $date . ' · ' . $l->n('%n attendee', '%n attendees', $summary->getAttendeeCount()) . "\n";
		}

		$total = $this->summaryMapper->countByConversation($token, $period['from'] ?? null, $period['to'] ?? null);
		if ($total > count($summaries)) {
			$answer .= "\n*" . str_replace(
				['{count}', '{total}', '{command_filter}', '{command_export}'],
				[(string)count($summaries), (string)$total, '`!summaries YYYY-MM`', '`!summaries export`'],
				$l->t('Showing the latest {count} of {total} summaries. Filter by month with {command_filter} or export all of them with {command_export}.')
			) . "*\n";
		}

		return $answer;
	}

	/**
	 * Writes the summaries into a Markdown file in the files of the requesting user
	 * and notifies them about it. Returns an error message to post, or null on success.
	 *
	 * @param array{from: int, to: int, label: string}|null $period
	 */
	public function export(string $token, string $roomName, string $actorId, string $lang, ?array $period = null): ?string {
		$l = $this->l10nFactory->get('call_summary_bot', $lang);
		if (!str_starts_with($actorId, 'users/')) {
			return '*' . $l->t('Only users of this server can export call summaries') . '*';
		}
		$userId = substr($actorId, strlen('users/'));

		$summaries = $this->summaryMapper->findByConversation($token, $period['from'] ?? null, $period['to'] ?? null);
		if (empty($summaries)) {
			return '*' . $l->t('No call summaries found') . '*';
		}

		$title = $this->summaryService->getTitle($l, $roomName);
		$content = '# ' . $title . ($period !== null ? ' (' . $period['label'] . ')' : '') . "\n";
		foreach ($summaries as $summary) {
			$content .= "\n" . $this->formatForExport($summary) . "\n\n---\n";
		}

		$folderName = $this->sanitizeFileName($l->t('Call summaries'));
		$baseName = $this->summaryService->isOneToOne($roomName) ? $token : $roomName;
		$now = $this->timeFactory->getDateTime('now', $this->summaryService->getTimezone() ?? new \DateTimeZone('UTC'));
		$fileName = $this->sanitizeFileName($baseName . ($period !== null ? ' ' . $period['label'] : '') . ' - ' . $now->format('Y-m-d H-i-s')) . '.md';

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			try {
				$folder = $userFolder->get($folderName);
				if (!$folder instanceof Folder) {
					return '*' . str_replace('{folder}', $folderName, $l->t('Export failed because "{folder}" in your files is not a folder')) . '*';
				}
			} catch (NotFoundException) {
				$folder = $userFolder->newFolder($folderName);
			}

			$file = $folder->newFile($folder->getNonExistingName($fileName), $content);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to export call summaries of conversation ' . $token, ['exception' => $e]);
			return '*' . $l->t('Export failed') . '*';
		}

		$this->notifyExport($userId, $file->getId(), count($summaries), $this->summaryService->isOneToOne($roomName) ? '' : $roomName, $now);
		return null;
	}

	protected function notifyExport(string $userId, int $fileId, int $count, string $roomName, \DateTime $dateTime): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setObject(Notifier::OBJECT_TYPE_EXPORT, (string)$fileId)
			->setSubject(Notifier::SUBJECT_EXPORTED, [
				'count' => $count,
				'roomName' => $roomName,
			])
			->setDateTime($dateTime);
		$this->notificationManager->notify($notification);
	}

	/**
	 * Replaces the "# Call summary" heading with the date line,
	 * so the summaries can be nested below the title of the export
	 */
	protected function formatForExport(Summary $summary): string {
		$lines = explode("\n", rtrim($summary->getSummary()));
		if (isset($lines[0], $lines[2]) && str_starts_with($lines[0], '# ') && $lines[1] === '') {
			array_splice($lines, 0, 2);
			$lines[0] = '# ' . $lines[0];
		}

		return implode("\n", array_map(
			static fn (string $line): string => str_starts_with($line, '#') ? '#' . $line : $line,
			$lines
		));
	}

	/**
	 * @return array{from: int, to: int, label: string}|null
	 */
	protected function getPeriod(int $year, ?int $month): ?array {
		if ($month !== null && ($month < 1 || $month > 12)) {
			return null;
		}

		$timezone = $this->summaryService->getTimezone() ?? new \DateTimeZone('UTC');
		$from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month ?? 1), $timezone);
		$to = $from->modify($month === null ? '+1 year' : '+1 month');

		return [
			'from' => $from->getTimestamp(),
			'to' => $to->getTimestamp(),
			'label' => $month === null ? sprintf('%04d', $year) : sprintf('%04d-%02d', $year, $month),
		];
	}

	protected function sanitizeFileName(string $name): string {
		$name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '-', $name) ?? '';
		$name = trim($name, " .-\t");
		if (mb_strlen($name) > 200) {
			$name = mb_substr($name, 0, 200);
		}
		return $name !== '' ? $name : 'summaries';
	}

	protected function getUsage(IL10N $l): string {
		return str_replace(
			['{command_list}', '{command_export}'],
			['`!summaries [YYYY[-MM]]`', '`!summaries export [YYYY[-MM]]`'],
			$l->t("- {command_list} lists the latest call summaries\n- {command_export} saves the call summaries as a Markdown file in your files")
		);
	}
}
