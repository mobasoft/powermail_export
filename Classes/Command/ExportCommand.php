<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Command;

use Mobasoft\PowermailExport\Domain\Repository\MailRepository;
use In2code\Powermail\Command\ExportCommand as BaseExportCommand;
use In2code\Powermail\Domain\Service\ExportService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidExtensionNameException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;

class ExportCommand extends BaseExportCommand
{
    public function configure(): void
    {
        parent::configure();
        $this->addOption(
            'page-uids',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma separated list of page UIDs to search in',
            ''
        );
        $this->addOption(
            'recursive',
            'r',
            InputOption::VALUE_NONE,
            'Also search recursively in all subpages of the given page UIDs'
        );
    }

    /**
     * @throws InvalidConfigurationTypeException
     * @throws InvalidExtensionNameException
     * @throws InvalidQueryException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageUids = $this->getPageUids($input);
        $recursive = (bool)$input->getOption('recursive');
        $resolvedPageUids = GeneralUtility::makeInstance(MailRepository::class)->resolvePageUids($pageUids, $recursive);

        if ($resolvedPageUids === []) {
            $output->writeln('No valid page UIDs provided');
            return self::FAILURE;
        }

        $mailRepository = GeneralUtility::makeInstance(MailRepository::class);
        $mails = $mailRepository->findAllInPids(
            $resolvedPageUids,
            [],
            $this->getFilterVariables((int)$input->getArgument('period'))
        );

        if ($mails->count() === 0) {
            $output->writeln('No mails found, export skipped');
            return self::SUCCESS;
        }

        $exportService = GeneralUtility::makeInstance(
            ExportService::class,
            $mails,
            (string)$input->getArgument('format'),
            ['domain' => $input->getArgument('domain')]
        );
        $exportService
            ->setReceiverEmails((string)$input->getArgument('receiverEmails'))
            ->setSenderEmails((string)$input->getArgument('senderEmail'))
            ->setSubject((string)$input->getArgument('subject'))
            ->setFieldList($input->getArgument('fieldList'))
            ->setAddAttachment((bool)$input->getArgument('attachment'))
            ->setStorageFolder((string)$input->getArgument('storageFolder'))
            ->setFileName((string)$input->getArgument('fileName'))
            ->setEmailTemplate((string)$input->getArgument('emailTemplate'));

        if ($exportService->send() === true) {
            $output->writeln('Export finished');
            return self::SUCCESS;
        }

        $output->writeln('Export could not be generated');
        return self::FAILURE;
    }

    /**
     * @param InputInterface $input
     * @return array<int>
     */
    protected function getPageUids(InputInterface $input): array
    {
        $pageUidArgument = trim((string)$input->getOption('page-uids'));
        if ($pageUidArgument === '') {
            $pageUidArgument = trim((string)$input->getArgument('pageUid'));
        }
        if ($pageUidArgument === '') {
            return [];
        }

        return GeneralUtility::intExplode(',', $pageUidArgument, true);
    }
}
