<?php
declare(strict_types=1);

namespace App;

/**
 * Grundwissen aus der Website viennastaterp.at (Stand 16.10.2026): haeufige Fragen mit kurzen, gesicherten Antworten.
 * Wird beim "Website einlesen" mitgeladen und ist im Panel unter "Wissen" (Herkunft "seed") bearbeitbar und loeschbar.
 * Bewusst NICHT enthalten: das Mindestalter - die Website nennt dazu aktuell 18 (Startseite) und 16 (Allgemeine Hinweise).
 */
final class Seed
{
    /** @return array<int,array{0:string,1:string}> [Frage, Antwort] */
    public static function entries(): array
    {
        return [
            ['Wie lautet die Server IP und wie verbinde ich mich mit dem Server?',
                'Server-IP: fivem.viennastaterp.at:30120 – Direktlink: fivem://connect/fivem.viennastaterp.at:30120'],
            ['Welche Voraussetzungen gibt es um auf dem Server zu spielen?',
                'Du brauchst die Mitgliedschaft und Verifizierung auf unserem Discord, ein lizenziertes GTA V, installiertes FiveM, ein funktionierendes Mikrofon und das gelesene und akzeptierte Regelwerk.'],
            ['Wie komme ich auf die Whitelist und wie funktioniert die Bewerbung?',
                'Der Server nutzt eine Discord-Whitelist: Tritt unserem Discord bei und verifiziere dich. Bewerbungen für Fraktionen laufen über Discord, jede Fraktion hat einen eigenen Bewerbungs-Channel mit Voraussetzungen und Anleitung.'],
            ['Was kostet der Server und ist er kostenlos?',
                'Die Teilnahme ist kostenlos. Du brauchst nur ein lizenziertes GTA V und FiveM. Das Projekt finanziert sich über Spenden (https://ko-fi.com/viennastaterp).'],
            ['Wie funktioniert der Voice Chat und Sprachchat?',
                'Die Sprachkommunikation läuft nur im Spiel über PMA Voice.'],
            ['Wie kann ich Streamer werden oder als Streamer spielen?',
                'Eröffne auf unserem Discord ein Streamer-Ticket, sende deinen Stream-Link und beschreibe dich kurz. Streaming ist nur mit gültiger Lizenz für die verwendeten Inhalte erlaubt.'],
            ['Ich wurde gebannt oder bestraft, was kann ich tun?',
                'Eröffne auf unserem Discord ein Support-Ticket. Dort werden Bans und Einsprüche geklärt. Entscheidungen des Teams sind final, Widersprüche laufen über ein Ticket.'],
            ['Wo finde ich das Regelwerk und die Regeln?',
                'Das vollständige Regelwerk findest du unter https://viennastaterp.at/regelwerk.php. Änderungen werden auf Discord bekannt gegeben, es gilt immer der aktuelle Stand auf der Seite.'],
            ['Wie funktioniert die New Life Rule nach dem Tod meines Charakters?',
                'Nach dem Tod vergisst dein Charakter die Ereignisse, die dazu geführt haben, und kehrt nicht an den Ort des Geschehens zurück. Es beginnt eine neue Situation ohne Erinnerungsvorteile.'],
            ['Was ist RDM und VDM und was passiert dabei?',
                'Grundloses Angreifen, Schießen oder Überfahren (RDM/VDM) ist verboten. Gewalt braucht immer einen Roleplay-Grund. Verstöße werden vom Team bestraft, bis hin zum Bann.'],
            ['Was ist Metagaming und was darf mein Charakter wissen?',
                'Dein Charakter darf nur wissen, was er im Roleplay erfahren hat. Informationen aus Streams oder Discord dürfen nicht ins Spiel übernommen werden (Metagaming).'],
            ['Gibt es Safezones auf dem Server?',
                'Nein, es gibt keine Safezones. Einzige Ausnahme ist der Ankunftsbereich am Flughafen.'],
            ['Was ist Combat Logging und ist das erlaubt?',
                'Combat Logging, also das Ausloggen während laufendem Roleplay, ist verboten.'],
            ['Was muss ich bei Überfällen und Raubüberfällen beachten?',
                'Bei Überfällen muss eine Verhandlungsmöglichkeit bestehen und es muss eine festgelegte Mindestanzahl an Polizisten im Dienst sein. Details stehen im Regelwerk (https://viennastaterp.at/regelwerk.php).'],
            ['Darf ich mehrere Accounts haben oder meinen Account weitergeben?',
                'Pro Person ist nur ein Account erlaubt. Zugangsdaten dürfen nicht weitergegeben werden, und du haftest für alles, was über deinen Account passiert.'],
            ['Wo finde ich das Changelog und die neuesten Änderungen und Updates?',
                'Das Changelog findest du unter https://viennastaterp.at/changelog.php.'],
            ['Wo sehe ich den Serverstatus und wie viele Spieler online sind?',
                'Den aktuellen Serverstatus findest du unter https://viennastaterp.at/players.php.'],
            ['Welche Fahrzeuge gibt es und kann ich sie tunen oder verkaufen?',
                'Es gibt hunderte Add-on-Fahrzeuge mit realistischer Schadensphysik und ein Tuning-System. Fahrzeuge können gekauft und verkauft werden.'],
            ['Welche Jobs und Berufe gibt es auf dem Server?',
                'Es gibt über 20 legale Jobs, außerdem ein eigenes Banksystem, eine Börse und einen Schwarzmarkt. Dazu kommen illegale Tätigkeiten wie Drogenanbau, Fahrzeugschmuggel und organisierte Kriminalität.'],
            ['Kann ich Häuser, Wohnungen und Immobilien kaufen oder mieten?',
                'Immobilien können gekauft, gemietet oder als Lager genutzt werden.'],
            ['Was für ein Server ist das und welches Genre ist es?',
                'ViennaStateRP ist ein Midcore-Roleplay-Server mit Fokus auf Realismus, mit Justizsystem (Staatsanwälte, Richter, Anwälte), Journalisten-Job und einem Smartphone mit Kontakten, Anrufen, SMS, Kamera, Browser und Social-Media-Feed.'],
            ['Wie kann ich das Projekt unterstützen und spenden?',
                'Du kannst uns über https://ko-fi.com/viennastaterp unterstützen. Die Spenden halten den Server am Laufen.'],

            // --- Regelwerk (https://viennastaterp.at/regelwerk.php, Stand 16.10.2026) ---
            ['Was ist Powergaming und was passiert dabei?',
                'Powergaming ist verboten und wird mit einem Kick geahndet. Das genaue Strafmaß legt das Team fest.'],
            ['Was ist FailRP und welche Strafe gibt es?',
                'FailRP wird mit einer Verwarnung oder einem Kick bestraft. Details stehen im Regelwerk (https://viennastaterp.at/regelwerk.php).'],
            ['Wie funktioniert die OOC Kommunikation und darf ich im Roleplay OOC schreiben?',
                'Roleplay geht vor. Der OOC-Chat ist nur für technische Probleme gedacht.'],
            ['Was ist Cop-Baiting und ist das erlaubt?',
                'Cop-Baiting, also das Provozieren der Polizei ohne Roleplay-Grund, ist untersagt.'],
            ['Darf ich in mehreren Fraktionen sein und was gilt für Fraktionen?',
                'Pro Charakter ist nur eine offizielle Fraktion erlaubt. Interne Informationen einer Fraktion bleiben intern.'],
            ['Wie muss ich als Medic oder bei Verletzungen spielen und darf ich Verletzungen ignorieren?',
                'Schwere Verletzungen dürfen nicht ignoriert werden. Sie müssen im Roleplay ausgespielt werden (Medic-RP).'],
            ['Was passiert bei Cheating, Hacking, Bugs und Exploits?',
                'Cheating, Hacking sowie das Ausnutzen von Bugs und Exploits oder Manipulation sind verboten und werden mit einem Bann geahndet.'],
            ['Welche Regeln gelten für Respekt, Beleidigungen und das Verhalten in der Community?',
                'Beleidigungen, Diskriminierung, Belästigung, Drohungen und Doxing sind verboten. Hassrede führt zu einem Bann. Respektiere andere Spieler und das Team.'],
            ['Was ist beim Thema NSFW und unangemessenem Roleplay erlaubt?',
                'NSFW-Roleplay ist nur mit dem Einverständnis aller Beteiligten erlaubt. Sexualisiertes Roleplay mit Minderjährigen ist strikt untersagt. Verstöße gegen die NSFW-Regeln werden mit einem Bann geahndet.'],
            ['Welche Strafen und Sanktionen gibt es bei Regelverstößen?',
                'Möglich sind Verwarnungen, Kicks, Bans auf dem Server, Discord-Ausschlüsse und Account-Sperren. Das Strafmaß legt das Team fest, Entscheidungen sind final. Einsprüche laufen über ein Support-Ticket auf Discord.'],
            ['Darf ich mit echtem Geld handeln oder Ingame-Items verkaufen?',
                'Der Handel mit echtem Geld (Real-Money-Trading) ist verboten und führt zu einem Bann.'],
            ['Wo werden Regeländerungen bekannt gegeben?',
                'Änderungen am Regelwerk werden auf unserem Discord bekannt gegeben. Es gilt immer der aktuelle Stand unter https://viennastaterp.at/regelwerk.php.'],
        ];
    }

    /** Laedt das Grundwissen. Vorhandene, sehr aehnliche Fragen werden aktualisiert statt doppelt angelegt. */
    public static function load(Knowledge $knowledge): int
    {
        $n = 0;
        foreach (self::entries() as [$q, $a]) {
            $knowledge->learn($q, $a, 'seed', 'seed', 'seed');
            $n++;
        }
        return $n;
    }
}
