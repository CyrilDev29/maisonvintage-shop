<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Article;
use App\Entity\ArticleImage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events as VichEvents;
use Vich\UploaderBundle\Storage\StorageInterface;

#[AsEventListener(event: VichEvents::POST_UPLOAD, method: 'onPostUpload')]
final class ResizeUploadedImageSubscriber
{
    public function __construct(private readonly StorageInterface $storage) {}

    public function onPostUpload(Event $event): void
    {
        $object = $event->getObject();

        // gère l'image principale (Article::imageFile) et la galerie (ArticleImage::file)
        $field = match (true) {
            $object instanceof Article      => 'imageFile',
            $object instanceof ArticleImage => 'file',
            default                         => null,
        };
        if ($field === null) {
            return;
        }

        $path = $this->storage->resolvePath($object, $field);
        if (!$path || !is_file($path)) {
            return;
        }

        // Détecter le mime
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($path);

        // Conversion HEIC/HEIF -> JPEG si Imagick est disponible
        if (\in_array($mime, ['image/heic', 'image/heif'], true) && \class_exists(\Imagick::class)) {
            $this->convertHeicToJpeg($path);
            // redétection après conversion
            $mime = (string) $finfo->file($path);
        }

        $this->resizeAndCompress($path, $mime, maxSize: 1600, quality: 80);
    }

    /**
     * Convertit un fichier HEIC/HEIF en JPEG en réécrivant le fichier (si possible).
     */
    private function convertHeicToJpeg(string &$path): void
    {
        try {
            $img = new \Imagick($path);
            $img->setImageFormat('jpeg');
            $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality(85);
            $img->stripImage();

            $newPath = preg_replace('/\.[^.]+$/', '.jpg', $path) ?: ($path . '.jpg');
            $img->writeImage($newPath);
            $img->destroy();

            // Remplacer l’original
            @unlink($path);
            $path = $newPath;
        } catch (\Throwable) {
            // Si la conversion échoue, on laisse le fichier tel quel.
        }
    }

    /**
     * Redimensionne (max $maxSize px) et compresse selon le type.
     */
    private function resizeAndCompress(string $path, string $mime, int $maxSize = 1600, int $quality = 80): void
    {
        [$width, $height] = getimagesize($path) ?: [0, 0];
        if ($width <= 0 || $height <= 0) {
            return;
        }

        // Calcul des dimensions cibles
        $targetW = $width;
        $targetH = $height;
        if ($width > $maxSize || $height > $maxSize) {
            $ratio = $width / $height;
            if ($ratio >= 1) {
                $targetW = $maxSize;
                $targetH = (int) round($maxSize / $ratio);
            } else {
                $targetH = $maxSize;
                $targetW = (int) round($maxSize * $ratio);
            }
        }

        $src = $this->createImageResource($path, $mime);
        if ($src === null) {
            return; // type non supporté par GD
        }

        $dst = imagecreatetruecolor($targetW, $targetH);

        // Préserver la transparence pour PNG/GIF
        if (\in_array($mime, ['image/png', 'image/gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        // Écriture compressée selon le mime
        $this->writeImage($dst, $path, $mime, $quality);

        imagedestroy($src);
        imagedestroy($dst);
    }

    /**
     * Crée une ressource GD à partir du fichier selon son MIME, ou null si non supporté.
     */
    private function createImageResource(string $path, string $mime): \GdImage|null
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path) ?: null,
            'image/png'  => imagecreatefrompng($path)  ?: null,
            'image/gif'  => imagecreatefromgif($path)  ?: null,
            'image/webp' => \function_exists('imagecreatefromwebp') ? (imagecreatefromwebp($path) ?: null) : null,
            default      => null,
        };
    }

    /**
     * Écrit l’image sur disque selon le type, avec compression.
     */
    private function writeImage(\GdImage $img, string $path, string $mime, int $quality): void
    {
        switch ($mime) {
            case 'image/jpeg':
                imagejpeg($img, $path, $quality); // 0-100
                break;

            case 'image/png':
                // 0 (sans compression) à 9 (max compression)
                // 6 est un bon compromis
                imagepng($img, $path, 6);
                break;

            case 'image/webp':
                if (\function_exists('imagewebp')) {
                    imagewebp($img, $path, $quality); // 0-100
                }
                break;

            case 'image/gif':
                imagegif($img, $path);
                break;

            default:
                // Type non supporté en écriture : on ne touche pas au fichier
                break;
        }
    }
}
