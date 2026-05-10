<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DriveImageController extends AbstractController
{
    private const CACHE_TTL = 86400 * 30; // 30 days

    public function __construct(private readonly string $cacheDir) {}

    public function serve(string $fileId): Response
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $fileId)) {
            throw new NotFoundHttpException();
        }

        $cachePath = $this->cacheDir . '/' . $fileId;

        if (!file_exists($cachePath)) {
            $url      = 'https://drive.google.com/thumbnail?sz=w1200&id=' . $fileId;
            $contents = @file_get_contents($url);
            if ($contents === false || strlen($contents) === 0) {
                throw new NotFoundHttpException('Image not available');
            }
            file_put_contents($cachePath, $contents);
        }

        $mimeType = mime_content_type($cachePath) ?: 'image/jpeg';

        return new Response(
            file_get_contents($cachePath),
            200,
            [
                'Content-Type'  => $mimeType,
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
                'Expires'       => gmdate('D, d M Y H:i:s', time() + self::CACHE_TTL) . ' GMT',
            ]
        );
    }
}
