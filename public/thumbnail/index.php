<?php

require __DIR__ . '/../../vendor/autoload.php';

use Directus\Util\ArrayUtils;
use Directus\Filesystem\Thumbnailer;

$basePath = realpath(__DIR__ . '/../../');
// Get Environment name
$env = get_api_env_from_request();

try {
    $app = create_app_with_env($basePath, $env);
} catch (\Exception $e) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => [
            'error' => 8,
            'message' => 'API Environment Configuration Not Found: ' . $env
        ]
    ]);
    exit;
}

$settings = get_kv_directus_settings('thumbnail');
try {
    // if the thumb already exists, return it
    $thumbnailer = new Thumbnailer(
        $env,
        $app->getContainer()->get('filesystem'),
        $app->getContainer()->get('filesystem_thumb'),
        $settings,
        get_virtual_path()
    );

    $image = $thumbnailer->get();

    if (!$image) {
        // now we can create the thumb
        switch ($thumbnailer->action) {
            // http://image.intervention.io/api/resize
            case 'contain':
                $image = $thumbnailer->contain();
                break;
            // http://image.intervention.io/api/fit
            case 'crop':
            default:
                $image = $thumbnailer->crop();
        }
    }

    header('HTTP/1.1 200 OK');
    header('Content-type: ' . $thumbnailer->getThumbnailMimeType());
    header("Pragma: cache");
    header('Cache-Control: max-age=86400');
    header('Last-Modified: '. gmdate('D, d M Y H:i:s \G\M\T', time()));
    header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
    echo $image;
    exit(0);
}

catch (Exception $e) {
    $filePath = ArrayUtils::get($settings, 'not_found_location');
    if (is_string($filePath) && !empty($filePath) && $filePath[0] !== '/') {
        $filePath = $basePath . '/' . $filePath;
    }

    // TODO: Throw message if the error is a invalid configuration
    if (file_exists($filePath)) {
        $mime = image_type_to_mime_type(exif_imagetype($filePath));

        // TODO: Do we need to cache non-existing files?
        header('Content-type: ' . $mime);
        header("Pragma: cache");
        header('Cache-Control: max-age=86400');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', time()));
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
        echo file_get_contents($filePath);
    } else {
        http_response_code(404);
    }

    exit(0);
}
