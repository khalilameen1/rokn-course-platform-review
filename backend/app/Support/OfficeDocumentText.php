<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Field;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table as WordTable;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Reader\Word2007;
use ZipArchive;

/**
 * Plain text of learner-uploaded Office files for AI review.
 *
 * Word documents are read with PhpWord's Word2007 reader (images never
 * loaded). Presentations are read straight from the package in the order
 * ECMA-376 defines: p:sldIdLst in ppt/presentation.xml resolved through
 * ppt/_rels/presentation.xml.rels, then every DrawingML paragraph of each
 * slide. The caller bounds the package before calling anything here.
 */
final class OfficeDocumentText
{
    private const PRESENTATION_NAMESPACE = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const DRAWING_NAMESPACE = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const MARKUP_COMPATIBILITY_NAMESPACE = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    private const SLIDE_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';

    /** Word document body as one paragraph per line, read by PhpWord. */
    public static function word(string $path): string
    {
        /** @var Word2007 $reader */
        $reader = WordIOFactory::createReader('Word2007');
        $reader->setImageLoading(false);
        $document = $reader->load($path);
        $lines = [];
        foreach ($document->getSections() as $section) {
            self::collectWordLines($section, $lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Slide part names in presentation order: p:sldIdLst of
     * ppt/presentation.xml (ECMA-376 Part 1, 19.2.1.33) resolved through the
     * part's relationships (ECMA-376 Part 2, 9.3). Missing parts are dropped.
     *
     * @return list<string>
     */
    public static function slideParts(ZipArchive $zip, int $maxPartBytes): array
    {
        $presentation = self::boundedPart($zip, 'ppt/presentation.xml', $maxPartBytes);
        $relationships = self::boundedPart($zip, 'ppt/_rels/presentation.xml.rels', $maxPartBytes);
        if ($presentation === null || $relationships === null) {
            return [];
        }
        $relationshipDocument = self::dom($relationships);
        $presentationDocument = self::dom($presentation);
        if ($relationshipDocument === null || $presentationDocument === null) {
            return [];
        }
        $targets = [];
        $relationshipPath = new DOMXPath($relationshipDocument);
        $relationshipPath->registerNamespace('pr', self::PACKAGE_RELATIONSHIPS_NAMESPACE);
        foreach ($relationshipPath->query('/pr:Relationships/pr:Relationship') ?: [] as $relationship) {
            if ($relationship instanceof DOMElement
                && $relationship->getAttribute('Type') === self::SLIDE_RELATIONSHIP
                && $relationship->getAttribute('TargetMode') !== 'External') {
                $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }
        }
        $presentationPath = new DOMXPath($presentationDocument);
        $presentationPath->registerNamespace('p', self::PRESENTATION_NAMESPACE);
        $presentationPath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);
        $parts = [];
        foreach ($presentationPath->query('/p:presentation/p:sldIdLst/p:sldId') ?: [] as $slideId) {
            if (! $slideId instanceof DOMElement) {
                continue;
            }
            $target = $targets[$slideId->getAttributeNS(self::RELATIONSHIPS_NAMESPACE, 'id')] ?? null;
            if ($target === null) {
                continue;
            }
            $name = self::resolvePartName('ppt/', $target);
            if ($name !== null && ! in_array($name, $parts, true) && $zip->locateName($name) !== false) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    /**
     * Text of slide parts given as XML in presentation order, one DrawingML
     * paragraph per line. Every a:p of the slide counts, so grouped shapes,
     * tables and runs without properties are included.
     *
     * @param  list<string>  $slideXml
     */
    public static function slideText(array $slideXml): string
    {
        $lines = [];
        foreach ($slideXml as $xml) {
            $document = self::dom($xml);
            if ($document === null) {
                continue;
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', self::DRAWING_NAMESPACE);
            $xpath->registerNamespace('mc', self::MARKUP_COMPATIBILITY_NAMESPACE);
            foreach ($xpath->query('//a:p[not(ancestor::mc:Fallback)]') ?: [] as $paragraph) {
                if (! $paragraph instanceof DOMElement) {
                    continue;
                }
                $line = '';
                foreach ($paragraph->childNodes as $child) {
                    if (! $child instanceof DOMElement || $child->namespaceURI !== self::DRAWING_NAMESPACE) {
                        continue;
                    }
                    if ($child->localName === 'br') {
                        $line .= "\n";
                    } elseif ($child->localName === 'r' || $child->localName === 'fld') {
                        foreach ($child->getElementsByTagNameNS(self::DRAWING_NAMESPACE, 't') as $text) {
                            $line .= $text->textContent;
                        }
                    }
                }
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    /** @param  list<string>  $lines */
    private static function collectWordLines(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof WordTable) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        self::collectWordLines($cell, $lines);
                    }
                }
            } elseif ($element instanceof TextRun) {
                $lines[] = self::wordRunText($element);
            } elseif ($element instanceof ListItem) {
                $lines[] = self::decode((string) $element->getText());
            } elseif ($element instanceof Title) {
                $text = $element->getText();
                $lines[] = $text instanceof TextRun ? self::wordRunText($text) : self::decode((string) $text);
            } elseif ($element instanceof PreserveText) {
                $text = $element->getText();
                $lines[] = self::decode(is_array($text) ? implode('', $text) : (string) $text);
            } elseif ($element instanceof TextBreak || $element instanceof PageBreak) {
                $lines[] = '';
            } elseif ($element instanceof AbstractContainer) {
                self::collectWordLines($element, $lines);
            } else {
                $text = self::wordInlineText($element);
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }
    }

    /** Runs of one paragraph; w:br becomes a line break and w:tab a tab. */
    private static function wordRunText(AbstractContainer $paragraph): string
    {
        $text = '';
        foreach ($paragraph->getElements() as $element) {
            if ($element instanceof TextBreak) {
                $text .= "\n";
            } elseif ($element instanceof AbstractContainer) {
                $text .= self::wordRunText($element);
            } else {
                $text .= self::wordInlineText($element);
            }
        }

        return $text;
    }

    private static function wordInlineText(AbstractElement $element): string
    {
        if ($element instanceof Text || $element instanceof Link) {
            return self::decode((string) $element->getText());
        }
        if ($element instanceof Field) {
            $text = $element->getText();

            return $text instanceof TextRun ? self::wordRunText($text) : self::decode((string) $text);
        }

        return '';
    }

    /** PhpWord stores run text HTML-escaped. */
    private static function decode(string $text): string
    {
        return htmlspecialchars_decode($text, ENT_QUOTES | ENT_HTML5);
    }

    private static function boundedPart(ZipArchive $zip, string $name, int $maxPartBytes): ?string
    {
        $stat = $zip->statName($name);
        if (! is_array($stat) || (int) $stat['size'] <= 0 || (int) $stat['size'] > $maxPartBytes) {
            return null;
        }
        $xml = $zip->getFromName($name);

        return is_string($xml) ? $xml : null;
    }

    private static function dom(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();

            return $loaded ? $document : null;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    /** Resolve a relationship target against its source part's directory. */
    private static function resolvePartName(string $sourceDirectory, string $target): ?string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : $sourceDirectory.$target;
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
