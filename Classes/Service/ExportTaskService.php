<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Service;

use In2code\Powermail\Domain\Service\ExportService;
use Mobasoft\PowermailExport\Domain\Repository\MailRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidExtensionNameException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;

class ExportTaskService
{
    /**
     * @param array<int> $pageUids
     * @param array $filterVariables
     * @param array<string, mixed> $options
     * @return int
     * @throws InvalidConfigurationTypeException
     * @throws InvalidExtensionNameException
     * @throws InvalidQueryException
     */
    public function run(array $pageUids, array $filterVariables, array $options): int
    {
        /** @var MailRepository $mailRepository */
        $mailRepository = GeneralUtility::makeInstance(MailRepository::class);
        $pageUids = $mailRepository->resolvePageUids($pageUids, (bool)($options['recursive'] ?? false));
        if ($pageUids === []) {
            return 0;
        }

        $mails = $mailRepository->findAllInPids($pageUids, [], $filterVariables);
        if ($mails->count() === 0) {
            return 0;
        }

        $exportService = GeneralUtility::makeInstance(
            ExportService::class,
            $mails,
            (string)($options['format'] ?? 'xls'),
            ['domain' => $options['domain'] ?? 'https://domain.org/']
        );
        $exportService
            ->setReceiverEmails((string)($options['receiverEmails'] ?? ''))
            ->setSenderEmails((string)($options['senderEmail'] ?? 'sender@domain.org'))
            ->setSubject((string)($options['subject'] ?? ''))
            ->setFieldList($options['fieldList'] ?? '')
            ->setAddAttachment((bool)($options['attachment'] ?? true))
            ->setStorageFolder((string)($options['storageFolder'] ?? 'typo3temp/assets/tx_powermail/'))
            ->setFileName((string)($options['fileName'] ?? ''))
            ->setEmailTemplate((string)($options['emailTemplate'] ?? 'EXT:powermail/Resources/Private/Templates/Module/ExportTaskMail.html'));

        return $exportService->send() ? 0 : 1;
    }
}
