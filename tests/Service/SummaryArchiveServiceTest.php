<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Tests\Service;

use OCA\CallSummaryBot\Model\Summary;
use OCA\CallSummaryBot\Model\SummaryMapper;
use OCA\CallSummaryBot\Service\SummaryArchiveService;
use OCA\CallSummaryBot\Service\SummaryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\NotFoundException;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SummaryArchiveServiceTest extends TestCase {
	protected SummaryMapper&MockObject $summaryMapper;
	protected SummaryService&MockObject $summaryService;
	protected IDateTimeFormatter&MockObject $dateTimeFormatter;
	protected IFactory&MockObject $l10nFactory;
	protected IURLGenerator&MockObject $urlGenerator;
	protected IRootFolder&MockObject $rootFolder;
	protected INotificationManager&MockObject $notificationManager;
	protected ITimeFactory&MockObject $timeFactory;
	protected LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->summaryMapper = $this->createMock(SummaryMapper::class);
		$this->summaryService = $this->createMock(SummaryService::class);
		$this->dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')
			->willReturnArgument(0);
		$l->method('n')
			->willReturnCallback(static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural));
		$this->l10nFactory->method('get')
			->willReturn($l);
	}

	/**
	 * @param string[] $methods
	 * @return SummaryArchiveService|MockObject
	 */
	protected function getService(array $methods = []) {
		$arguments = [
			$this->summaryMapper,
			$this->summaryService,
			$this->dateTimeFormatter,
			$this->l10nFactory,
			$this->urlGenerator,
			$this->rootFolder,
			$this->notificationManager,
			$this->timeFactory,
			$this->logger,
		];

		if (!empty($methods)) {
			return $this->getMockBuilder(SummaryArchiveService::class)
				->setConstructorArgs($arguments)
				->onlyMethods($methods)
				->getMock();
		}

		return new SummaryArchiveService(...$arguments);
	}

	/**
	 * IRootFolder::getUserFolder() returns an IUserFolder since Nextcloud 36
	 */
	protected function createUserFolderMock(): Folder&MockObject {
		return $this->createMock(interface_exists(IUserFolder::class) ? IUserFolder::class : Folder::class);
	}

	protected function createSummary(int $start, int $end, ?int $messageId, int $attendees, string $text = ''): Summary {
		$summary = new Summary();
		$summary->setStartTime($start);
		$summary->setEndTime($end);
		$summary->setMessageId($messageId);
		$summary->setAttendeeCount($attendees);
		$summary->setSummary($text);
		return $summary;
	}

	public static function dataIsCommand(): array {
		return [
			['!summaries', true],
			['  !summaries  ', true],
			['!summaries export 2025-03', true],
			['!SUMMARIES', true],
			['!summariesfoo', false],
			['!agenda', false],
			['please post !summaries', false],
		];
	}

	/**
	 * @dataProvider dataIsCommand
	 */
	public function testIsCommand(string $message, bool $expected): void {
		self::assertSame($expected, $this->getService()->isCommand($message));
	}

	public static function dataHandleCommand(): array {
		return [
			['!summaries', 'list', null],
			['!summaries 2025', 'list', '2025'],
			['!summaries 2025-03', 'list', '2025-03'],
			['!summaries export', 'export', null],
			['!summaries Export 2024-12', 'export', '2024-12'],
			['!summaries 2025-13', 'usage', null],
			['!summaries 2025-3', 'usage', null],
			['!summaries help', 'usage', null],
			['!summaries export foo', 'usage', null],
		];
	}

	/**
	 * @dataProvider dataHandleCommand
	 */
	public function testHandleCommand(string $message, string $expected, ?string $label): void {
		$service = $this->getService(['list', 'export']);

		$periodMatcher = $this->callback(static fn (?array $period): bool => $label === null ? $period === null : $period['label'] === $label);
		$service->expects($expected === 'list' ? $this->once() : $this->never())
			->method('list')
			->with('t0k3n', 'en', $periodMatcher)
			->willReturn('list');
		$service->expects($expected === 'export' ? $this->once() : $this->never())
			->method('export')
			->with('t0k3n', 'Room', 'users/alice', 'en', $periodMatcher)
			->willReturn('export');

		$answer = $service->handleCommand($message, 't0k3n', 'Room', 'users/alice', 'en');
		if ($expected === 'usage') {
			self::assertStringContainsString('!summaries export', $answer);
		} else {
			self::assertSame($expected, $answer);
		}
	}

	public function testGetPeriod(): void {
		$this->summaryService->method('getTimezone')
			->willReturn(new \DateTimeZone('Europe/Berlin'));

		$service = $this->getService();
		self::assertSame([
			'from' => 1740783600, // 2025-03-01 00:00 CET
			'to' => 1743458400, // 2025-04-01 00:00 CEST
			'label' => '2025-03',
		], self::invokePrivate($service, 'getPeriod', [2025, 3]));
		self::assertSame([
			'from' => 1735686000, // 2025-01-01 00:00 CET
			'to' => 1767222000, // 2026-01-01 00:00 CET
			'label' => '2025',
		], self::invokePrivate($service, 'getPeriod', [2025, null]));
	}

	public function testList(): void {
		$this->summaryMapper->expects($this->once())
			->method('findByConversation')
			->with('t0k3n', null, null, SummaryArchiveService::LIST_LIMIT)
			->willReturn([
				$this->createSummary(2000, 2600, 42, 3),
				$this->createSummary(1000, 1600, null, 1),
			]);
		$this->summaryMapper->method('countByConversation')
			->willReturn(12);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('spreed.Page.showCall', ['token' => 't0k3n'])
			->willReturn('https://cloud/call/t0k3n');
		$this->dateTimeFormatter->method('formatDate')
			->willReturnCallback(static fn (int $timestamp): string => 'date' . $timestamp);
		$this->dateTimeFormatter->method('formatTime')
			->willReturnCallback(static fn (int $timestamp): string => 'time' . $timestamp);

		$answer = $this->getService()->list('t0k3n', 'en');
		self::assertStringContainsString("- [date2000 · time2000 – time2600](https://cloud/call/t0k3n#message_42) · 3 attendees\n", $answer);
		self::assertStringContainsString("- date1000 · time1000 – time1600 · 1 attendee\n", $answer);
		self::assertStringContainsString('Showing the latest 2 of 12 summaries.', $answer);
	}

	public function testListEmpty(): void {
		$this->summaryMapper->method('findByConversation')
			->willReturn([]);
		$this->summaryMapper->expects($this->never())
			->method('countByConversation');

		self::assertSame('*No call summaries found*', $this->getService()->list('t0k3n', 'en'));
	}

	public function testExportRequiresUser(): void {
		$this->summaryMapper->expects($this->never())
			->method('findByConversation');
		$this->rootFolder->expects($this->never())
			->method('getUserFolder');

		self::assertSame(
			'*Only users of this server can export call summaries*',
			$this->getService()->export('t0k3n', 'Room', 'guests/abc', 'en')
		);
	}

	public function testExport(): void {
		$this->summaryMapper->method('findByConversation')
			->with('t0k3n', 1740783600, 1743458400, null)
			->willReturn([
				$this->createSummary(2000, 2600, 42, 1, "# Call summary - Room/Weekly\n\nMonday · 10:00 – 11:00 (UTC)\n\n## Attendees (1)\n- Alice\n"),
			]);
		$this->summaryService->method('getTitle')
			->willReturn('Call summary - Room/Weekly');
		$this->summaryService->method('isOneToOne')
			->willReturn(false);

		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn(1337);

		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')
			->with('Room-Weekly 2025-03 - 2026-10-31 12-34-56.md')
			->willReturn('Room-Weekly 2025-03 - 2026-10-31 12-34-56 (2).md');
		$folder->expects($this->once())
			->method('newFile')
			->with('Room-Weekly 2025-03 - 2026-10-31 12-34-56 (2).md', "# Call summary - Room/Weekly (2025-03)\n\n## Monday · 10:00 – 11:00 (UTC)\n\n### Attendees (1)\n- Alice\n\n---\n")
			->willReturn($file);

		$userFolder = $this->createUserFolderMock();
		$userFolder->method('get')
			->with('Call summaries')
			->willThrowException(new NotFoundException());
		$userFolder->expects($this->once())
			->method('newFolder')
			->with('Call summaries')
			->willReturn($folder);

		$this->rootFolder->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')
			->with('call_summary_bot')
			->willReturnSelf();
		$notification->method('setDateTime')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setUser')
			->with('alice')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setObject')
			->with('export', '1337')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with('exported', ['count' => 1, 'roomName' => 'Room/Weekly'])
			->willReturnSelf();
		$this->notificationManager->method('createNotification')
			->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);
		$this->summaryService->method('getTimezone')
			->willReturn(new \DateTimeZone('Europe/Berlin'));
		$this->timeFactory->method('getDateTime')
			->with('now', new \DateTimeZone('Europe/Berlin'))
			->willReturn(new \DateTime('2026-10-31 12:34:56', new \DateTimeZone('Europe/Berlin')));

		self::assertNull(
			$this->getService()->export('t0k3n', 'Room/Weekly', 'users/alice', 'en', [
				'from' => 1740783600,
				'to' => 1743458400,
				'label' => '2025-03',
			])
		);
	}
}
