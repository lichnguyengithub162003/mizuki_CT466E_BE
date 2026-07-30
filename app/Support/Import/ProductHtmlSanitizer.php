<?php

namespace App\Support\Import;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

class ProductHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'ul',
        'ol',
        'li',
        'h2',
        'h3',
        'h4',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
    ];

    /** @var list<string> */
    private const REMOVED_WITH_CONTENT = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
    ];

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="mizuki-import-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('mizuki-import-root');

        if (! $root instanceof DOMElement) {
            return null;
        }

        $this->sanitizeChildren($root);
        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::REMOVED_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeChildren($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }

                $parent->removeChild($child);

                continue;
            }

            while ($child->attributes->length > 0) {
                $attribute = $child->attributes->item(0);

                if ($attribute !== null) {
                    $child->removeAttributeNode($attribute);
                }
            }
        }
    }
}
