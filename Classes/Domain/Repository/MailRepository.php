<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Domain\Repository;

use In2code\Powermail\Database\QueryGenerator;
use In2code\Powermail\Domain\Repository\MailRepository as BaseMailRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

class MailRepository extends BaseMailRepository
{
    /**
     * Find all mails in multiple PIDs.
     *
     * @param array<int> $pids
     * @param array $settings
     * @param array $piVars
     * @return QueryResultInterface
     * @throws InvalidQueryException
     */
    public function findAllInPids(array $pids, array $settings = [], array $piVars = []): QueryResultInterface
    {
        $pids = array_values(array_filter(array_map('intval', $pids)));
        if ($pids === []) {
            $query = $this->createQuery();
            $query->matching($query->equals('uid', 0));
            return $query->execute();
        }

        $query = $this->createQuery();
        $query->getQuerySettings()->setIgnoreEnableFields(true);

        $and = [
            $query->equals('deleted', 0),
            $query->in('pid', $pids),
        ];

        if (isset($piVars['filter'])) {
            foreach ((array)$piVars['filter'] as $field => $value) {
                if (!is_array($value)) {
                    if ($field === 'all' && !empty($value)) {
                        $or = [
                            $query->like('sender_name', '%' . $value . '%'),
                            $query->like('sender_mail', '%' . $value . '%'),
                            $query->like('subject', '%' . $value . '%'),
                            $query->like('receiver_mail', '%' . $value . '%'),
                            $query->like('sender_ip', '%' . $value . '%'),
                            $query->like('answers.value', '%' . $value . '%'),
                        ];
                        $and[] = $query->logicalOr(...$or);
                    } elseif ($field === 'form' && !empty($value)) {
                        $and[] = $query->equals('form', $value);
                    } elseif ($field === 'start' && !empty($value)) {
                        $and[] = $query->greaterThan('crdate', strtotime($value));
                    } elseif ($field === 'stop' && !empty($value)) {
                        $and[] = $query->lessThan('crdate', strtotime($value));
                    } elseif ($field === 'hidden' && !empty($value)) {
                        $and[] = $query->equals($field, ($value - 1));
                    } elseif (!empty($value)) {
                        $and[] = $query->like($field, '%' . $value . '%');
                    }
                }

                if (is_array($value)) {
                    foreach ((array)$value as $answerField => $answerValue) {
                        if (!empty($answerValue) && $answerField !== 'crdate') {
                            $and[] = $query->equals('answers.field', $answerField);
                            $and[] = $query->like('answers.value', '%' . $answerValue . '%');
                        }
                    }
                }
            }
        }

        $query->matching($query->logicalAnd(...$and));
        $query->setOrderings(
            [
                'crdate' => QueryInterface::ORDER_DESCENDING,
            ]
        );

        $mails = $query->execute();
        return $this->makeUniqueQuery($mails, $query);
    }

    /**
     * Expand one or more page uids to their page trees.
     *
     * @param array<int> $pageUids
     * @param bool $recursive
     * @return array<int>
     */
    public function resolvePageUids(array $pageUids, bool $recursive): array
    {
        $pageUids = array_values(array_filter(array_map('intval', $pageUids)));
        if ($pageUids === []) {
            return [];
        }

        if ($recursive === false) {
            return array_values(array_unique($pageUids));
        }

        $resolvedPageUids = [];
        $queryGenerator = GeneralUtility::makeInstance(QueryGenerator::class);
        foreach ($pageUids as $pageUid) {
            $treeList = $queryGenerator->getTreeList($pageUid, 99, 0);
            foreach (GeneralUtility::intExplode(',', (string)$treeList, true) as $resolvedPageUid) {
                $resolvedPageUids[] = $resolvedPageUid;
            }
        }

        return array_values(array_unique($resolvedPageUids));
    }
}
