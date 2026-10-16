<?php
declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMXPath;

/**
 * Liest die Seiten der eigenen Website (WEBSITE_URLS) und speichert den Text als Wissen (Tabelle server_info,
 * channel_id = "web"). Jeder Import ersetzt den vorherigen Stand der Website-Infos vollstaendig.
 */
final class WebImporter
{
    private const MAX_CHUNK = 700;

    public function __construct(private Database $db, private Knowledge $knowledge)
    {
    }

    /** @return array{pages:int,chunks:int,errors:string[]} */
    public function run(callable $log): array
    {
        $urls = Env::list('WEBSITE_URLS');
        if ($urls === []) {
            $log('WEBSITE_URLS ist leer - nichts zu importieren.');
            return ['pages' => 0, 'chunks' => 0, 'errors' => []];
        }
        if (!class_exists(DOMDocument::class)) {
            $log('PHP-Erweiterung dom/xml fehlt (apt install php-xml).');
            return ['pages' => 0, 'chunks' => 0, 'errors' => ['dom fehlt']];
        }

        $pages = [];
        $errors = [];
        foreach ($urls as $url) {
            if (!preg_match('#^https?://#i', $url)) {
                $errors[] = "{$url}: ungueltige URL";
                continue;
            }
            [$status, $html, $err] = $this->fetch($url);
            if ($status !== 200 || $html === '') {
                $errors[] = "{$url}: " . ($err !== '' ? $err : "HTTP {$status}");
                $log("  [Fehler]  {$url}: " . ($err !== '' ? $err : "HTTP {$status}"));
                continue;
            }
            [$title, $sections] = $this->extract($html);
            $chunks = $this->toChunks($title, $sections);
            $pages[$url] = ['title' => $title, 'chunks' => $chunks];
            $log(sprintf('  [gelesen] %s (%s): %d Abschnitte', $url, $title !== '' ? $title : 'ohne Titel', count($chunks)));
        }

        // Nur ersetzen, wenn etwas gelesen wurde (bei Netzwerkproblemen bleibt der alte Stand erhalten)
        $total = 0;
        if ($pages !== []) {
            $pdo = $this->db->pdo();
            $pdo->exec("DELETE FROM server_info WHERE channel_id = 'web'");
            $now = date('Y-m-d H:i:s');
            foreach ($pages as $url => $p) {
                $name = 'Website: ' . ($p['title'] !== '' ? $p['title'] : (parse_url($url, PHP_URL_PATH) ?: $url));
                foreach ($p['chunks'] as $chunk) {
                    $this->knowledge->addInfo('web-' . substr(md5($url . '|' . $chunk), 0, 28), 'web', mb_substr($name, 0, 100), $chunk, $now);
                    $total++;
                }
            }
        }
        return ['pages' => count($pages), 'chunks' => $total, 'errors' => $errors];
    }

    /** @return array{0:int,1:string,2:string} */
    private function userAgent(): string
    {
        return Env::get(
            'WEB_USER_AGENT',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 ViennaStateRP-SupportBot/1.0'
        );
    }

    /** @return string[] Browser-aehnliche Header, damit Schutzsysteme den Abruf nicht als Bot ablehnen */
    private function headers(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-AT,de;q=0.9,en;q=0.7',
            'Cache-Control: no-cache',
        ];
    }

    /** Erklaert eine Sperre (403/429/503) moeglichst konkret. */
    private function blockedHint(int $status, string $body, array $respHeaders): string
    {
        $server = $respHeaders['server'] ?? '';
        $cf = isset($respHeaders['cf-ray']) || stripos($server, 'cloudflare') !== false || stripos($body, 'cf-chl') !== false || stripos($body, 'Just a moment') !== false;
        $hint = "HTTP {$status}" . ($server !== '' ? " (Server: {$server})" : '');
        if ($cf) {
            $hint .= ' - Cloudflare-Schutz blockiert den Abruf. In Cloudflare unter Sicherheit > WAF eine Ausnahme fuer die Server-IP des Bots anlegen oder den Bot-Schutz fuer /regelwerk.php u.a. lockern.';
        } elseif (in_array($status, [403, 429, 503], true)) {
            $hint .= ' - die Website lehnt den Abruf ab. Server-IP des Bots in der Firewall/WAF/Hoster-Schutz der Website freigeben'
                . ' oder WEB_USER_AGENT in der .env anpassen. Pruefen mit: curl -I ' . 'https://viennastaterp.at/ (vom Bot-Server aus).';
        }
        return $hint;
    }

    private function fetch(string $url): array
    {
        if (function_exists('curl_init')) {
            $respHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 25,
                CURLOPT_USERAGENT => $this->userAgent(), CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $this->headers(),
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $respHeaders[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $err = $body === false ? curl_error($ch) : '';
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $body = $body === false ? '' : (string) $body;
            if ($status !== 200 && $err === '') {
                $err = $this->blockedHint($status, $body, $respHeaders);
            }
            return [$status, $body, $err];
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 25, 'ignore_errors' => true,
            'header' => 'User-Agent: ' . $this->userAgent() . "\r\n" . implode("\r\n", $this->headers()) . "\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $body = $body === false ? '' : $body;
        return [$status, $body, $status === 200 ? '' : ($status === 0 ? 'Abruf fehlgeschlagen' : $this->blockedHint($status, $body, []))];
    }

    /**
     * @return array{0:string,1:array<int,array{heading:string,lines:string[]}>} [Titel, Abschnitte]
     */
    private function extract(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $title = trim((string) $xp->evaluate('string(//title)'));
        $title = trim((string) preg_replace('/\s*[|\-–].*$/u', '', $title)); // "Regelwerk | ViennaStateRP" -> "Regelwerk"

        // Navigation, Fuss, Skripte und Formulare entfernen
        foreach ($xp->query('//script|//style|//noscript|//nav|//header|//footer|//form|//svg|//iframe|//select|//button') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $block = 'p or .//li or .//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//h6 or .//td or .//div or .//dt or .//dd or .//blockquote';
        $nodes = $xp->query(
            '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::li or self::td or self::th'
            . ' or self::dt or self::dd or self::blockquote or self::div or self::span or self::summary]'
            . '[not(.//' . $block . ')]'
        ) ?: [];

        $sections = [['heading' => '', 'lines' => []]];
        $last = '';
        foreach ($nodes as $n) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $n->textContent));
            if (mb_strlen($text) < 3 || $text === $last || preg_match('/cookie|google analytics|alle rechte vorbehalten|©/iu', $text)) {
                continue;
            }
            $last = $text;
            if (preg_match('/^h[1-6]$/i', $n->nodeName)) {
                $sections[] = ['heading' => $text, 'lines' => []];
            } else {
                $sections[count($sections) - 1]['lines'][] = $text;
            }
        }
        return [$title, array_values(array_filter($sections, fn($s) => $s['lines'] !== []))];
    }

    /**
     * @param array<int,array{heading:string,lines:string[]}> $sections
     * @return string[]
     */
    private function toChunks(string $title, array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            $prefix = trim(($title !== '' ? $title . ' – ' : '') . $s['heading'], ' –');
            $cur = '';
            foreach ($s['lines'] as $line) {
                foreach (explode("\n", wordwrap($line, self::MAX_CHUNK, "\n", true)) as $part) {
                    if ($cur !== '' && mb_strlen($cur) + mb_strlen($part) + 1 > self::MAX_CHUNK) {
                        $out[] = ($prefix !== '' ? $prefix . ":\n" : '') . $cur;
                        $cur = '';
                    }
                    $cur .= ($cur === '' ? '' : "\n") . $part;
                }
            }
            if ($cur !== '') {
                $out[] = ($prefix !== '' ? $prefix . ":\n" : '') . $cur;
            }
        }
        return $out;
    }
}
