# powermail_export

TYPO3-12-Extension fuer den Powermail-Export.

Enthaelt:
- eigenes E-Mail-Template fuer `powermail:export`
- eigenes Static TypoScript (inkl. Konstante fuer den Template-Pfad)

## Template

`EXT:powermail_export/Resources/Private/Templates/Module/ExportTaskMail.html`

## Scheduler / CLI

Beim Command `powermail:export` den letzten Parameter (`emailTemplate`) auf das Template dieser Extension setzen:

`EXT:powermail_export/Resources/Private/Templates/Module/ExportTaskMail.html`
