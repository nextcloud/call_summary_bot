<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Listener;

use OCA\CallSummaryBot\AppInfo\Application;
use OCA\CallSummaryBot\Model\Bot;
use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCA\CallSummaryBot\Service\SummaryService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	public function __construct(
		protected ITimeFactory $timeFactory,
		protected IFactory $l10nFactory,
		protected LogEntryMapper $logEntryMapper,
		protected SummaryService $summaryService,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BotInvokeEvent) {
			return;
		}

		if (!str_starts_with($event->getBotUrl(), 'nextcloudapp://' . Application::APP_ID . '/')) {
			return;
		}

		[,, $appId, $lang] = explode('/', $event->getBotUrl(), 4);
		if ($appId !== Application::APP_ID || !in_array($lang, Bot::SUPPORTED_LANGUAGES, true)) {
			return;
		}

		$this->receiveWebhook($lang, $event);
	}

	public function receiveWebhook(string $lang, BotInvokeEvent $event): void {
		$data = $event->getMessage();
		if (($data['type'] === 'Create' && $data['object']['name'] === 'message')
			// Nextcloud 33 and newer
			|| ($data['type'] === 'Activity' && $data['object']['name'] === 'message')
			// Nextcloud 32 and older
			|| ($data['type'] === 'Activity' && $data['object']['name'] === '')) {

			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];

			if ($message === Bot::COMMAND_SILENT_MUTED
				|| $message === Bot::COMMAND_SILENT_POST) {
				$this->logEntryMapper->deleteSetting($data['target']['id'], LogEntry::TYPE_SETTING_IGNORE_SILENT);
				$this->setSetting(
					$data['target']['id'],
					LogEntry::TYPE_SETTING_IGNORE_SILENT,
					$message === Bot::COMMAND_SILENT_MUTED ? LogEntry::DETAILS_IGNORE_SILENT_MUTED : LogEntry::DETAILS_IGNORE_SILENT_STILL_POST
				);
				$event->addReaction($message === Bot::COMMAND_SILENT_MUTED ? '🔕' : '📝');
				return;
			}

			$hasAttachment = isset($messageData['parameters']['file']);

			if (!$this->logEntryMapper->hasActiveCall($data['target']['id'])) {
				$displayName = $this->getAuthorDisplayName($data['actor']['name'], $data['actor']['id'], $lang);
				$agendaDetected = $this->summaryService->readAgendaFromMessage($message, $messageData, $data, $displayName, $hasAttachment, $lang);

				if ($agendaDetected) {
					// React with thumbs up as we detected an agenda item
					$event->addReaction('👍');
				}
				return;
			}

			$taskDetected = $this->summaryService->readTasksFromMessage($message, $messageData, $data, $hasAttachment, $lang);

			if ($taskDetected) {
				// React with thumbs up as we detected a task
				$event->addReaction('👍');
			}
		} elseif ($data['type'] === 'Activity') {
			if ($data['object']['name'] === 'call_joined' || $data['object']['name'] === 'call_started') {
				if ($data['object']['name'] === 'call_started') {
					$settings = $this->logEntryMapper->getSetting($data['target']['id'], LogEntry::TYPE_SETTING_IGNORE_SILENT);
					if ($settings === LogEntry::DETAILS_IGNORE_SILENT_MUTED && $this->isSilentCallSystemMessage($data['object']['content'])) {
						// Skip on silent calls when agenda is muted
						return;
					}

					$agenda = $this->summaryService->agenda($data['target']['id'], $lang);
					if ($agenda !== null) {
						$event->addAnswer($agenda, true);

						if ($settings === null && $this->isSilentCallSystemMessage($data['object']['content'])) {
							$l = $this->l10nFactory->get('call_summary_bot', $lang);
							$hint = $l->t("Silent calls can be ignored and not trigger the agenda.\n\n- {reaction_ignore} Post {command_ignore} to ignore silent calls.\n- {reaction_continue} To later enable it later again post {command_continue}");
							$event->addAnswer(str_replace(
								['{reaction_ignore}', '{command_ignore}', '{reaction_continue}', '{command_continue}'],
								['🔕', '`' . Bot::COMMAND_SILENT_MUTED . '`', '📝', '`' . Bot::COMMAND_SILENT_POST . '`'],
								$hint
							));

							$this->setSetting($data['target']['id'], LogEntry::TYPE_SETTING_IGNORE_SILENT, LogEntry::DETAILS_IGNORE_SILENT_HINT_POSTED);
						}
					}

					$logEntry = new LogEntry();
					$logEntry->setServer('local');
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_START);
					$logEntry->setDetails((string)$this->timeFactory->now()->getTimestamp());
					$this->logEntryMapper->insert($logEntry);

					$logEntry = new LogEntry();
					$logEntry->setServer('local');
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_ELEVATOR);
					$logEntry->setDetails((string)$data['object']['id']);
					$this->logEntryMapper->insert($logEntry);
				} elseif (!$this->logEntryMapper->hasActiveCall($data['target']['id'])) {
					return;
				}

				$logEntry = new LogEntry();
				$logEntry->setServer('local');
				$logEntry->setToken($data['target']['id']);
				$logEntry->setType(LogEntry::TYPE_ATTENDEE);

				$displayName = $this->getAuthorDisplayName($data['actor']['name'], $data['actor']['id'], $lang);
				if ($displayName === null) {
					return;
				}

				$logEntry->setDetails($displayName);
				if ($logEntry->getDetails()) {
					// Only store when not empty
					$this->logEntryMapper->insert($logEntry);
				}
			} elseif ($data['object']['name'] === 'call_ended' || $data['object']['name'] === 'call_ended_everyone') {
				if (!$this->logEntryMapper->hasActiveCall($data['target']['id'])) {
					// Skip on silent calls when agenda is muted
					return;
				}

				$summary = $this->summaryService->summarize($data['target']['id'], $data['target']['name'], $lang);
				if ($summary !== null) {
					$event->addAnswer($summary['summary'], $summary['elevator']);
				}
			} elseif ($data['object']['name'] === 'call_missed') {
				if (!$this->logEntryMapper->hasActiveCall($data['target']['id'])) {
					// Skip on silent calls when agenda is muted
					return;
				}

				$this->logEntryMapper->deleteByConversation($data['target']['id']);
			}
		}
	}

	protected function getAuthorDisplayName(string $name, string $id, string $lang): ?string {
		$displayName = $name;
		if (str_starts_with($id, 'guests/') || str_starts_with($id, 'emails/')) {
			if ($displayName === '') {
				return null;
			}
			$l = $this->l10nFactory->get('call_summary_bot', $lang);
			$displayName = $l->t('%s (guest)', $displayName);
		} elseif (str_starts_with($id, 'federated_users/')) {
			$cloudIdServer = explode('@', $id);
			$displayName .= ' (' . array_pop($cloudIdServer) . ')';
		}

		return $displayName;
	}

	protected function isSilentCallSystemMessage(string $content): bool {
		$systemMessage = json_decode($content, true);
		if (is_array($systemMessage) && isset($systemMessage['message'])) {
			$talkL10N = $this->l10nFactory->get('spreed', 'en', 'en');
			if ($systemMessage['message'] === $talkL10N->t('{actor} started a silent call')) {
				return true;
			}
		}
		return false;
	}

	public function setSetting(string $token, string $type, string $details) {
		$logEntry = new LogEntry();
		$logEntry->setServer('local');
		$logEntry->setToken($token);
		$logEntry->setType($type);
		$logEntry->setDetails($details);
		$this->logEntryMapper->insert($logEntry);
	}
}
