<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Limpia el HTML de los correos entrantes con una lista blanca de etiquetas
 * y atributos. Se eliminan scripts, estilos, formularios, imágenes remotas
 * (seguimiento), manejadores on*, y enlaces que no sean http(s) o mailto.
 * El resultado se muestra además dentro de un contenedor sin JS gracias a la
 * CSP, como segunda barrera.
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'div' => [], 'span' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
        's' => [], 'small' => [], 'sub' => [], 'sup' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'pre' => [], 'code' => [], 'hr' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
        'a' => ['href', 'title'], 'font' => [], 'center' => [],
    ];
    private const DROP_WITH_CONTENT = ['script', 'style', 'head', 'title', 'iframe', 'object', 'embed', 'form', 'select', 'textarea', 'button', 'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base', 'frame', 'frameset', 'applet', 'video', 'audio', 'canvas'];

    public static function clean(string $html, int $maxBytes = 500000): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (strlen($html) > $maxBytes) {
            $html = substr($html, 0, $maxBytes);
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::walk($body);
        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if ($tag === 'img') {
                $alt = trim((string)$child->getAttribute('alt'));
                $node->replaceChild($node->ownerDocument->createTextNode($alt !== '' ? "[imagen: $alt]" : '[imagen]'), $child);
                continue;
            }
            if (!array_key_exists($tag, self::ALLOWED)) {
                // Se conserva el contenido, se quita la etiqueta
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a') {
                $href = trim((string)$child->getAttribute('href'));
                if ($href !== '' && !preg_match('#^(https?://|mailto:)#i', $href)) {
                    $child->removeAttribute('href');
                }
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
                $child->setAttribute('target', '_blank');
            }
            if (in_array($tag, ['td', 'th'], true)) {
                foreach (['colspan', 'rowspan'] as $a) {
                    if ($child->hasAttribute($a) && !ctype_digit($child->getAttribute($a))) {
                        $child->removeAttribute($a);
                    }
                }
            }
        }
    }

    /** Texto plano a partir de HTML (para correos sin parte de texto). */
    public static function toText(string $html): string
    {
        $html = (string)preg_replace('#<(br|/p|/div|/tr|/li|/h\d)[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags((string)preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace("/[ \t]+/", ' ', $text);
        return trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
