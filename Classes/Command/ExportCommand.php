<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Command;

use In2code\Powermail\Command\ExportCommand as BaseExportCommand;
use Mobasoft\PowermailExport\Service\ExportTaskService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExportCommand extends BaseExportCommand
{
    public function configure(): void
    {
        parent::configure();
        $this->setHelp(
            'Use --page-uids=12,34,56 to export multiple storage pages at once. '
            . 'Add --recursive to include subpages. '
            . 'Use format=xlsx for a styled Excel workbook.'
        );
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

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageUids = $this->getPageUids($input);
        $exitCode = GeneralUtility::makeInstance(ExportTaskService::class)->run(
            $pageUids,
            $this->getFilterVariables((int)$input->getArgument('period')),
            [
                'recursive' => (bool)$input->getOption('recursive'),
                'receiverEmails' => $input->getArgument('receiverEmails'),
                'senderEmail' => $input->getArgument('senderEmail'),
                'subject' => $input->getArgument('subject'),
                'format' => $input->getArgument('format'),
                'domain' => $input->getArgument('domain'),
                'fieldList' => $input->getArgument('fieldList'),
                'attachment' => $input->getArgument('attachment'),
                'storageFolder' => $input->getArgument('storageFolder'),
                'fileName' => $input->getArgument('fileName'),
                'emailTemplate' => $input->getArgument('emailTemplate'),
            ]
        );

        if ($exitCode === 0) {
            $output->writeln('Export finished or skipped because no mails were found');
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
