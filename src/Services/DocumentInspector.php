<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates uploaded documents (PDF, PPTX, DOCX) before they are stored or sent to a network.
 *
 * Safety rules:
 *  - type is decided by file content (magic bytes / package structure), never by the file name
 *  - PDF: no JavaScript, launch actions, embedded files or rich-media; not encrypted
 *  - PPTX/DOCX: Office Open XML only; no macros (vbaProject.bin, *.docm/*.pptm content types),
 *    no ActiveX or embedded OLE objects, no external relationship targets; zip-bomb limits
 *  - legacy binary .doc/.ppt and macro-enabled formats are refused (common malware carriers)
 *
 * NextaPost never renders or converts documents on the server, so no PDF/Office parser
 * (e.g. Ghostscript, LibreOffice) is ever exposed to untrusted files.
 */
final class DocumentInspector
{
    public const PDF = 'application/pdf';
    public const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const MAX_ZIP_ENTRIES = 5000;
    private const MAX_UNCOMPRESSED = 300 * 1024 * 1024;
    private const MAX_RATIO = 200;

    /**
     * @return array{mime:string, ext:string, pages:?int}
     * @throws \InvalidArgumentException with a user-facing reason
     */
    public static function inspect(string $path, string $originalName): array
    {
        $fh = fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 8) : '';
        if ($fh) {
            fclose($fh);
        }
        if (str_starts_with($head, '%PDF-')) {
            return ['mime' => self::PDF, 'ext' => 'pdf', 'pages' => self::inspectPdf($path)];
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            return self::inspectOoxml($path);
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new \InvalidArgumentException(__('Old .doc/.ppt files aren’t accepted because they can hide macros. Save it as PDF, PPTX or DOCX and upload again.'));
        }
        throw new \InvalidArgumentException(__('Upload documents as PDF, PPTX or DOCX.'));
    }

    private static function inspectPdf(string $path): int
    {
        $data = (string) file_get_contents($path);
        // Objects can hide inside compressed object streams: inflate them (bounded) and scan those too.
        $scan = $data;
        $budget = 64 * 1024 * 1024;
        if (preg_match_all('#/FlateDecode[^>]*>>\s*stream\r?\n#s', $data, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$hdr, $off]) {
                $start = $off + strlen($hdr);
                $end = strpos($data, 'endstream', $start);
                if ($end === false || $budget <= 0) {
                    break;
                }
                $raw = @gzuncompress(substr($data, $start, $end - $start), $budget);
                if (is_string($raw)) {
                    $budget -= strlen($raw);
                    $scan .= "\n" . $raw;
                }
            }
        }
        // Names may be hex-escaped (/J#61vaScript) to dodge filters — normalise first.
        $norm = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn ($m) => chr((int) hexdec($m[1])), $scan);
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA)\b#', $norm, $m)) {
            throw new \InvalidArgumentException(__('This PDF contains scripts, auto-actions or embedded files ({what}), which aren’t allowed. Export or print it to a fresh PDF and upload again.', ['what' => $m[1]]));
        }
        if (preg_match('#/Encrypt\b#', $norm)) {
            throw new \InvalidArgumentException(__('This PDF is password-protected or encrypted. Save an unprotected copy and upload again.'));
        }
        if (!preg_match('/%%EOF\s*$/', substr($data, -1024))) {
            throw new \InvalidArgumentException(__('This PDF appears to be incomplete or damaged.'));
        }
        $pages = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $norm);
        if ($pages < 1) {
            // Compressed object streams hide the page tree; fall back to the /Count of the page tree root.
            $pages = preg_match_all('#/Count\s+(\d+)#', $norm, $mm) ? max(array_map('intval', $mm[1])) : 0;
        }
        return max(1, (int) $pages);
    }

    /** @return array{mime:string, ext:string, pages:?int} */
    private static function inspectOoxml(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \InvalidArgumentException(__('The server can’t check Office files (PHP zip extension missing). Upload a PDF instead.'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException(__('This Office file appears to be damaged.'));
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new \InvalidArgumentException(__('This file has too many parts to be a normal document.'));
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if (!$st) {
                    continue;
                }
                $name = (string) $st['name'];
                $names[] = $name;
                $total += (int) $st['size'];
                if ((int) $st['comp_size'] > 0 && (int) $st['size'] / (int) $st['comp_size'] > self::MAX_RATIO && (int) $st['size'] > 10 * 1024 * 1024) {
                    throw new \InvalidArgumentException(__('This file is compressed suspiciously heavily and was refused.'));
                }
                if (preg_match('#(^|/)vbaProject\.bin$|(^|/)activeX/|(^|/)embeddings/.*\.(bin|exe|dll|js|vbs|bat|cmd|ps1|jar|hta)$#i', $name)) {
                    throw new \InvalidArgumentException(__('This file contains macros or embedded programs, which aren’t allowed. Save it as PPTX/DOCX without macros, or as PDF.'));
                }
            }
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new \InvalidArgumentException(__('This document expands to more than 300 MB and was refused.'));
            }
            $ct = $zip->getFromName('[Content_Types].xml');
            if ($ct === false) {
                throw new \InvalidArgumentException(__('Upload documents as PDF, PPTX or DOCX.'));
            }
            if (stripos($ct, 'macroEnabled') !== false || stripos($ct, 'vbaProject') !== false) {
                throw new \InvalidArgumentException(__('Macro-enabled Office files (.pptm/.docm) aren’t allowed. Save as PPTX/DOCX or PDF.'));
            }
            // External links (remote templates/images) can leak viewers' IPs or pull in remote content.
            foreach ($names as $name) {
                if (str_ends_with($name, '.rels')) {
                    $rels = (string) $zip->getFromName($name);
                    if (preg_match('/TargetMode\s*=\s*"External"[^>]*Type="[^"]*(attachedTemplate|oleObject|frame|subDocument)"|Type="[^"]*(attachedTemplate|oleObject|frame|subDocument)"[^>]*TargetMode\s*=\s*"External"/i', $rels)) {
                        throw new \InvalidArgumentException(__('This document loads content from an external address, which isn’t allowed. Save a self-contained copy.'));
                    }
                }
            }
            if ($zip->locateName('ppt/presentation.xml') !== false && str_contains($ct, 'presentationml.presentation.main+xml')) {
                $slides = count(array_filter($names, static fn ($n) => (bool) preg_match('#^ppt/slides/slide\d+\.xml$#', $n)));
                return ['mime' => self::PPTX, 'ext' => 'pptx', 'pages' => max(1, $slides)];
            }
            if ($zip->locateName('word/document.xml') !== false && str_contains($ct, 'wordprocessingml.document.main+xml')) {
                $app = (string) $zip->getFromName('docProps/app.xml');
                $pages = preg_match('#<Pages>(\d+)</Pages>#', $app, $m) ? (int) $m[1] : null;
                return ['mime' => self::DOCX, 'ext' => 'docx', 'pages' => $pages];
            }
            throw new \InvalidArgumentException(__('Upload documents as PDF, PPTX or DOCX.'));
        } finally {
            $zip->close();
        }
    }
}
