<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Service;

use In2code\Powermail\Domain\Service\ExportService;
use Mobasoft\PowermailExport\Domain\Repository\MailRepository;
use Mobasoft\PowermailExport\Service\XlsxExportService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidExtensionNameException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;

class ExportTaskService
{
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

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
        $this->logger->info('Powermail export started', [
            'pageUids' => $pageUids,
            'recursive' => (bool)($options['recursive'] ?? false),
            'period' => $filterVariables['filter']['start'] ?? null,
        ]);
        $pageUids = $mailRepository->resolvePageUids($pageUids, (bool)($options['recursive'] ?? false));
        if ($pageUids === []) {
            $this->logger->warning('Powermail export skipped because no page uids were resolved');
            return 0;
        }
        $this->logger->info('Powermail export resolved page uids', ['resolvedPageUids' => $pageUids]);

        $mails = $mailRepository->findAllInPids($pageUids, [], $filterVariables);
        $this->logger->info('Powermail export query finished', ['mailCount' => $mails->count()]);
        if ($mails->count() === 0) {
            $this->logger->warning('Powermail export skipped because no mails were found');
            return 0;
        }

        $format = (string)($options['format'] ?? 'xls');
        $this->logger->info('Powermail export selected format', ['format' => $format]);
        if ($format === 'xlsx') {
            $exportService = GeneralUtility::makeInstance(
                XlsxExportService::class,
                $mails,
                ['domain' => $options['domain'] ?? 'https://domain.org/']
            );
        } else {
            $exportService = GeneralUtility::makeInstance(
                ExportService::class,
                $mails,
                $format,
                ['domain' => $options['domain'] ?? 'https://domain.org/']
            );
        }
        $exportService
            ->setReceiverEmails((string)($options['receiverEmails'] ?? ''))
            ->setSenderEmails((string)($options['senderEmail'] ?? 'sender@domain.org'))
            ->setSubject((string)($options['subject'] ?? ''))
            ->setFieldList($options['fieldList'] ?? '')
            ->setAddAttachment((bool)($options['attachment'] ?? true))
            ->setStorageFolder((string)($options['storageFolder'] ?? 'typo3temp/assets/tx_powermail/'))
            ->setFileName((string)($options['fileName'] ?? ''))
            ->setEmailTemplate((string)($options['emailTemplate'] ?? 'EXT:powermail/Resources/Private/Templates/Module/ExportTaskMail.html'));

        $sent = $exportService->send();
        $this->logger->info('Powermail export mail send result', ['sent' => $sent]);
        return $sent ? 0 : 1;
    }
}
