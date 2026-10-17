# E-Mail-Adressbasierter, reversibel zuordenbarer Gesprächsverlauf

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-10-17 | dermatthes | §§ 1–9: Breaking-Change-Entwurf für getrennte Nachrichten- und Kontaktzuordnung angelegt |

## § 1 Ziel und Entscheidung

Empfangene und gesendete E-Mails sollen zuerst dauerhaft und unabhängig von
einem Kontakt über ihre beteiligten E-Mail-Adressen auffindbar sein. Die
Zuordnung mehrerer Adressen zu einem Gesprächspartner soll erst beim Abruf
eines Kontaktverlaufs angewendet werden. Eine neue oder bislang unbekannte
Adresse kann dadurch samt ihrem bereits gespeicherten Mailverkehr nachträglich
einem bestehenden Kontakt zugeordnet und später wieder getrennt werden, ohne
Nachrichtendaten umzuschreiben. Die Zuordnung muss nachvollziehbar und
reversibel sein.

**Entscheidung:** Die Nachricht ist die unveränderliche Datengrundlage;
E-Mail-Adressen sind eigenständige Identitäten; Kontakte sind eine veränderbare
Aggregation dieser Identitäten. Ein SMTP-/RFC-Thread ist ausdrücklich nicht
dasselbe wie ein Kontaktverlauf. Dieser Entwurf ist ein **Breaking Change**:
Die bisherigen Tabellen, Methoden und gespeicherten Zuordnungen müssen nicht
rückwärtskompatibel bleiben. Das betrifft jedoch nicht die Forderung, neue
Adresszuordnungen später sicher rückgängig machen zu können.

## § 2 Ist-Zustand und Probleme

- `src/Storage.php` speichert mit `mail_history.contact_id` die Zuordnung
  direkt beim Schreiben; `historyForContact()` filtert diese feste Spalte.
  Eine nachträglich zugeordnete Adresse bringt dadurch alte Nachrichten nicht
  automatisch in den Kontaktverlauf.
- `MailAutomation::processMessage()` ruft `recordHistory()` nur nach einer
  erfolgreich behandelten Action auf. Unbearbeitete, als verarbeitet markierte
  oder bei der Sent-Baseline übersprungene Nachrichten können deshalb in diesem
  Verlauf fehlen.
- `contact_aliases` unterstützt mehrere Adressen pro Kontakt, bietet aber
  weder einen atomaren Transfer einer Adresse von Kontakt A nach Kontakt B
  noch ein überprüfbares Undo-Protokoll.
- `MailAnalyzer` speichert `ai-history` und `ai-thread` separat in
  `metadata`; `ConversationHistory::build()` verfolgt
  `Message-ID`/`References`/`In-Reply-To` und prüft gemeinsame
  Teilnehmer. Dieser technische Threadverlauf soll nicht stillschweigend mit
  einem frei zusammengefügten Kontaktverlauf gleichgesetzt werden.

## § 3 Vorgeschlagenes relationales Modell

Die folgenden Tabellen bilden **ein neues Schema**. Der bestehende
`mail_history`-Aufbau ist nicht die Zielstruktur.

| Tabelle | Wichtige Spalten / Constraints | Verantwortung |
|---|---|---|
| `email_addresses` | `id` PK, `normalized_address` UNIQUE NOT NULL | Kanonische, auch ohne bekannten Kontakt existierende Adresse |
| `contacts` | `id` PK, `name`, `primary_email_id` NULL FK | Gesprächspartneridentität ohne eingebettete Nachrichten |
| `contact_email_assignments` | `email_id` PK/FK, `contact_id` FK NOT NULL, `name`, `source` | Aktuelle, 0:1-Zuordnung Adresse → Kontakt; ein Kontakt hat 0:n Adressen |
| `mail_messages` | `id` PK, `account_id`, `source_key`, `rfc_message_id`, `direction`, `folder`, `server_id`, `subject`, `mail_date`, `observed_at`, `tag_scope_id` | Eine persistente Nachricht pro stabiler Mailbox-Identität, ohne `contact_id` |
| `mail_message_addresses` | `message_id` FK, `email_id` FK, `role`, `is_correspondent`, PK (`message_id`, `email_id`, `role`) | Alle bekannten From/To/Cc/Bcc-Bezüge und Auswahl der Korrespondenz-Adressen |
| `contact_assignment_events` | `id` PK, `email_id`, `from_contact_id` NULL, `to_contact_id` NULL, `actor`, `reason`, `created_at`, `reverts_event_id` NULL | Append-only Audit-Trail einschließlich Rückabwicklung |

Für `mail_messages` gilt ein UNIQUE-Constraint auf
`(account_id, source_key)`; ein Retry darf keine zweite Nachricht erzeugen.
`source_key` nutzt bevorzugt eine stabile Backend-Nachrichtenidentität
innerhalb des Accounts. Eine RFC-`Message-ID` **allein** reicht wegen
möglicher Duplikate und fehlender IDs nicht als Primärschlüssel. Ordnerwechsel
oder erneut beobachtete Nachrichten müssen anhand dokumentierter
Mailbox-Identität behandelt werden. Der Dateispeicher und das bisherige
`metadata`-/`tags`-Konzept können fachlich getrennt bleiben, benötigen
aber stabile Scope-IDs.

`contact_email_assignments` speichert die Adresse nicht nochmals als Text.
Die normalisierte Adresse wird ausschließlich über `email_addresses`
aufgelöst. Die Primäradresse eines Kontakts muss zu dessen aktuell
zugeordneten Adressen gehören; diese Invariante wird innerhalb derselben
Schreibtransaktion geprüft.

### § 3.1 Korrespondenz und Mehrfachempfänger

Beim Eingang wird mindestens der externe `From`-Absender als
Korrespondenz-Adresse markiert; beim Ausgang werden alle externen
`To`-, `Cc`- und `Bcc`-Empfänger markiert. Die eigenen Mailbox-Adressen
werden erkannt und nicht als externe Gesprächspartner gezählt. Sämtliche
bekannten Header-Adressen samt Rolle bleiben als Beziehungsdaten erhalten.

Eine Nachricht mit mehreren externen Empfängern kann folglich zu mehreren
Adressverläufen gehören. Im aggregierten Kontaktverlauf erscheint sie durch
`DISTINCT mail_messages.id` trotzdem nur einmal. `Bcc` darf niemals als
Empfängerinformation in eine fremde Antwort oder einen fremden Prompt
weitergegeben werden; die Speicherung der Rohbeziehung ist kein
Freigaberecht für eine solche Offenlegung.

## § 4 Lesen und Schreiben

### § 4.1 Aufnahme vor der Automation

Der normale Durchlauf bleibt `Sent` vor `Inbox`. Jede tatsächlich
beobachtete Mail wird zunächst idempotent in `mail_messages` sowie
`mail_message_addresses` eingetragen – **auch** bei Sent-Baseline,
verarbeiteter Nachricht, nicht passender Rule oder späterem
Handler-Fehler. Erst danach laufen Kontaktauflösung, AI-Analyse und
Actions. `dry-run` darf an keiner dieser Tabellen und auch nicht am
Zuordnungsprotokoll etwas ändern. Beim Fehler der Speicherung darf kein
Cursor stillschweigend über die betroffene Nachricht hinweg fortgesetzt
werden.

Der bestehende `recordHistory(?string $contactId, ...)`-Vertrag wird
durch eine kontaktunabhängige Indexierungs-API ersetzt. Ein
`Message-ID`-basierter Thread-Index darf erhalten bleiben, wird aber als
separate technische Sicht behandelt.

### § 4.2 Adress- und Kontaktverlauf

`historyForEmail(email)` liefert die chronologisch absteigend
geordneten eindeutigen Nachrichten dieser Adresse. Die neue
`historyForContact(contactId)`-Abfrage bestimmt **bei jedem Lesen** zunächst
alle aktuell zugeordneten Adressen und bildet dann die nach Datum sortierte
Vereinigung ihrer Nachrichten. Bestehende `HistoryFilter`, Tags und
Limits werden **nach** der Deduplizierung in dieser Reihenfolge angewandt.

Konzeptioneller SQL-Ausdruck (Tabellennamen und Spalten entsprechen § 3):

```sql
SELECT DISTINCT m.*
FROM mail_messages AS m
JOIN mail_message_addresses AS p ON p.message_id = m.id
JOIN contact_email_assignments AS a ON a.email_id = p.email_id
WHERE a.contact_id = :contact_id
  AND p.is_correspondent = 1
ORDER BY julianday(COALESCE(m.mail_date, m.observed_at)) DESC, m.id DESC;
```

Für die Einzeladresse wird statt des Assignment-Joins nach
`email_addresses.normalized_address` gefiltert. Für beide Abfragen
sind passende Indizes auf Assignment-Kontakt, Message-Address und
Message-Datum erforderlich. Das Kontakt-Metadatenobjekt ist dabei
unabhängig von den unveränderten Metadaten einer Nachricht.

## § 5 Zuordnung, Transfer, Undo

Die öffentliche API soll die fachlichen Operationen ausdrücken, nicht
SQL-Zeilen nach außen reichen:

```php
$contact = $storage->contacts()->findById($contactId);

// Ordnet auch eine bereits zuvor beobachtete Adresse zu.
// Das Ergebnis enthält die ID des Audit-Events.
$event = $storage->contacts()->assignEmail(
    'neue-adresse@example.org',
    $contact->id,
    reason: 'manual_review',
);

// Stellt die durch dieses Event veränderte Zuordnung wieder her,
// indem ein neues kompensierendes Event angehängt wird.
$undoEvent = $storage->contacts()->undoAssignment($event->id);
```

Dies ist eine **vorgeschlagene API**, noch keine implementierte Methode.
Die Rückgabe eines No-op bei bereits identischer Zuordnung muss
explizit definiert sein, z. B. `null` statt eines künstlichen Events.

Jede Zuordnung erfolgt in **einer** SQLite-Transaktion: Zielkontakt
prüfen, aktuellen Besitzer und Primäradress-Invariante prüfen,
Assignment aktualisieren, vollständige Vorher-/Nachher-Zuordnung als
Event protokollieren und committen. Eine Adresse darf zu einem
Zeitpunkt höchstens einem Kontakt gehören. Vor dem Transfer einer
Primäradresse muss für den bisherigen Kontakt eine andere gültige
Primäradresse gewählt werden. Automatisch erkannte Reply-Aliase
dürfen die exklusive Zuordnung nicht ungeprüft überschreiben; ein
Konflikt erfordert Review.

`undoAssignment(eventId)` ist **kein** Löschen von Nachrichten und
kein Zurückdrehen einer Datenbankmigration. Es prüft atomar, ob seit
diesem Event keine neuere, noch wirksame Änderung an derselben Adresse
vorliegt und ob der aktuelle Besitzer weiterhin dem erwarteten Zustand
entspricht. Bei Konflikten wird mit Fehlermeldung abgebrochen. Bei
gültigem Undo wird ein neues Event mit `reverts_event_id` angehängt,
das den früheren Besitzer wiederherstellt (oder die Zuordnung entfernt).
Die Historie der Entscheidungen bleibt vollständig erhalten. Das
Wiederholen desselben Undo darf keine zweite Umkehrung auslösen.

### § 5.1 Beobachtbares Beispiel

1. Von `a@firma.example` liegen bereits drei empfangene und zwei
   gesendete Mails vor. Die Adresse gehört zu Kontakt K1.
2. Von `neu@firma.example` sind zwei Nachrichten gespeichert,
   aber keinem Kontakt zugeordnet.
3. `assignEmail('neu@firma.example', K1)` macht ohne Umschreiben der
   sieben Nachrichten sofort alle sieben im K1-Verlauf sichtbar.
4. `undoAssignment(eventId)` reduziert K1 wieder auf die fünf Mails
   der ursprünglichen Adresse. Die zwei Nachrichten bleiben über
   `historyForEmail('neu@firma.example')` auffindbar.
5. Falls `neu@firma.example` zuvor Kontakt K2 gehörte, werden nach
   einem zulässigen Transfer die beiden Mails aus K2 entfernt und
   K1 zugeschlagen; das Undo stellt K2 wieder her.

## § 6 Technische Threads, AI und Sicherheit

`ConversationHistory::build()` kann weiterhin RFC-Referenzketten für
den *technischen Thread* bilden. Eine über mehrere Adressen aggregierte
*Kontakt-Timeline* ist eine andere, bewusst angeforderte Sicht. Ein
späterer AI-Context-Builder kann diese Timeline als Quellenmenge
verwenden, muss aber pro Nachricht die tatsächlichen Teilnehmer und
Zugriffsrechte prüfen und darf `Bcc`/fremde Empfänger nicht
ungeprüft in Prompts mischen. Die bestehende `ConversationScope`-ID
aus einem Thread-Root darf nicht ohne explizite Migration zu einer
Kontakt-ID umgedeutet werden.

Eine Kontaktänderung ändert nur die **Sicht** auf die Historie. Bereits
erzeugte AI-Antworten, Entwürfe, Analysen und Kontakt-Metadaten werden
dadurch nicht rückwirkend verändert. Neu erzeugte Kontexte müssen die
aktuellen Assignments verwenden und keine veraltete materialisierte
Kontakt-Timeline cachen.

## § 7 Breaking-Change-Rollout und Datenübernahme

Es gibt **keine Verpflichtung**, die alten `mail_history`-Zeilen,
Methodensignaturen oder `contact_aliases` automatisch zu übernehmen.
Der erste Implementierungs-PR darf eine neue Datenbank beziehungsweise
einen expliziten Schema-Versionswechsel voraussetzen. Er muss alte
Datenbestände jedoch **erkennen und mit klarer Fehlermeldung stoppen**,
anstatt sie stillschweigend zu löschen oder teilweise weiterzuverwenden.
Vor einem produktiven Wechsel: SQLite-Backup, neue Schema-Version,
Mailbox-Abdeckung sowie Wiederaufbau/Import entscheiden und prüfen.

Ein erneutes Scannen vorhandener Sent-/Inbox-Nachrichten kann nur
Mailverkehr rekonstruieren, der tatsächlich noch im Postfach liegt.
Gelöschte Nachrichten, alte Tags und alte Kontaktmetadaten erfordern
bei gewünschter Übernahme einen **eigenen**, ausdrücklich freizugebenden
Import. Diese historische Datenübernahme ist nicht mit dem späteren
fachlichen Adress-`assign`/`undo`-Vorgang aus § 5 gleichzusetzen.
Ohne Import beginnt die neue Timeline leer; zum Rescan müssen die
bestehenden Cursor bewusst neu initialisiert werden.

## § 8 Implementierungsschnitt für den Folge-PR

1. `src/Storage.php`: neues Schema, idempotente
   Nachrichtenerfassung, Adressindizes, dynamische Verlaufsklassen
   und transaktionales Zuordnungs-/Undo-Protokoll.
2. `src/Domain.php`: kontaktunabhängige History-Methoden und
   neue explizite Assignment-Operationen; bisherige gebundene
   `recordHistory(contactId, ...)`-Signatur entfällt.
3. `src/MailAutomation.php`: Indexierung vor Flag-/Action-Checks;
   keine zweite Verlaufsspeicherung nach erfolgreichem Handler.
4. `src/ReplyContactResolver.php`: Alias-Lernen über den
   Assignment-Service und Konfliktschutz statt implizitem
   Überschreiben eines anderen Kontakts.
5. `src/Analysis/MailAnalyzer.php`: technische Thread-Sicht und
   spätere Kontakt-Sicht klar trennen; keine automatische
   Vermischung bei der ersten Datenmodell-Umstellung.
6. `test/SqliteStorageTest.php`,
   `test/MailAutomationTest.php` und fokussierte neue Tests:
   Mehrfachzuordnung, Rescan, Undo, Altbestand-Sperre und
   Dry-run-Verhalten prüfen.

Eine CLI/UI zum manuellen Zuordnen kann danach separat erstellt
werden; zuerst braucht die Library eine vollständig testbare API.
Der Implementierungs-PR soll nur den neuen Vertrag berücksichtigen
und keine Kompatibilitätsschicht um alte Aufrufwege bauen.

## § 9 Abnahme- und Risikokriterien

- **Vollständigkeit:** Inbox, Sent und übersprungene Actions werden
  indexiert; kein doppelter Eintrag bei erneutem Scan.
- **Zuordenbarkeit:** Eine bislang unbekannte Adresse ist ohne
  Kontakt dauerhaft auffindbar. Ein Kontakt kann mehrere Adressen
  besitzen. Zwei Kontakte können nicht gleichzeitig dieselbe
  Adresse beanspruchen.
- **Undo:** Zuordnen, Transferieren und rückgängig Machen ändern
  ausschließlich Assignments. Nachrichten und Tags bleiben intakt;
  Konflikte und veraltete Undo-Versuche werden zurückgewiesen.
- **Timeline:** Nach jeder gültigen Assignment-Änderung liefert
  eine frische Kontakt-Abfrage unmittelbar den korrekten,
  sortierten, deduplizierten Gesamtverlauf; Filter und Limits
  funktionieren weiterhin.
- **Betrieb:** Dry-run erzeugt keine Writes; Backend-Fehler dürfen
  keine Cursor-Datenlücken verursachen. Das alte Schema führt
  nicht zu stillschweigendem Datenverlust.
- **Sicherheit:** Mehrfachadressierung, Alias-Konflikte, eigene
  Absenderadressen und Bcc sind explizit getestet; technische
  Thread-Grenzen bleiben erkennbar.
- **Validierung:** SQLite-Integrationstests, PHPUnit sowie
  ein kontrollierter Test mit einer nichtproduktiven Mailbox.
  Kein Test darf echte Mails versenden oder Live-AI aufrufen.

**Status:** Reviewbarer Architekturentwurf. Dieser PR implementiert
bewusst **noch keine** Datenbankschema- oder Laufzeitänderung und
führt keine Migration produktiver Nachrichten durch.
