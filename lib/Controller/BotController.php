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

namespace OCA\CallSummaryBot\Controller;

use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCA\CallSummaryBot\Service\SummaryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BotController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		protected IClientService $clientService,
		protected ITimeFactory $timeFactory,
		protected LogEntryMapper $logEntryMapper,
		protected SummaryService $summaryService,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the body of the POST request
	 */
	protected function getInputStream(): string {
		return file_get_contents('php://input');
	}

	#[BruteForceProtection(action: 'webhook')]
	#[PublicPage]
	public function receiveWebhook(string $lang): DataResponse {
		$signature = $this->request->getHeader('X_NEXTCLOUD_TALK_SIGNATURE');
		$random = $this->request->getHeader('X_NEXTCLOUD_TALK_RANDOM');
		$server = rtrim($this->request->getHeader('X_NEXTCLOUD_TALK_BACKEND'), '/') . '/';

		$secretData = $this->config->getAppValue('call_summary_bot', 'secret_' . sha1($server));
		if ($secretData === '') {
			$this->logger->warning('No matching secret found for server: ' . $server);
			$response = new DataResponse(null, Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		try {
			$config = json_decode($secretData, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->logger->error('Could not json_decode config');
			return new DataResponse(null, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$body = $this->getInputStream();
		$generatedDigest = hash_hmac('sha256', $random . $body, $config['secret']);

		if (!hash_equals($generatedDigest, strtolower($signature))) {
			$this->logger->warning('Message signature could not be verified');
			$response = new DataResponse(null, Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		$this->logger->debug($body);
		$data = json_decode($body, true);

		if ($data['type'] === 'Create' && $data['object']['name'] === 'message') {
			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];

			$placeholders = $replacements = [];
			foreach ($messageData['parameters'] as $placeholder => $parameter) {
				$placeholders[] = '{' . $placeholder . '}';
				if ($parameter['type'] === 'user') {
					if (str_contains($parameter['id'], ' ') || str_contains($parameter['id'], '/')) {
						$replacements[] = '@"' . $parameter['id'] . '"';
					} else {
						$replacements[] = '@' . $parameter['id'];
					}
				} elseif ($parameter['type'] === 'call') {
					$replacements[] = '@all';
				} elseif ($parameter['type'] === 'guest') {
					$replacements[] = '@' . $parameter['name'];
				} else {
					$replacements[] = $parameter['name'];
				}
			}
			$parsedMessage = str_replace($placeholders, $replacements, $message);

			if (str_starts_with($parsedMessage, '-')) {
				$todos = explode("\n-", $parsedMessage);
				foreach ($todos as $todo) {
					$logEntry = new LogEntry();
					$logEntry->setServer($server);
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_TODO);
					$logEntry->setDetails(trim(ltrim($todo, '-')));
					if ($logEntry->getDetails()) {
						// Only store when not empty
						$this->logEntryMapper->insert($logEntry);
					}
				}
			} elseif (str_starts_with($parsedMessage, '*')) {
				$todos = explode("\n*", $parsedMessage);
				foreach ($todos as $todo) {
					$logEntry = new LogEntry();
					$logEntry->setServer($server);
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_TODO);
					$logEntry->setDetails(trim(ltrim($todo, '*')));
					if ($logEntry->getDetails()) {
						// Only store when not empty
						$this->logEntryMapper->insert($logEntry);
					}
				}
			}
		} elseif ($data['type'] === 'Activity') {
			if ($data['object']['name'] === 'call_joined' || $data['object']['name'] === 'call_started') {
				$logEntry = new LogEntry();
				$logEntry->setServer($server);
				$logEntry->setToken($data['target']['id']);
				$logEntry->setType(LogEntry::TYPE_START);
				$logEntry->setDetails((string) $this->timeFactory->now()->getTimestamp());
				$this->logEntryMapper->insert($logEntry);

				$logEntry = new LogEntry();
				$logEntry->setServer($server);
				$logEntry->setToken($data['target']['id']);
				$logEntry->setType(LogEntry::TYPE_ATTENDEE);
				$logEntry->setDetails($data['actor']['name']);
				if ($logEntry->getDetails()) {
					// Only store when not empty
					$this->logEntryMapper->insert($logEntry);
				}
			} elseif ($data['object']['name'] === 'call_ended' || $data['object']['name'] === 'call_ended_everyone') {
				$summary = $this->summaryService->summarize($server, $data['target']['id'], $data['target']['name'], $lang);
				if ($summary !== null) {
					$body = [
						'message' => $summary,
						'referenceId' => sha1($random),
					];

					// Generate and post summary
					$this->sendResponse($server, $config, $body, $data);
				}
			}
		}
		return new DataResponse();
	}

	protected function sendResponse(string $server, array $config, array $body, array $data): void {
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		$random = bin2hex(random_bytes(32));
		$hash = hash_hmac('sha256', $random . $body['message'], $config['secret']);
		$this->logger->info('Reply: Random ' . $random);
		$this->logger->info('Reply: Hash ' . $hash);

		try {
			$options = [
				'headers' => [
					'OCS-APIRequest' => 'true',
					'Content-Type' => 'application/json',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $hash,
					'User-Agent' => 'nextcloud-call-summary-bot/1.0',
				],
				'body' => $jsonBody,
				'verify' => false, // FIXME
			];

			$client = $this->clientService->newClient();
			$response = $client->post(rtrim($server, '/') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $data['target']['id'] . '/message', $options);
			$this->logger->info('Response: ' . $response->getBody());
		} catch (\Exception $exception) {
			$this->logger->info(get_class($exception) . ': ' . $exception->getMessage());
		}
	}
}
