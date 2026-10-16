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
Es gibt nur **einen** pm2-Prozess (`discord-ai-bot`): Er startet das Web-Panel selbst als Kindprozess (Port `PANEL_PORT`,
Log `panel.log`) und startet es neu, falls es beendet wird. Mit `PANEL_EMBEDDED=false` lässt sich das abschalten.
Ein früheres separates pm2-Programm `discord-ai-panel` bitte löschen: `pm2 delete discord-ai-panel`.
Aufträge aus dem Panel (z.B. "Durchsuchung starten") werden vom laufenden Bot ausgeführt – der Bot muss also laufen.
Nach Änderungen in den Einstellungen: `pm2 restart discord-ai-bot`.

## Wie das Panel Aufträge ausführt

Klickst du im Panel auf z.B. „Durchsuchung starten“, entsteht ein Auftrag. Läuft der Bot (pm2), führt er ihn aus.
Ist der Bot offline oder übernimmt er den Auftrag nicht innerhalb von 20 Sekunden, startet **das Panel ihn selbst**
über `run_job.php` im Hintergrund – es muss also nichts in der Konsole eingegeben werden. Voraussetzung: PHP darf
Programme starten (`exec`/`shell_exec`/`proc_open` nicht in `disable_functions`). Bei Problemen die Dateien
`jobrunner.log`, `crawl.log` und `panel-error.log` ansehen. Liegt das PHP-CLI nicht im Pfad, in der `.env`
`PHP_CLI=/pfad/zu/php` setzen.

## Antworten ohne KI-Modell

Hinter dem Bot steht **kein KI-Modell**. Er baut seine Antwort allein aus den Informationen, die er auf dem Discord gelesen hat
(Antworten des Teams und Server-Infos): Zur Frage werden die passenden Stellen gesucht, einzelne Sätze nach ihrer Passung
bewertet, die besten ausgewählt (ohne Doppelungen) und in eine höfliche Antwort eingebettet. Wurde eine sehr ähnliche Frage
vom Team schon beantwortet, gibt er diese Antwort im Original wieder. Weiß der Bot nichts, antwortet er in den Antwort-Channels
trotzdem mit einem freundlichen Hinweis. Wer sofort statt nach `ANSWER_DELAY_SECONDS` (Wartezeit, ob ein Teamler schneller ist)
antworten soll, setzt den Wert niedrig (min. 5). Die Trefferschwelle steuert `SEARCH_MIN_SCORE`.

Die Antwort enthält **nur den Antworttext** – ohne Begrüßung, Quellenangabe oder Abschlussformel. Findet der Bot keinen
ausreichend sicheren Treffer (unter `SEARCH_MIN_SCORE`), **markiert er automatisch das Team** (Rollen aus `TEAM_PING_ROLE_IDS`,
leer = erste Rolle aus `TEAM_ROLE_IDS`). Antwortet ein Teamler auf diese Nachricht per Reply, lernt der Bot die Antwort.

## Wo der Bot antwortet

- `QUESTION_CHANNEL_IDS`: Channels, in denen der Bot auf **Fragen** antwortet (leer = überall).
- `ALWAYS_ANSWER_CHANNEL_IDS`: Channels (z.B. Support-Chat), in denen er auf **jede** Nachricht (ab 10 Zeichen) antwortet.
- `ANSWER_ONLY_CATEGORY_IDS`: Kategorien (z.B. „Erstellte Tickets“): dort antwortet der Bot **immer** auf jede Nachricht,
  liest/lernt aber **nichts** aus diesen Channels (auch nicht bei der Durchsuchung).
- `ANSWER_SOURCE_CHANNEL_IDS`: Ausnahme in einer Antwort-Kategorie – dieser Channel (z.B. das Wissens-Ticket) wird komplett
  ausgewertet (alle Nachrichten inkl. Bot-Embeds, in Abschnitten gespeichert) und dient als Informationsquelle.

Der Bot wartet `ANSWER_DELAY_SECONDS`, ob ein Teamler zuerst antwortet; in diesen Channels erscheint bei unbekannter Frage
immer der freundliche Hinweis. Änderungen an diesen Werten brauchen einen Bot-Neustart (`pm2 restart discord-ai-bot`).

Damit die Antworten **sinngemäß** sind, prüft der Bot, ob die ausgewählten Sätze die wichtigen (seltenen) Wörter der Frage abdecken
(`ANSWER_MIN_COVERAGE`, Standard 0.5). Gleichbedeutende Begriffe (z.B. Fahrzeug/Auto/Wagen) werden zusammengeführt; eigene Wortgruppen
lassen sich über `SYNONYMS` ergänzen (`fahrzeug=auto,wagen=auto`). Gibt es **keine gültige Antwort**, leitet der Bot die Frage
**sofort** (ohne Wartezeit) ans Team weiter und markiert es. Dank und Grüße (Smalltalk) beantwortet er nicht und leitet sie nicht weiter.

## Website und Regelwerk als Wissensquelle

`php web.php` (oder im Panel **Steuerung > Website einlesen**) liest die Seiten aus `WEBSITE_URLS` (Startseite, Regelwerk, Allgemeine Hinweise,
Changelog) in Abschnitten ein und lädt zusätzlich ein gesichertes **Grundwissen** (`src/Seed.php`: Server-IP, Voraussetzungen, Whitelist,
Kosten, Streamer, Regeln wie New-Life/RDM/Metagaming/Safezone). Antworten aus Website und Regelwerk werden bei der Auswahl leicht bevorzugt.
**Automatisch und von der Website aus:** Der Bot liest die Website regelmäßig selbst ein (Schalter und Intervall unter
**Steuerung**, Standard alle 24 h). Außerdem kann die Website einen Import auslösen: Token in `WEB_TRIGGER_TOKEN` setzen und
`https://<panel>/trigger.php?token=<TOKEN>` aufrufen (z.B. nach Änderungen am Regelwerk). Ohne Token ist das deaktiviert.

Das Grundwissen ist im Panel unter **Wissen** (Herkunft „seed“) bearbeitbar; bei Änderungen auf der Website `src/Seed.php` anpassen.
Bewusst nicht enthalten ist das Mindestalter, solange die Website 18 (Startseite) und 16 (Allgemeine Hinweise) nennt.

## Tickets: Bot an/aus

In Tickets (Kategorie aus `ANSWER_ONLY_CATEGORY_IDS`) beantwortet der Bot die Fragen des Spielers nach `TICKET_ANSWER_DELAY_SECONDS`
(Standard 8 s). **Sobald ein Teamler im Ticket schreibt, schweigt der Bot dort.** Ein Teamler setzt ihn mit `!bot an` wieder
ein (und mit `!bot aus` aus). Der Zustand wird pro Ticket gespeichert und übersteht Neustarts. Im Testmodus werden Teamler wie Spieler
behandelt, der Bot schweigt dann nicht.

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
