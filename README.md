# powermail_export

TYPO3-12-Extension fuer den Powermail-Export.

Enthaelt:
- eigenes E-Mail-Template fuer `powermail:export`
- eigenes Static TypoScript (inkl. Konstante fuer den Template-Pfad)
- erweitert `powermail:export` um mehrere `pageUid`-Werte und rekursive Suche
- verhindert Export-Mails mit leerem Anhang, wenn keine Datensaetze gefunden wurden
- kann bei `format=xlsx` eine echte, formatierte Excel-Datei erzeugen

## Template

`EXT:powermail_export/Resources/Private/Templates/Module/ExportTaskMail.html`

## Scheduler / CLI

Beim Command `powermail:export` den letzten Parameter (`emailTemplate`) auf das Template dieser Extension setzen:

`EXT:powermail_export/Resources/Private/Templates/Module/ExportTaskMail.html`

Wichtig: Es gibt keinen neuen Command-Namen. Diese Extension ersetzt nur die Implementierung hinter `powermail:export`.

Der Export-Command dieser Extension erweitert den Powermail-Export um:

- mehrere `pageUid`-Werte per `--page-uids=12,34,56`
- rekursive Suche mit `--recursive`
- Abbruch ohne Mailversand, wenn keine Datensaetze gefunden wurden
- optionalen XLSX-Export mit besserer Darstellung

### Seiten-Auswahl

- Alternativ zum bisherigen positionalen `pageUid`-Argument kann `--page-uids=12,34,56` genutzt werden
- Mehrere Seiten-IDs koennen als kommagetrennte Liste im bisherigen `pageUid`-Argument uebergeben werden, zum Beispiel `12,34,56`
- Mit `--recursive` werden die jeweiligen Unterseiten mit durchsucht
- Wenn keine Datensaetze gefunden werden, wird keine E-Mail mit leerem XLS-Anhang verschickt
- Fuer Tests kann `period` auf `0` gesetzt werden; dann wird kein Zeitfilter angewendet und es werden alle passenden Mails beruecksichtigt

### XLSX-Export

Wenn der dritte Parameter `format` auf `xlsx` gesetzt wird, erzeugt der Command eine echte Excel-Arbeitsmappe statt des klassischen HTML-basierten XLS-Exports.

Beispiel:

```bash
vendor/bin/typo3 powermail:export export@domain.org no-reply@domain.org "Powermail Export" xlsx --page-uids=12,34,56 --recursive
```

Die XLSX-Datei ist dabei bewusst etwas aufbereitet:

- farbige, fette Kopfzeile
- wechselnde Zeilenhintergruende
- eingefrorene Kopfzeile
- automatische Filter
- angepasste Spaltenbreiten

Beispiele:

```bash
vendor/bin/typo3 powermail:export export@domain.org  no-reply@domain.org "Powermail Export" --page-uids=12,34,56
vendor/bin/typo3 powermail:export export@domain.org  no-reply@domain.org "Powermail Export" --page-uids=12,34,56 --recursive
vendor/bin/typo3 powermail:export export@domain.org  no-reply@domain.org "Powermail Export" 12,34,56
vendor/bin/typo3 powermail:export export@domain.org  no-reply@domain.org "Powermail Export" --page-uids=12,34,56 0
vendor/bin/typo3 powermail:export export@domain.org  no-reply@domain.org "Powermail Export" xlsx --page-uids=12,34,56 --recursive
```

Wenn du den Command in einer Scheduler-Task nutzt, kann das Felder-Setup weiterhin wie bisher ueber die vorhandenen Powermail-Argumente gepflegt werden. Nur die Seitenauswahl und der Export-Flow wurden erweitert.

### Service-Override

In [`Configuration/Services.yaml`](/Users/sscheibe/Sites/ddgv12/packages/powermail_export/Configuration/Services.yaml) wird der originale Powermail-Service `In2code\Powermail\Command\ExportCommand` durch `Mobasoft\PowermailExport\Command\ExportCommand` ersetzt. Deshalb bleibt der aufgerufene Command unveraendert:

```bash
vendor/bin/typo3 powermail:export ...
```
