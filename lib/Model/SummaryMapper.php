<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @method Summary mapRowToEntity(array $row)
 * @method Summary findEntity(IQueryBuilder $query)
 * @method list<Summary> findEntities(IQueryBuilder $query)
 * @template-extends QBMapper<Summary>
 */
class SummaryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'csb_summaries', Summary::class);
	}

	/**
	 * Newest summaries first, optionally limited to calls started in [$from, $to)
	 *
	 * @return list<Summary>
	 */
	public function findByConversation(string $token, ?int $from = null, ?int $to = null, ?int $limit = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('token', $query->createNamedParameter($token)))
			->orderBy('start_time', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		$this->addTimeRange($query, $from, $to);
		return $this->findEntities($query);
	}

	public function countByConversation(string $token, ?int $from = null, ?int $to = null): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*'))
			->from($this->getTableName())
			->where($query->expr()->eq('token', $query->createNamedParameter($token)));
		$this->addTimeRange($query, $from, $to);
		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Remembers the ID of the chat message the summary was posted as
	 */
	public function setMessageIdByReferenceId(string $referenceId, int $messageId): void {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('message_id', $query->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->set('reference_id', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($query->expr()->eq('reference_id', $query->createNamedParameter($referenceId)));
		$query->executeStatement();
	}

	public function deleteByConversation(string $token): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('token', $query->createNamedParameter($token)));
		$query->executeStatement();
	}

	protected function addTimeRange(IQueryBuilder $query, ?int $from, ?int $to): void {
		if ($from !== null) {
			$query->andWhere($query->expr()->gte('start_time', $query->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
		}
		if ($to !== null) {
			$query->andWhere($query->expr()->lt('start_time', $query->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		}
	}
}
