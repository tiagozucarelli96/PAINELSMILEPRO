<?php
// OCR provider interface

interface OcrProviderInterface {
    /**
     * @param array $files Array of file arrays (tmp_name, name, type, size).
     * @return array{ text: string, lines: array, pages: int }
     */
    public function extractText(array $files): array;
}

class OcrException extends Exception {
}
