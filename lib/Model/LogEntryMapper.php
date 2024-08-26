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

namespace OCA\CallSummaryBot\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @method LogEntry mapRowToEntity(array $row)
 * @method LogEntry findEntity(IQueryBuilder $query)
 * @method LogEntry[] findEntities(IQueryBuilder $query)
 * @template-extends QBMapper<LogEntry>
 */
class LogEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'csb_log_entries', LogEntry::class);
	}

	/**
	 * @return LogEntry[]
	 */
	public function findByConversation(string $server, string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter($server)))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)));
		return $this->findEntities($query);
	}

	public function hasActiveCall(string $server, string $token): bool {
		$query = $this->db->getQueryBuilder();
		$query->select($query->expr()->literal(1))
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter($server)))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_ATTENDEE)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$hasAttendee = (bool)$result->fetchOne();
		$result->closeCursor();

		return $hasAttendee;
	}

	public function deleteByConversation(string $server, string $token): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter($server)))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)));
		$query->executeStatement();
	}
}
