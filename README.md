# Discord KI-Bot (PHP)

Selbstlernender, höflicher Support-Bot auf Basis von [DiscordPHP](https://github.com/discord-php/DiscordPHP).

## Funktionen

- **Antwortet auf Fragen**, wenn innerhalb von `ANSWER_DELAY_SECONDS` kein Teamler geantwortet hat.
- **Lernt von Teamlern**: Antworten von Mitgliedern der `TEAM_ROLE_IDS` (per Reply oder direkt im Channel) werden mit der Frage gespeichert.
- **Korrekturen**: Antwortet ein Teamler auf eine Bot-Antwort, wird die neue Antwort gelernt und die alte abgewertet.
- **Höflicher Ton** (`du` oder `sie` über `ADDRESS_FORM`).
- **Channel-Überwachung**: erkennt Beleidigungen, Caps-Spam und Streit zwischen mehreren Personen, greift mit einem deeskalierenden Hinweis ein und meldet wiederholte Eskalationen im `LOG_CHANNEL_ID` (ohne automatische Strafen).

## Installation

Ein einziges Skript erledigt alles:

```bash
php install.php
```

Es prüft PHP, lädt Composer selbst herunter, installiert die Abhängigkeiten, fragt alle Einstellungen ab,
schreibt die `.env` (LIVE-Datei, nicht im Git), prüft Token und Datenbank, legt Datenbank und Tabellen an
und startet den Bot auf Wunsch. Danach genügt `php bot.php`.

## Dauerbetrieb mit pm2

```bash
npm i -g pm2
pm2 start ecosystem.config.js    # startet Bot (bot.php) und Panel
pm2 save && pm2 startup          # Autostart nach Server-Neustart
pm2 logs discord-ai-bot          # Live-Log
```

`install.php` richtet das automatisch ein, wenn pm2 installiert ist. Der Bot startet bei Abstürzen selbst neu.
Aufträge aus dem Panel (z.B. "Durchsuchung starten") werden vom laufenden Bot ausgeführt – der Bot muss also laufen.
Nach Änderungen in den Einstellungen: `pm2 restart discord-ai-bot`.

## Steuerung im Panel

Unter **Steuerung** startest du alle Funktionen per Klick (Server durchsuchen, Channels synchronisieren, Update-Prüfung,
Bot neu starten) und schaltest sie ohne Neustart ein/aus: Fragen beantworten, Lernen, Channel-Überwachung, Hinweis bei
unbekannter Frage, **automatische Durchsuchung** (Intervall in Stunden) und der **Testmodus**.

**Testmodus:** Teamler werden nicht ignoriert – ihre Nachrichten werden wie Spielernachrichten behandelt, der Bot antwortet
nach max. 10 Sekunden und die Überwachung greift. Es wird nichts gelernt; Bot-Nachrichten sind mit „🧪 Testmodus“ markiert.
Im Panel erscheint solange ein gelbes Banner. Nach dem Testen wieder ausschalten.

## Updates von GitHub

Im Panel unter **Updates**: „Auf Updates prüfen“ holt den Stand von GitHub (`git fetch`) und zeigt neue Commits;
„Update installieren“ führt `git pull --ff-only` aus, aktualisiert bei Bedarf die Composer-Pakete und startet den Bot
(unter pm2) automatisch neu. Voraussetzungen: Projekt per `git clone` installiert, `git` auf dem Server, Bot läuft.
Lokale Änderungen an versionierten Dateien brechen das Update ab; die `.env` bleibt unberührt.
Manuell: `php update.php check` bzw. `php update.php apply`.

## Server durchsuchen

```bash
php crawl.php
```

Liest die Nachrichtenverläufe und speichert daraus Frage/Antwort-Paare (Teamler antwortet auf Spielerfrage) sowie
längere Team-Texte (Regeln, Ankündigungen, FAQ → Tabelle `server_info`). Wiederholte Läufe lesen nur neue Nachrichten.

**Datenschutz:** Channels, die für `@everyone` nicht sichtbar sind und nur für `TEAM_ROLE_IDS` (oder einzelne Personen)
freigegeben sind, werden nicht gelesen. Ohne gesetzte `TEAM_ROLE_IDS` bricht die Durchsuchung ab. Personen-Erwähnungen werden vor
dem Speichern entfernt. Weitere Channels über `CRAWL_IGNORE_CHANNEL_IDS` ausschließen. Welche Channels gelesen/übersprungen wurden,
zeigt das Panel unter "Server-Infos". Benötigt den **Server Members Intent**.

**Channels/Kategorien ausnehmen:** Im Panel unter **Channels** per Haken einzelne Channels oder ganze Kategorien von der
Durchsuchung ausnehmen. Die Liste wird vom laufenden Bot aus Discord übernommen (alle 5 Minuten bzw. bei jeder Durchsuchung).
Team-Channels sind dort bereits automatisch gesperrt dargestellt. Änderungen gelten ab der nächsten Durchsuchung.

## Web-Panel

Ordner `panel/`. `install.php` legt Benutzer und Passwort an und startet das Panel auf Wunsch.
Manuell: `php -S 0.0.0.0:8080 -t panel` (oder `panel/` als Webroot eines Apache/nginx-vHosts verwenden).

Einstieg ist `index.php` (nur die Anmeldung); alles Weitere liegt hinter dem Login. Die `index.php` im Projektroot leitet auf `panel/index.php` weiter, die `.htaccess` sperrt `.env`, `src/` und `vendor/` (Apache). Besser: nur `panel/` als Webroot verwenden.

- **Dashboard** (`dashboard.php`): Statistiken, zuletzt gelernte und meistgenutzte Antworten, letzte Eingriffe
- **Wissen**: Antworten suchen, hinzufügen, bearbeiten, löschen, bewerten
- **Eingriffe**: Protokoll der Channel-Überwachung
- **Einstellungen**: Bot-Parameter und Panel-Passwort (schreibt in die `.env`; Bot danach neu starten)

Für öffentlichen Zugriff das Panel hinter HTTPS (Reverse-Proxy) betreiben.

## Discord-Einstellungen

Im Developer Portal beim Bot den **Message Content Intent** und **Server Members Intent** aktivieren.
Der Bot braucht die Rechte: Nachrichten lesen/senden, Nachrichtenverlauf anzeigen.

## Sicherheit

`.env` enthält die Live-Zugangsdaten und steht in der `.gitignore`. Auf GitHub gehört nur `.env.example` (ohne Zugangsdaten).
