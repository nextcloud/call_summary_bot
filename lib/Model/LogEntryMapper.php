<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @method LogEntry mapRowToEntity(array $row)
 * @method LogEntry findEntity(IQueryBuilder $query)
 * @method list<LogEntry> findEntities(IQueryBuilder $query)
 * @template-extends QBMapper<LogEntry>
 */
class LogEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'csb_log_entries', LogEntry::class);
	}

	/**
	 * @return LogEntry[]
	 */
	public function findByConversation(string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)));
		return $this->findEntities($query);
	}

	public function hasActiveCall(string $token): bool {
		$query = $this->db->getQueryBuilder();
		$query->select($query->expr()->literal(1))
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_ATTENDEE)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$hasAttendee = (bool)$result->fetchOne();
		$result->closeCursor();

		return $hasAttendee;
	}

	/**
	 * @psalm-return LogEntry::DETAIL_*|null
	 */
	public function getSetting(string $token, string $type): ?string {
		$query = $this->db->getQueryBuilder();
		$query->select('details')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter($type)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$setting = $result->fetchOne();
		$result->closeCursor();

		return $setting ?: null;
	}

	public function deleteSetting(string $token, string $setting): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter($setting)));
		$query->executeStatement();
	}

	public function deleteByConversation(string $token): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->neq('type', $query->createNamedParameter(LogEntry::TYPE_SETTING_IGNORE_SILENT)));
		$query->executeStatement();
	}
}
