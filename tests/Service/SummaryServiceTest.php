<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Tests\Service;

use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCA\CallSummaryBot\Service\SummaryService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class SummaryServiceTest extends TestCase {
	protected IConfig&MockObject $config;
	protected LogEntryMapper&MockObject $mapper;
	protected ITimeFactory&MockObject $timeFactory;
	protected IDateTimeFormatter&MockObject $dateFormatter;
	protected IFactory&MockObject $l10nFactory;
	protected IAppManager&MockObject $appManager;
	protected IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->mapper = $this->createMock(LogEntryMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->dateFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}

	/**
	 * @param string[] $methods
	 * @return SummaryService|MockObject
	 */
	protected function getService(array $methods = []) {
		if (!empty($methods)) {
			return $this->getMockBuilder(SummaryService::class)
				->setConstructorArgs([
					$this->config,
					$this->mapper,
					$this->timeFactory,
					$this->dateFormatter,
					$this->l10nFactory,
					$this->appManager,
					$this->appConfig,
				])
				->onlyMethods($methods)
				->getMock();
		}

		return new SummaryService(
			$this->config,
			$this->mapper,
			$this->timeFactory,
			$this->dateFormatter,
			$this->l10nFactory,
			$this->appManager,
			$this->appConfig,
		);
	}

	public static function dataReadTasksFromMessage(): array {
		return [
			[
				'hi',
				[],
				[],
			],
			[
				'- [ ] task1' . "\n" . '- [ ] ' . "\n" . '- [x]' . "\t",
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'- [ ] task1' . "\n" . '- [ ] task2',
				['task1', 'task2'],
				[LogEntry::TYPE_TODO, LogEntry::TYPE_TODO],
			],
			[
				'- [ ] task1' . "\n" . '- [x] task2',
				['task1', 'task2'],
				[LogEntry::TYPE_TODO, LogEntry::TYPE_SOLVED],
			],
			[
				'- [x] task1' . "\n" . '- [ ] task2',
				['task1', 'task2'],
				[LogEntry::TYPE_SOLVED, LogEntry::TYPE_TODO],
			],
			[
				'- [ ] task1',
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'* task: task1',
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'TODOs: task1',
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'to-do: task1',
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'- to do : task1',
				['task1'],
				[LogEntry::TYPE_TODO],
			],
			[
				'- to do : task1' . "\n" . '* task: task2' . "\n" . '* [x] task3' . "\n" . '* report: report1' . "\n" . '- note: note1' . "\n" . '- decision: decision1',
				['task1', 'task2', 'task3', 'report1', 'note1', 'decision1'],
				[LogEntry::TYPE_TODO, LogEntry::TYPE_TODO, LogEntry::TYPE_SOLVED, LogEntry::TYPE_REPORT, LogEntry::TYPE_NOTE, LogEntry::TYPE_DECISION],
			],
		];
	}

	/**
	 * @dataProvider dataReadTasksFromMessage
	 */
	public function testReadTasksFromMessage(string $message, array $tasks, array $types): void {
		$service = $this->getService(['saveTask']);

		if (!empty($tasks)) {
			$i = 0;
			$service->method('saveTask')
				->willReturnCallback(function (string $token, string $text, string $type) use ($tasks, $types, &$i): void {
					if (!isset($tasks[$i])) {
						$this->fail($type . '/' . $text . ' not found in Array' . print_r($tasks, true));
					}
					$this->assertEquals($tasks[$i], $text);
					$this->assertEquals($types[$i], $type);
					$i++;
				});
		} else {
			$service->expects($this->never())
				->method('saveTask');
		}

		self::assertEquals(!empty($tasks), $service->readTasksFromMessage($message, ['parameters' => []], ['target' => ['id' => 't0k3n']], false, 'en'));
	}

	public static function dataGetTitle(): array {
		return [
			// Default cases
			[
				'hi',
				'Call summary - hi',
			],
			[
				'"hi"',
				'Call summary - "hi"',
			],
			[
				'0',
				'Call summary - 0',
			],
			[
				json_encode([1, '2']),
				'Call summary - ' . json_encode([1, '2']),
			],
			[
				// Not only 2 strings
				json_encode(['2991c735-4f9e-46e2-a107-7569dd19fdf8', '42e6a9c2-a833-43f6-ab47-6b7004094912', '0964cbe6-598b-4543-bc90-a790131bc768']),
				'Call summary - ' . json_encode(['2991c735-4f9e-46e2-a107-7569dd19fdf8', '42e6a9c2-a833-43f6-ab47-6b7004094912', '0964cbe6-598b-4543-bc90-a790131bc768']),
			],

			// Corrected case
			[
				json_encode(['2991c735-4f9e-46e2-a107-7569dd19fdf8', '42e6a9c2-a833-43f6-ab47-6b7004094912']),
				'Call summary',
			],
		];
	}

	/**
	 * @dataProvider dataGetTitle
	 */
	public function testGetTitle(string $roomName, string $title): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')
			->willReturnCallback(fn ($string, $args) => vsprintf($string, $args));

		$service = $this->getService();

		self::assertEquals($title, self::invokePrivate($service, 'getTitle', [$l, $roomName]));
	}

	protected function createLogEntry(int $id, string $type, string $details): LogEntry {
		$logEntry = new LogEntry();
		$logEntry->setId($id);
		$logEntry->setType($type);
		$logEntry->setDetails($details);
		return $logEntry;
	}

	public static function dataUpdateAgendaFromMessage(): array {
		$agenda = '# Agenda' . "\n\n";
		return [
			'nothing ticked' => [
				$agenda . '- [ ] Item 1' . "\n" . '- [ ] Item 2' . "\n",
				[],
				[],
			],
			'one ticked' => [
				$agenda . '- [x] Item 1' . "\n" . '- [ ] Item 2' . "\n",
				[1],
				[],
			],
			'upper case and asterisk' => [
				$agenda . '* [X] Item 1' . "\n" . '- [x] Item 2',
				[1, 2],
				[],
			],
			'unticked again' => [
				$agenda . '- [ ] Item 1' . "\n" . '- [ ] Item 2' . "\n" . '- [ ] Item 3' . "\n",
				[],
				[3],
			],
			'prefix of other item is not matched' => [
				$agenda . '- [x] Item 1 extended' . "\n" . '- [ ] Item 2' . "\n",
				[],
				[],
			],
			'multi-line item' => [
				$agenda . '- [x] Item 1' . "\n" . '- [x] Item 4' . "\n" . 'second line' . "\n",
				[1, 4],
				[],
			],
		];
	}

	/**
	 * @dataProvider dataUpdateAgendaFromMessage
	 */
	public function testUpdateAgendaFromMessage(string $message, array $done, array $open): void {
		$this->mapper->method('findByConversation')
			->with('t0k3n')
			->willReturn([
				$this->createLogEntry(1, LogEntry::TYPE_AGENDA_POSTED, 'Item 1'),
				$this->createLogEntry(2, LogEntry::TYPE_AGENDA_POSTED, 'Item 2'),
				$this->createLogEntry(3, LogEntry::TYPE_AGENDA_DONE, 'Item 3'),
				$this->createLogEntry(4, LogEntry::TYPE_AGENDA_POSTED, 'Item 4' . "\n" . 'second line'),
				$this->createLogEntry(5, LogEntry::TYPE_AGENDA, 'Item 5'),
				$this->createLogEntry(6, LogEntry::TYPE_TODO, 'Item 6'),
			]);

		$this->mapper->expects($this->exactly(2))
			->method('changeTypeOfEntries')
			->willReturnCallback(function (array $ids, string $type) use ($done, $open): void {
				if ($type === LogEntry::TYPE_AGENDA_DONE) {
					self::assertEquals($done, $ids);
				} else {
					self::assertEquals(LogEntry::TYPE_AGENDA_POSTED, $type);
					self::assertEquals($open, $ids);
				}
			});

		$this->getService()->updateAgendaFromMessage('t0k3n', $message);
	}

	public function testAgendaMarksItemsAsPosted(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')
			->willReturnArgument(0);
		$this->l10nFactory->method('get')
			->willReturn($l);

		$this->mapper->method('findByConversation')
			->willReturn([
				$this->createLogEntry(1, LogEntry::TYPE_AGENDA, 'Item 1'),
				$this->createLogEntry(2, LogEntry::TYPE_AGENDA, 'Item 2'),
			]);

		$changes = [];
		$this->mapper->expects($this->exactly(2))
			->method('changeType')
			->willReturnCallback(function (string $token, string $from, string $to) use (&$changes): void {
				$changes[] = [$from, $to];
			});

		self::assertEquals(
			'# Agenda' . "\n\n" . '- [ ] Item 1' . "\n" . '- [ ] Item 2' . "\n",
			$this->getService()->agenda('t0k3n')
		);
		self::assertEquals([
			[LogEntry::TYPE_AGENDA_POSTED, LogEntry::TYPE_AGENDA],
			[LogEntry::TYPE_AGENDA, LogEntry::TYPE_AGENDA_POSTED],
		], $changes);
	}

	public function testAgendaPreviewKeepsAgenda(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')
			->willReturnArgument(0);
		$this->l10nFactory->method('get')
			->willReturn($l);

		$this->mapper->method('findByConversation')
			->willReturn([
				$this->createLogEntry(1, LogEntry::TYPE_AGENDA, 'Item 1'),
			]);
		$this->mapper->expects($this->never())
			->method('changeType');
		$this->mapper->expects($this->once())
			->method('deleteByConversation')
			->with('t0k3n');

		self::assertEquals(
			'# Agenda' . "\n\n" . '- [ ] Item 1' . "\n",
			$this->getService()->agenda('t0k3n', 'en', true)
		);
	}
}
