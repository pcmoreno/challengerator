<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DriveImageController extends AbstractController
{
    private const CACHE_TTL    = 86400 * 30;
    private const MAX_BYTES    = 5 * 1024 * 1024;

    public function __construct(
        private readonly string $cacheDir,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function serve(string $fileId): Response
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $fileId)) {
            throw new NotFoundHttpException();
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }

        $cachePath = $this->cacheDir . '/' . $fileId;

        if (!file_exists($cachePath)) {
            $url = 'https://drive.google.com/thumbnail?sz=w1200&id=' . $fileId;

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 5]);
                $contents = $response->getContent();
            } catch (TransportExceptionInterface | HttpExceptionInterface) {
                throw new NotFoundHttpException('Image not available');
            }

            if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
                throw new NotFoundHttpException('Image not available');
            }

            if (@getimagesizefromstring($contents) === false) {
                throw new NotFoundHttpException('Image not available');
            }

            $tmp = $cachePath . '.tmp';
            file_put_contents($tmp, $contents);
            rename($tmp, $cachePath);
        } else {
            $contents = file_get_contents($cachePath);
        }

        $mimeType = @getimagesizefromstring($contents)['mime'] ?? 'image/jpeg';

        return new Response(
            $contents,
            200,
            [
                'Content-Type'  => $mimeType,
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
                'Expires'       => gmdate('D, d M Y H:i:s', time() + self::CACHE_TTL) . ' GMT',
            ]
        );
    }
}
