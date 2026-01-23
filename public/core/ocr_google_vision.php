<?php
require_once __DIR__ . '/ocr_provider.php';

class GoogleVisionOcrProvider implements OcrProviderInterface {
    private string $apiKey;

    public function __construct() {
        $this->apiKey = $_ENV['GOOGLE_CLOUD_VISION_API_KEY'] ?? getenv('GOOGLE_CLOUD_VISION_API_KEY') ?: '';
        if ($this->apiKey === '') {
            throw new OcrException('Chave da API do Google Vision não configurada. Defina GOOGLE_CLOUD_VISION_API_KEY.');
        }
    }

    public function extractText(array $files): array {
        $allText = [];
        $allLines = [];
        $totalPages = 0;

        foreach ($files as $file) {
            if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
                continue;
            }

            $mimeType = $file['type'] ?? mime_content_type($file['tmp_name']);
            $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

            if ($mimeType === 'application/pdf' || $extension === 'pdf') {
                $images = $this->convertPdfToImages($file['tmp_name']);
                $totalPages += count($images);
                foreach ($images as $imagePath) {
                    $result = $this->detectTextFromImage($imagePath);
                    if (!empty($result['text'])) {
                        $allText[] = $result['text'];
                        $allLines = array_merge($allLines, $result['lines']);
                    }
                }
                $this->cleanupTempFiles($images);
                continue;
            }

            $totalPages += 1;
            $result = $this->detectTextFromImage($file['tmp_name']);
            if (!empty($result['text'])) {
                $allText[] = $result['text'];
                $allLines = array_merge($allLines, $result['lines']);
            }
        }

        $mergedText = trim(implode("\n", $allText));
        return [
            'text' => $mergedText,
            'lines' => $allLines,
            'pages' => $totalPages,
        ];
    }

    private function detectTextFromImage(string $imagePath): array {
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new OcrException('Falha ao ler imagem para OCR.');
        }

        $base64 = base64_encode($content);

        $payload = [
            'requests' => [[
                'image' => ['content' => $base64],
                'features' => [[
                    'type' => 'DOCUMENT_TEXT_DETECTION',
                ]],
            ]],
        ];

        $response = $this->postJson('https://vision.googleapis.com/v1/images:annotate', $payload);
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new OcrException('Resposta inválida do OCR.');
        }

        $annotation = $data['responses'][0]['fullTextAnnotation']['text'] ?? '';
        $text = is_string($annotation) ? trim($annotation) : '';
        $lines = $text !== '' ? preg_split('/\r\n|\r|\n/', $text) : [];

        return [
            'text' => $text,
            'lines' => $lines,
        ];
    }

    private function postJson(string $url, array $payload): string {
        $json = json_encode($payload);
        if ($json === false) {
            throw new OcrException('Falha ao preparar requisição OCR.');
        }

        $finalUrl = $url . '?key=' . urlencode($this->apiKey);
        $ch = curl_init($finalUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            error_log('[OCR] cURL error: ' . $error);
            throw new OcrException('Erro na requisição OCR: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            $bodySnippet = is_string($response) ? substr($response, 0, 500) : '';
            error_log('[OCR] HTTP ' . $status . ' - url: ' . $finalUrl . ' body: ' . $bodySnippet);
            $msg = 'OCR retornou HTTP ' . $status;
            if ($bodySnippet) {
                $msg .= ' - ' . $bodySnippet;
            }
            throw new OcrException($msg);
        }

        return $response ?: '';
    }

    private function convertPdfToImages(string $pdfPath): array {
        $images = [];
        $tmpDir = sys_get_temp_dir() . '/cartao_ofx_' . uniqid();
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new OcrException('Falha ao criar diretório temporário para OCR.');
        }

        if (extension_loaded('imagick')) {
            $imagick = new Imagick();
            $imagick->setResolution(200, 200);
            $imagick->readImage($pdfPath);
            $index = 0;
            foreach ($imagick as $page) {
                $page->setImageFormat('png');
                $output = $tmpDir . '/page_' . $index . '.png';
                $page->writeImage($output);
                $images[] = $output;
                $index++;
            }
            $imagick->clear();
            $imagick->destroy();
            return $images;
        }

        $pdftoppm = trim((string)shell_exec('command -v pdftoppm'));
        if ($pdftoppm !== '') {
            $outputPrefix = $tmpDir . '/page';
            $command = sprintf('%s -png %s %s',
                escapeshellcmd($pdftoppm),
                escapeshellarg($pdfPath),
                escapeshellarg($outputPrefix)
            );
            shell_exec($command);
            $generated = glob($tmpDir . '/page-*.png') ?: [];
            foreach ($generated as $file) {
                $images[] = $file;
            }
            if (!empty($images)) {
                return $images;
            }
        }

        throw new OcrException('OCR de PDF requer Imagick ou pdftoppm instalado.');
    }

    private function cleanupTempFiles(array $files): void {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (!empty($files)) {
            $dir = dirname($files[0]);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }
}
