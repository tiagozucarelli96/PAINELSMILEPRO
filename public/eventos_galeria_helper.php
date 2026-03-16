<?php
/**
 * Helper compartilhado da galeria de eventos.
 */

function eventosGaleriaThumbColumns(PDO $pdo): array
{
    static $cache = [];

    $cacheKey = spl_object_hash($pdo);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $result = [
        'thumb_storage_key' => false,
        'thumb_public_url' => false,
    ];

    try {
        $stmt = $pdo->query("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = 'eventos_galeria'
              AND table_schema = ANY(current_schemas(false))
              AND column_name IN ('thumb_storage_key', 'thumb_public_url')
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $columnName) {
            if (isset($result[$columnName])) {
                $result[$columnName] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[EVENTOS_GALERIA] Falha ao detectar colunas de thumbnail: ' . $e->getMessage());
    }

    $result['ready'] = $result['thumb_storage_key'] && $result['thumb_public_url'];
    $cache[$cacheKey] = $result;

    return $result;
}

function eventosGaleriaStoragePublicUrl(string $storageKey): ?string
{
    $storageKey = ltrim(trim($storageKey), '/');
    if ($storageKey === '') {
        return null;
    }

    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';

    return rtrim((string)$endpoint, '/') . '/' . strtolower((string)$bucket) . '/' . $storageKey;
}

function eventosGaleriaDownloadSourceToTemp(string $url): string
{
    if ($url === '') {
        throw new InvalidArgumentException('URL de origem vazia.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'gal_src_');
    if ($tmpPath === false) {
        throw new RuntimeException('Falha ao criar arquivo temporario para download.');
    }

    if (function_exists('curl_init')) {
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            @unlink($tmpPath);
            throw new RuntimeException('Falha ao abrir arquivo temporario para download.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FAILONERROR => false,
            CURLOPT_USERAGENT => 'SmileEventosGaleria/1.0',
        ]);
        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $httpCode >= 400) {
            @unlink($tmpPath);
            throw new RuntimeException('Falha ao baixar arquivo fonte. HTTP ' . $httpCode . ($error !== '' ? ' - ' . $error : ''));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'ignore_errors' => true,
                'user_agent' => 'SmileEventosGaleria/1.0',
            ],
        ]);
        $contents = @file_get_contents($url, false, $context);
        if ($contents === false || file_put_contents($tmpPath, $contents) === false) {
            @unlink($tmpPath);
            throw new RuntimeException('Falha ao baixar arquivo fonte sem cURL.');
        }
    }

    if (!file_exists($tmpPath) || filesize($tmpPath) <= 0) {
        @unlink($tmpPath);
        throw new RuntimeException('Arquivo fonte baixado esta vazio.');
    }

    return $tmpPath;
}

function eventosGaleriaDetectMimeLocal(string $path): string
{
    if ($path === '' || !file_exists($path)) {
        return '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return '';
    }

    $mime = (string)(finfo_file($finfo, $path) ?: '');
    finfo_close($finfo);

    return $mime;
}

function eventosGaleriaAutoOrientGd($image, string $sourcePath, string $mimeType)
{
    if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($sourcePath);
    $orientation = (int)($exif['Orientation'] ?? 1);
    switch ($orientation) {
        case 3:
            $rotated = imagerotate($image, 180, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
        case 6:
            $rotated = imagerotate($image, -90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
        case 8:
            $rotated = imagerotate($image, 90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
    }

    return $image;
}

function eventosGaleriaCreateThumbnailWithImagick(string $sourcePath, int $maxDimension = 640, int $quality = 82): array
{
    if (!class_exists('Imagick')) {
        throw new RuntimeException('Imagick indisponivel.');
    }

    $img = new Imagick();
    $img->readImage($sourcePath);
    if (method_exists($img, 'setIteratorIndex')) {
        @$img->setIteratorIndex(0);
    }
    if (method_exists($img, 'autoOrient')) {
        @$img->autoOrient();
    }

    $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $img->setImageBackgroundColor('white');
    $img->thumbnailImage($maxDimension, $maxDimension, true, true);
    $img->setImageFormat('jpeg');
    if (defined('Imagick::COMPRESSION_JPEG')) {
        $img->setImageCompression(Imagick::COMPRESSION_JPEG);
    }
    $img->setImageCompressionQuality(max(60, min(92, $quality)));
    if (method_exists($img, 'stripImage')) {
        $img->stripImage();
    }
    if (method_exists($img, 'setInterlaceScheme') && defined('Imagick::INTERLACE_JPEG')) {
        $img->setInterlaceScheme(Imagick::INTERLACE_JPEG);
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'gal_thumb_');
    if ($tmpBase === false) {
        throw new RuntimeException('Falha ao criar arquivo temporario da thumbnail.');
    }
    @unlink($tmpBase);
    $thumbPath = $tmpBase . '.jpg';

    $img->writeImage($thumbPath);
    $width = $img->getImageWidth();
    $height = $img->getImageHeight();
    $img->clear();
    $img->destroy();

    return [
        'path' => $thumbPath,
        'mime_type' => 'image/jpeg',
        'size_bytes' => (int)(filesize($thumbPath) ?: 0),
        'width' => (int)$width,
        'height' => (int)$height,
        'extension' => 'jpg',
    ];
}

function eventosGaleriaCreateThumbnailWithGd(string $sourcePath, int $maxDimension = 640, int $quality = 82): array
{
    if (!function_exists('getimagesize')) {
        throw new RuntimeException('GD indisponivel.');
    }

    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) {
        throw new RuntimeException('Nao foi possivel ler as dimensoes da imagem.');
    }

    $mimeType = (string)($imageInfo['mime'] ?? '');
    switch ($mimeType) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new RuntimeException('GD sem suporte a WEBP.');
            }
            $source = @imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new RuntimeException('Tipo de imagem nao suportado para thumbnail: ' . $mimeType);
    }

    if (!$source) {
        throw new RuntimeException('Falha ao abrir imagem fonte para thumbnail.');
    }

    $source = eventosGaleriaAutoOrientGd($source, $sourcePath, $mimeType);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    $scale = min($maxDimension / max(1, $sourceWidth), $maxDimension / max(1, $sourceHeight), 1);
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    imageinterlace($target, true);

    $tmpBase = tempnam(sys_get_temp_dir(), 'gal_thumb_');
    if ($tmpBase === false) {
        imagedestroy($source);
        imagedestroy($target);
        throw new RuntimeException('Falha ao criar arquivo temporario da thumbnail.');
    }
    @unlink($tmpBase);
    $thumbPath = $tmpBase . '.jpg';

    if (!imagejpeg($target, $thumbPath, max(60, min(92, $quality)))) {
        imagedestroy($source);
        imagedestroy($target);
        throw new RuntimeException('Falha ao gravar thumbnail em JPEG.');
    }

    imagedestroy($source);
    imagedestroy($target);

    return [
        'path' => $thumbPath,
        'mime_type' => 'image/jpeg',
        'size_bytes' => (int)(filesize($thumbPath) ?: 0),
        'width' => $targetWidth,
        'height' => $targetHeight,
        'extension' => 'jpg',
    ];
}

function eventosGaleriaCreateThumbnail(string $sourcePath, int $maxDimension = 640, int $quality = 82): array
{
    if ($sourcePath === '' || !file_exists($sourcePath)) {
        throw new InvalidArgumentException('Arquivo fonte da thumbnail nao encontrado.');
    }

    try {
        return eventosGaleriaCreateThumbnailWithImagick($sourcePath, $maxDimension, $quality);
    } catch (Throwable $e) {
        error_log('[EVENTOS_GALERIA] Thumbnail via Imagick indisponivel: ' . $e->getMessage());
    }

    return eventosGaleriaCreateThumbnailWithGd($sourcePath, $maxDimension, $quality);
}

function eventosGaleriaUploadThumbnail(
    MagaluUpload $uploader,
    string $sourcePath,
    string $originalName = 'imagem.jpg',
    int $maxDimension = 640
): array {
    $thumbMeta = eventosGaleriaCreateThumbnail($sourcePath, $maxDimension);
    $thumbPath = $thumbMeta['path'];
    $baseName = trim((string)pathinfo($originalName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'imagem';
    }
    $thumbFileName = $baseName . '.' . ($thumbMeta['extension'] ?? 'jpg');

    try {
        $upload = $uploader->uploadFromPath(
            $thumbPath,
            'galeria_eventos/thumbs',
            $thumbFileName,
            (string)($thumbMeta['mime_type'] ?? 'image/jpeg')
        );

        return [
            'storage_key' => (string)($upload['chave_storage'] ?? ''),
            'public_url' => (string)($upload['url'] ?? ''),
            'size_bytes' => (int)($upload['tamanho_bytes'] ?? ($thumbMeta['size_bytes'] ?? 0)),
        ];
    } finally {
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }
}
