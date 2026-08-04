<?php

namespace App\Services\Rag;

use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Plain-text extraction for RAG ingestion (mvp-scope.md §8, extended to
 * pdf/docx). Chunking/embedding downstream (IngestDocumentJob) only ever
 * deals with plain text — this is the one place that knows how to get
 * plain text out of each supported file format.
 */
class DocumentTextExtractor
{
    /** @var string[] */
    public const SUPPORTED_EXTENSIONS = ['txt', 'md', 'pdf', 'docx'];

    public static function extract(string $absolutePath, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'txt', 'md' => (string) file_get_contents($absolutePath),
            'pdf' => self::extractPdf($absolutePath),
            'docx' => self::extractDocx($absolutePath),
            default => throw new InvalidArgumentException("Unsupported document type: {$extension}"),
        };

        return self::sanitizeUtf8($text);
    }

    /**
     * PDF/DOCX extraction can yield byte sequences that aren't valid UTF-8
     * (e.g. smart quotes/em-dashes surviving from a source encoding the
     * parser didn't fully normalize) — downstream json_encode() (Ollama
     * embedding requests) and Postgres's UTF-8-only text columns both
     * reject that outright, so scrub it here once, at the source, rather
     * than downstream in every consumer.
     */
    private static function sanitizeUtf8(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private static function extractPdf(string $path): string
    {
        $parser = new PdfParser();

        return trim($parser->parseFile($path)->getText());
    }

    private static function extractDocx(string $path): string
    {
        $document = WordIOFactory::load($path, 'Word2007');
        $lines = [];

        foreach ($document->getSections() as $section) {
            self::collectContainerText($section, $lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Walks block-level elements (paragraphs, tables) of a section/cell.
     * A paragraph with mixed formatting (bold/italic spans) is a TextRun
     * containing several inline Text runs — those get flattened into one
     * line by flattenInlineText() rather than one line per formatting span.
     *
     * @param string[] $lines
     */
    private static function collectContainerText(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if (method_exists($element, 'getRows')) {
                // Table: rows -> cells, each cell is itself a container of paragraphs.
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        self::collectContainerText($cell, $lines);
                    }
                }

                continue;
            }

            if ($element instanceof AbstractContainer) {
                $flattened = self::flattenInlineText($element);

                if ($flattened !== '') {
                    $lines[] = $flattened;
                } else {
                    // No direct inline text (e.g. a table nested inside a
                    // cell) — recurse as a block container instead.
                    self::collectContainerText($element, $lines);
                }

                continue;
            }

            if (method_exists($element, 'getText')) {
                $text = $element->getText();

                if (is_string($text) && $text !== '') {
                    $lines[] = $text;
                }
            }
        }
    }

    /**
     * Concatenates a paragraph-like container's own inline runs (Text,
     * bold/italic spans, links, ...) into a single string with no
     * newlines — they're all part of the same paragraph.
     */
    private static function flattenInlineText(AbstractContainer $container): string
    {
        $parts = [];

        foreach ($container->getElements() as $inline) {
            if (method_exists($inline, 'getText')) {
                $text = $inline->getText();

                if (is_string($text) && $text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode('', $parts);
    }
}
