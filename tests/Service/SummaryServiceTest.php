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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class SummaryServiceTest extends TestCase {
	protected $config;
	protected $mapper;
	protected $timeFactory;
	protected $dateFormatter;
	protected $l10nFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->mapper = $this->createMock(LogEntryMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->dateFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
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
		);
	}

	public function dataReadTasksFromMessage(): array {
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
				->willReturnCallback(function (string $server, string $token, string $text, string $type) use ($tasks, $types, &$i): void {
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

		self::assertEquals(!empty($tasks), $service->readTasksFromMessage($message, ['parameters' => []], 'server', ['target' => ['id' => 't0k3n']]));
	}

	public function dataGetTitle(): array {
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
			->willReturnCallback(fn($string, $args) => vsprintf($string, $args));

		$service = $this->getService();

		self::assertEquals($title, self::invokePrivate($service, 'getTitle', [$l, $roomName]));
	}
}
