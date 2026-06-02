<?php

declare(strict_types=1);

namespace Mobasoft\PowermailExport\Service;

use In2code\Powermail\Domain\Service\ExportService;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Typo3DefaultExportService extends ExportService
{
    protected function sendEmail(): bool
    {
        $email = GeneralUtility::makeInstance(MailMessage::class);
        $email->setTo($this->getReceiverEmails());
        $email->setFrom($this->getDefaultSenderEmails());
        $email->setSubject($this->getSubject());
        $email->html($this->createMailBody());
        if ($this->isAddAttachment()) {
            $email->attachFromPath($this->getAbsolutePathAndFileName());
        }
        $email->send();
        return $email->isSent();
    }

    protected function getDefaultSenderEmails(): array
    {
        $mailConfig = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $senderAddress = (string)($mailConfig['defaultMailFromAddress'] ?? '');
        $senderName = (string)($mailConfig['defaultMailFromName'] ?? '');
        if ($senderAddress === '') {
            $senderAddress = 'powermail@domain.org';
        }

        return [$senderAddress => $senderName !== '' ? $senderName : 'Sender'];
    }
}
