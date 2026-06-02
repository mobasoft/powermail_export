<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Service;

use DateTimeInterface;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Mail;
use In2code\Powermail\Utility\BasicFileUtility;
use In2code\Powermail\Utility\StringUtility;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

class XlsxExportService
{
    protected const COLOR_NAVY = 'FF16324F';
    protected const COLOR_BLUE = 'FF2F75B5';
    protected const COLOR_BLUE_LIGHT = 'FFEAF2FB';
    protected const COLOR_BLUE_STRIPES = 'FFF7FAFD';
    protected const COLOR_TEXT = 'FF1F2933';
    protected const COLOR_MUTED = 'FF5F6B7A';

    protected ?QueryResultInterface $mails = null;
    protected array $receiverEmails = [];
    protected array $senderEmails = ['powermail@domain.org'];
    protected string $subject = '';
    protected array $fieldList = [];
    protected string $fileName = '';
    protected array $additionalProperties = [];
    protected bool $addAttachment = true;
    protected string $storageFolder = 'typo3temp/assets/tx_powermail/';
    protected string $emailTemplate = 'Module/ExportTaskMail.html';

    public function __construct(?QueryResultInterface $mails = null, array $additionalProperties = [])
    {
        $this->setMails($mails);
        $this->setAdditionalProperties($additionalProperties);
        $this->setFieldList($this->getDefaultFieldListFromFirstMail($mails));
        $this->createRandomFileName();
    }

    public function send(): bool
    {
        if (!$this->createExportFile()) {
            return false;
        }

        return $this->sendEmail();
    }

    protected function sendEmail(): bool
    {
        $email = GeneralUtility::makeInstance(MailMessage::class);
        $email->setTo($this->getReceiverEmails());
        $email->setFrom($this->getDefaultSenderAddress());
        $email->setSubject($this->getSubject());
        $email->html($this->createMailBody());
        if ($this->isAddAttachment()) {
            $email->attachFromPath($this->getAbsolutePathAndFileName());
        }
        $email->send();
        return $email->isSent();
    }

    protected function createMailBody(): string
    {
        $standaloneView = \In2code\Powermail\Utility\TemplateUtility::getDefaultStandAloneView();
        $standaloneView->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName($this->getEmailTemplate())
        );
        $standaloneView->assign('export', $this);
        $standaloneView->assign('mails', $this->getMails());
        return $standaloneView->render();
    }

    protected function createExportFile(): bool
    {
        BasicFileUtility::createFolderIfNotExists($this->getStorageFolder(true));
        $spreadsheet = $this->createSpreadsheet();
        $writer = new Xlsx($spreadsheet);
        $writer->save($this->getAbsolutePathAndFileName());
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return true;
    }

    protected function createSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Powermail Export')
            ->setLastModifiedBy('Powermail Export')
            ->setTitle('Powermail Export')
            ->setSubject($this->getSubject())
            ->setDescription('Powermail export data');

        $groupedMails = $this->groupMailsByForm();
        $this->createOverviewSheet($spreadsheet, $groupedMails);

        foreach ($groupedMails as $group) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->buildSheetTitle($group['formTitle'], (int)$group['formUid']));

            /** @var Mail|null $firstMail */
            $firstMail = $group['mails'][0] ?? null;
            $fieldList = $firstMail instanceof Mail ? $this->getFieldListForMail($firstMail) : [];
            $headers = $this->getHeaders($firstMail instanceof Mail ? $firstMail : null, $fieldList);
            $this->renderTitleBlock($sheet, count($headers), $group['formTitle'], (int)$group['formUid'], count($group['mails']));
            $sheet->fromArray($headers, null, 'A3', true);
            $this->styleHeaderRow($sheet, count($headers));

            $rowIndex = 4;
            foreach ($group['mails'] as $mail) {
                $row = $this->buildRow($mail, $fieldList);
                $sheet->fromArray($row, null, 'A' . $rowIndex, true);
                $this->styleDataRow($sheet, $rowIndex, count($row));
                ++$rowIndex;
            }

            $this->formatColumns($sheet, $headers, $this->getPreviewRows($group['mails']));
            $sheet->freezePane('A4');
            $sheet->setAutoFilter('A3:' . Coordinate::stringFromColumnIndex($this->getColumnCount($headers)) . '3');
        }

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    protected function createOverviewSheet(Spreadsheet $spreadsheet, array $groupedMails): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Overview');

        $headers = ['Form', 'Form UID', 'Records'];
        $sheet->fromArray($headers, null, 'A1', true);
        $this->styleHeaderRow($sheet, count($headers), 'A1');

        $rowIndex = 2;
        foreach ($groupedMails as $group) {
            $sheetName = $this->buildSheetTitle($group['formTitle'], (int)$group['formUid']);
            $sheet->setCellValue('A' . $rowIndex, (string)$group['formTitle']);
            $sheet->setCellValue('B' . $rowIndex, (string)$group['formUid']);
            $sheet->setCellValue('C' . $rowIndex, (int)$group['count']);
            $sheet->setCellValue(
                'D' . $rowIndex,
                '=HYPERLINK("#\'' . str_replace("'", "''", $sheetName) . '\'!A1","Open")'
            );
            $sheet->getStyle('D' . $rowIndex)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => self::COLOR_BLUE],
                    'underline' => 'single',
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                ],
            ]);
            $this->styleDataRow($sheet, $rowIndex, 4);
            ++$rowIndex;
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:D1');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(12);
    }

    protected function renderTitleBlock(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $columnCount,
        string $formTitle,
        int $formUid,
        int $recordCount
    ): void
    {
        $endColumn = Coordinate::stringFromColumnIndex(max(1, $columnCount));
        $sheet->mergeCells('A1:' . $endColumn . '1');
        $sheet->mergeCells('A2:' . $endColumn . '2');

        $sheet->setCellValue('A1', $formTitle !== '' ? $formTitle : 'Powermail Export');
        $sheet->setCellValue(
            'A2',
            'Form UID: ' . $formUid . ' | Generated ' . date('Y-m-d H:i') . ' | Records: ' . $recordCount
        );

        $sheet->getStyle('A1:' . $endColumn . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => self::COLOR_NAVY],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);

        $sheet->getStyle('A2:' . $endColumn . '2')->applyFromArray([
            'font' => [
                'italic' => true,
                'size' => 10,
                'color' => ['argb' => self::COLOR_MUTED],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => self::COLOR_BLUE_LIGHT],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(18);
    }

    protected function styleHeaderRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $columnCount,
        string $startCell = 'A3'
    ): void
    {
        $startColumn = preg_replace('/\d+$/', '', $startCell);
        $startRow = (int)preg_replace('/\D+/', '', $startCell);
        $range = $startColumn . $startRow . ':' . Coordinate::stringFromColumnIndex($columnCount) . $startRow;
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => self::COLOR_BLUE],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => self::COLOR_NAVY],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => self::COLOR_NAVY],
                ],
                'left' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFB8C7D9'],
                ],
                'right' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFB8C7D9'],
                ],
            ],
        ]);
        $sheet->getRowDimension($startRow)->setRowHeight(22);
    }

    protected function styleDataRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowIndex, int $columnCount): void
    {
        $range = 'A' . $rowIndex . ':' . Coordinate::stringFromColumnIndex($columnCount) . $rowIndex;
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'color' => ['argb' => self::COLOR_TEXT],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_HAIR,
                    'color' => ['argb' => 'FFD9E2EC'],
                ],
            ],
        ]);

        if (($rowIndex % 2) === 0) {
            $sheet->getStyle($range)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB(self::COLOR_BLUE_STRIPES);
        }
    }

    protected function formatColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $rows): void
    {
        foreach (array_keys($headers) as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $width = max(strlen((string)$header) + 4, 16);
            foreach ($rows as $row) {
                $value = (string)($row[$index] ?? '');
                $width = max($width, min(64, strlen($value) + 2));
            }
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    protected function getHeaders(?Mail $firstMail = null, array $fieldList = []): array
    {
        $headers = [];
        $fieldList = $fieldList !== [] ? $fieldList : $this->fieldList;
        foreach ($fieldList as $fieldListItem) {
            $headers[] = $this->resolveHeaderLabel((string)$fieldListItem, $firstMail);
        }
        return $headers;
    }

    protected function buildRow(Mail $mail, array $fieldList = []): array
    {
        $row = [];
        $fieldList = $fieldList !== [] ? $fieldList : $this->fieldList;
        foreach ($fieldList as $fieldListItem) {
            $row[] = $this->resolveValue($mail, (string)$fieldListItem);
        }
        return $row;
    }

    protected function getFieldListForMail(Mail $mail): array
    {
        $fieldList = [];
        $form = $mail->getForm();
        if (is_object($form) && method_exists($form, 'getFields')) {
            foreach ($form->getFields(Field::FIELD_TYPE_EXTPORTABLE) as $field) {
                if (method_exists($field, 'getUid')) {
                    $fieldList[] = $field->getUid();
                }
            }
        }

        return $fieldList !== [] ? $fieldList : $this->fieldList;
    }

    protected function resolveHeaderLabel(string $fieldListItem, ?Mail $firstMail = null): string
    {
        $labels = [
            'crdate' => 'Created',
            'sender_name' => 'Sender name',
            'sender_mail' => 'Sender email',
            'receiver_mail' => 'Receiver email',
            'subject' => 'Subject',
            'marketing_referer_domain' => 'Referer domain',
            'marketing_referer' => 'Referer',
            'marketing_frontend_language' => 'Frontend language',
            'marketing_browser_language' => 'Browser language',
            'marketing_country' => 'Country',
            'marketing_mobile_device' => 'Mobile device',
            'marketing_page_funnel' => 'Page funnel',
            'user_agent' => 'User agent',
            'time' => 'Submit time',
            'sender_ip' => 'Sender IP',
            'uid' => 'Record UID',
            'feuser' => 'Frontend user',
        ];

        if (isset($labels[$fieldListItem])) {
            return $labels[$fieldListItem];
        }

        if (ctype_digit($fieldListItem)) {
            if ($firstMail !== null) {
                $answers = $firstMail->getAnswersByFieldUid();
                $answer = $answers[(int)$fieldListItem] ?? null;
                if ($answer instanceof Answer && $answer->getField() !== null) {
                    $title = trim($answer->getField()->getTitle());
                    if ($title !== '') {
                        return $title;
                    }
                }
            }
            return 'Field #' . $fieldListItem;
        }

        return $fieldListItem;
    }

    protected function resolveValue(Mail $mail, string $fieldListItem): string
    {
        $answer = null;
        if (ctype_digit($fieldListItem)) {
            $answers = $mail->getAnswersByFieldUid();
            $answer = $answers[(int)$fieldListItem] ?? null;
            if ($answer instanceof Answer) {
                return $this->normalizeAnswerValue($answer);
            }
            return '';
        }

        return match ($fieldListItem) {
            'crdate' => $this->formatDateTime($mail->getCrdate()),
            'sender_name' => $mail->getSenderName(),
            'sender_mail' => $mail->getSenderMail(),
            'receiver_mail' => $mail->getReceiverMail(),
            'subject' => $mail->getSubject(),
            'marketing_referer_domain' => $mail->getMarketingRefererDomain(),
            'marketing_referer' => $mail->getMarketingReferer(),
            'marketing_frontend_language' => $this->resolveFrontendLanguageLabel((int)$mail->getMarketingFrontendLanguage()),
            'marketing_browser_language' => $mail->getMarketingBrowserLanguage(),
            'marketing_country' => $mail->getMarketingCountry(),
            'marketing_mobile_device' => $mail->getMarketingMobileDevice() ? 'Yes' : 'No',
            'marketing_page_funnel' => $this->normalizeArrayValue($mail->getMarketingPageFunnel()),
            'user_agent' => $mail->getUserAgent(),
            'time' => $this->formatDuration($mail->getTime()),
            'sender_ip' => $mail->getSenderIp(),
            'uid' => (string)$mail->getUid(),
            'feuser' => $this->resolveFeUserValue($mail),
            default => '',
        };
    }

    protected function normalizeAnswerValue(Answer $answer): string
    {
        $value = $answer->getValue();
        if (is_array($value)) {
            return $this->normalizeArrayValue($value);
        }

        return (string)$value;
    }

    protected function normalizeArrayValue(array $value): string
    {
        $flattened = [];
        array_walk_recursive($value, static function ($item) use (&$flattened): void {
            $flattened[] = (string)$item;
        });

        return implode(', ', array_filter($flattened, static fn(string $item): bool => $item !== ''));
    }

    protected function resolveFeUserValue(Mail $mail): string
    {
        $feuser = $mail->getFeuser();
        if (is_object($feuser) && method_exists($feuser, 'getUid')) {
            return (string)$feuser->getUid();
        }

        return '';
    }

    protected function formatDateTime(?DateTimeInterface $dateTime): string
    {
        if ($dateTime === null) {
            return '';
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    protected function resolveFrontendLanguageLabel(int $languageUid): string
    {
        if ($languageUid <= 0) {
            return 'Default';
        }

        return 'Language #' . $languageUid;
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $rest);
    }

    protected function getPreviewRows(?array $mails = null): array
    {
        $rows = [];
        $mails = $mails ?? $this->getMails()->toArray();
        foreach ($mails as $mail) {
            $rows[] = $this->buildRow($mail);
            if (count($rows) >= 5) {
                break;
            }
        }

        return $rows;
    }

    protected function getColumnCount(array $headers = []): int
    {
        return max(1, count($headers !== [] ? $headers : $this->fieldList));
    }

    protected function groupMailsByForm(): array
    {
        $groups = [];
        foreach ($this->getMails() as $mail) {
            $form = $mail->getForm();
            $formUid = 0;
            $formTitle = 'Unknown form';
            if (is_object($form)) {
                if (method_exists($form, 'getUid')) {
                    $formUid = (int)$form->getUid();
                }
                if (method_exists($form, 'getTitle')) {
                    $formTitle = trim((string)$form->getTitle());
                }
            }

            $key = $formUid . ':' . $formTitle;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'formUid' => $formUid,
                    'formTitle' => $formTitle,
                    'mails' => [],
                    'count' => 0,
                ];
            }
            $groups[$key]['mails'][] = $mail;
            $groups[$key]['count']++;
        }

        return array_values($groups);
    }

    protected function buildSheetTitle(string $formTitle, int $formUid): string
    {
        $title = trim($formTitle);
        if ($title === '') {
            $title = 'Form ' . $formUid;
        }
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?: 'Form ' . $formUid;
        $title = trim(preg_replace('/\\s+/', ' ', $title));
        $title .= ' ' . $formUid;

        return mb_substr($title, 0, 31);
    }

    protected function getDefaultFieldListFromFirstMail(?QueryResultInterface $mails = null): array
    {
        $fieldList = [];
        if ($mails !== null) {
            /** @var Mail $mail */
            $mail = $mails->getFirst();
            if ($mail !== null && $mail->getForm() !== null) {
                foreach ($mail->getForm()->getFields(Field::FIELD_TYPE_EXTPORTABLE) as $field) {
                    $fieldList[] = $field->getUid();
                }
            }
        }

        return $fieldList;
    }

    protected function createRandomFileName(): void
    {
        $this->fileName = StringUtility::getRandomString(55) . '.xlsx';
    }

    public function getMails(): QueryResultInterface
    {
        return $this->mails;
    }

    public function setMails(?QueryResultInterface $mails): self
    {
        $this->mails = $mails;
        return $this;
    }

    public function getReceiverEmails(): array
    {
        $mailArray = [];
        foreach ($this->receiverEmails as $email) {
            $mailArray[$email] = '';
        }
        return $mailArray;
    }

    public function setReceiverEmails($emails): self
    {
        if (is_string($emails)) {
            $emails = GeneralUtility::trimExplode(',', $emails, true);
        }
        $this->receiverEmails = $emails;
        return $this;
    }

    public function getSenderEmails(): array
    {
        $mailArray = [];
        foreach ($this->senderEmails as $email) {
            $mailArray[$email] = 'Sender';
        }
        return $mailArray;
    }

    public function setSenderEmails($senderEmails): self
    {
        if (is_string($senderEmails)) {
            $senderEmails = GeneralUtility::trimExplode(',', $senderEmails, true);
        }
        if ($senderEmails === [] || $senderEmails === '') {
            $senderEmails = $this->getDefaultSenderEmails();
        }
        $this->senderEmails = $senderEmails;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getFieldList(): array
    {
        return $this->fieldList;
    }

    public function setFieldList($fieldList): self
    {
        if (!empty($fieldList)) {
            if (is_string($fieldList)) {
                $fieldList = GeneralUtility::trimExplode(',', $fieldList, true);
            }
            $this->fieldList = $fieldList;
        }
        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName = null): self
    {
        if ($fileName) {
            $this->fileName = $fileName . '.xlsx';
        }
        return $this;
    }

    public function getRelativePathAndFileName(): string
    {
        return $this->getStorageFolder() . $this->getFileName();
    }

    public function getAbsolutePathAndFileName(): string
    {
        return GeneralUtility::getFileAbsFileName($this->getRelativePathAndFileName());
    }

    public function getAdditionalProperties(): array
    {
        return $this->additionalProperties;
    }

    public function setAdditionalProperties(array $additionalProperties): self
    {
        $this->additionalProperties = $additionalProperties;
        return $this;
    }

    public function isAddAttachment(): bool
    {
        return $this->addAttachment;
    }

    public function setAddAttachment(bool $addAttachment): self
    {
        $this->addAttachment = $addAttachment;
        return $this;
    }

    public function getStorageFolder(bool $absolute = false): string
    {
        $storageFolder = $this->storageFolder;
        if ($absolute) {
            $storageFolder = GeneralUtility::getFileAbsFileName($storageFolder);
        }
        return $storageFolder;
    }

    public function setStorageFolder(string $storageFolder): self
    {
        $this->storageFolder = $storageFolder;
        return $this;
    }

    public function getEmailTemplate(): string
    {
        return $this->emailTemplate;
    }

    public function setEmailTemplate(string $emailTemplate): self
    {
        if (!empty($emailTemplate)) {
            $this->emailTemplate = $emailTemplate;
        }
        return $this;
    }

    protected function getDefaultSenderAddress(): string
    {
        $mailConfig = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $senderAddress = (string)($mailConfig['defaultMailFromAddress'] ?? '');
        $senderName = (string)($mailConfig['defaultMailFromName'] ?? '');
        if ($senderAddress === '') {
            $senderAddress = 'powermail@domain.org';
        }

        if ($senderName !== '') {
            return sprintf('%s <%s>', $senderName, $senderAddress);
        }

        return $senderAddress;
    }
}
