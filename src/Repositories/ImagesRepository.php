<?php

namespace OffbeatWP\Images\Repositories;

use OffbeatWP\Images\Helpers\ImageHelper;
use WP_Error;

final class ImagesRepository
{
    public const UPLOAD_FOLDER = 'odi';

    /** @return array{width: int, height: int, crop: bool}[] */
    public function getOnDemandImageSizes(): array
    {
        $onDemandSizes = [];

        $sizes = wp_get_registered_image_subsizes();

        foreach ($sizes as $sizeKey => $size) {
            if (preg_match('/^\*(.*)$/', $sizeKey, $match)) {
                $onDemandSizes[$sizeKey] = $size;
                $onDemandSizes[$match[1]] = $size;
            }
        }

        $onDemandImageSizeKeys = apply_filters('image-sizes-on-demand/enable', ['large', 'medium']);

        foreach ($onDemandImageSizeKeys as $onDemandImageSizeKey) {
            if (isset($sizes[$onDemandImageSizeKey])) {
                $onDemandSizes[$onDemandImageSizeKey] = $sizes[$onDemandImageSizeKey];
            }
        }

        return $onDemandSizes;
    }

    /** @return array{path: string, url: string}|null */
    public static function getUploadDir(string $relativePath = '', bool $create = true): ?array
    {
        $wpUploadDir = wp_upload_dir();

        if (!$wpUploadDir) {
            return null;
        }

        if ($relativePath) {
            $relativePath = untrailingslashit(preg_replace('/^\/(.*)/', '$1', $relativePath));
        }

        $path = untrailingslashit($wpUploadDir['basedir']) . '/' . self::UPLOAD_FOLDER . '/' . $relativePath;
        $url = untrailingslashit($wpUploadDir['baseurl']) . '/' . self::UPLOAD_FOLDER . '/' . $relativePath;

        if ($create) {
            wp_mkdir_p($path);
        }

        return [
            'path' => $path,
            'url' => $url
        ];
    }

    /** @return array{width: int, height: int, crop: bool}|null */
    public function getSizeDefinition(string $size): ?array
    {
        $sizes = $this->getOnDemandImageSizes();

        if (!empty($sizes[$size])) {
            return $sizes[$size];
        }

        return null;
    }

    /** @return array{width: int, height: int, path: string, url: string}|null */
    public function getImage(int $attachmentId, string $size): ?array
    {
        $meta = wp_get_attachment_metadata($attachmentId);

        if (!is_array($meta) || empty($meta['file'])) {
            return null;
        }

        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType && strpos($mimeType, 'svg') !== false) {
            return null;
        }

        $imageSizeDefiniton = $this->getSizeDefinition($size);
        if (!$imageSizeDefiniton) {
            if (!preg_match('/^\*(?<width>\d+)x(?<height>\d+)(?<crop>c)?(\/(?<density>[0-9])x)?$/', $size, $matches) || !is_numeric($matches[1]) || !is_numeric($matches[2])) {
                return null;
            }

            $width = (int)$matches['width'];
            $height = (int)$matches['height'];
            $density = (isset($matches['density']) && is_numeric($matches['density'])) ? $matches['density'] : 1;

            if ($density > 1) {
                $width *= $density;
                $height *= $density;
            }

            $imageSizeDefiniton = [
                'width' => $width,
                'height' => $height,
                'crop' => isset($matches['crop']) && $matches['crop'] === 'c',
            ];
        }

        $imagePathInfo = pathinfo($meta['file']);
        if (!$imagePathInfo) {
            return null;
        }

        $uploadDir = static::getUploadDir($imagePathInfo['dirname']);
        if (!$uploadDir) {
            return null;
        }

        $imageFilename = $imagePathInfo['filename'] . '-' . $imageSizeDefiniton['width'] . 'x' . $imageSizeDefiniton['height'] . '.' . $imagePathInfo['extension'];
        $imagePath = $uploadDir['path'] . '/' . $imageFilename;

        // TODO: generateImage might return a WP_ERROR. What to do in this case?
        if (!file_exists($imagePath) && !$this->generateImage($attachmentId, $imageSizeDefiniton, $imagePath)) {
            return null;
        }

        $imageInfo = wp_getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'path' => $imagePath,
            'url' => $uploadDir['url'] . '/' . $imageFilename
        ];
    }

    /**
     * @param int $attachmentId
     * @param null|string|int|float $aspectRatio
     * @return array{width: int, height: int, path: string, url: string}|null
     */
    public function getMaxImage(int $attachmentId, $aspectRatio = null): ?array
    {
        $imageModifier = $aspectRatio ? 'c' : '';
        $fullImageMeta = wp_get_attachment_metadata($attachmentId);

        if (!$fullImageMeta) {
            return null;
        }

        $maxImageWidth = $fullImageMeta['width'];
        $maxImageHeight = $this->getOriginalImageHeight($attachmentId);

        if ($aspectRatio) {
            $maxImageHeight = floor($fullImageMeta['width'] / ImageHelper::calculateAspectRatio($aspectRatio, $attachmentId));

            if ($maxImageHeight > $fullImageMeta['height']) {
                $maxImageWidth = floor($fullImageMeta['width'] * ($fullImageMeta['height'] / $maxImageHeight));
                $maxImageHeight = $fullImageMeta['height'];
            }
        }

        $image = offbeat('images')->getImage($attachmentId , "*{$maxImageWidth}x{$maxImageHeight}{$imageModifier}");

        return $image; 
    }

    public function getOriginalImageHeight(int $attachmentId): ?int
    {
        $fullImageMeta = wp_get_attachment_metadata($attachmentId);

        if ($fullImageMeta) {
            return $fullImageMeta['height'];
        }

        return null;
    }

    /**
     * @param int $attachmentId
     * @param array{width: int, height: int, crop: bool} $size
     * @param string $destinationPath
     * @return array{path: string, file: string, width: int, height: int, mime-type: string, filesize: int}|null
     */
    public function generateImage(int $attachmentId, array $size, string $destinationPath): ?array
    {
        $isCrop = $size['crop'] ?? false;
        $originalPath = wp_get_original_image_path($attachmentId);

        if (!$originalPath) {
            trigger_error('Could not find original path of attachment #' . $attachmentId);
            return null;
        }

        list($originalWidth, $originalHeight) = getimagesize($originalPath);

        if ($originalWidth < $size['width'] || $originalHeight < $size['height']) {
            return null;
        }

        $focalpointX = get_post_meta($attachmentId, 'focalpoint_x', true);
        if (!is_numeric($focalpointX)) {
            $focalpointX = 0.5;
        }

        $focalpointY = get_post_meta($attachmentId, 'focalpoint_y', true);
        if (!is_numeric($focalpointY)) {
            $focalpointY = 0.5;
        }

        $imageEditor = wp_get_image_editor($originalPath);
        if (is_wp_error($imageEditor)) {
            trigger_error($imageEditor->get_error_message(), E_USER_WARNING);
            return null;
        }

        if ($isCrop) {
            // sanitize and distribute parameters
            $dst_w = (int)$size['width'];
            $dst_h = (int)$size['height'];
            $focal_x = (float)$focalpointX;
            $focal_y = (float)$focalpointY;

            // maybe replace empty sizes
            if (!$dst_w) {
                $dst_w = $originalWidth;
            }
            if (!$dst_h) {
                $dst_h = $originalHeight;
            }

            // calculate cropped image size
            $src_w = $originalWidth;
            $src_h = $originalHeight;

            if ($originalWidth / $originalHeight > $dst_w / $dst_h) {
                $src_w = round($originalHeight * ($dst_w / $dst_h));
            } else {
                $src_h = round($originalWidth * ($dst_h / $dst_w));
            }

            // calculate focal top left position
            $src_x = $originalWidth * $focal_x - $src_w * $focal_x;
            if ($src_x + $src_w > $originalWidth) {
                $src_x += $originalWidth - $src_w - $src_x;
            }
            if ($src_x < 0) {
                $src_x = 0;
            }
            $src_x = round($src_x);

            $src_y = $originalHeight * $focal_y - $src_h * $focal_y;
            if ($src_y + $src_h > $originalHeight) {
                $src_y += $originalHeight - $src_h - $src_y;
            }
            if ($src_y < 0) {
                $src_y = 0;
            }
            $src_y = round($src_y);

            $imageEditor->crop((int)$src_x, (int)$src_y, (int)$src_w, (int)$src_h, (int)$dst_w, (int)$dst_h);
            $savedImage = $imageEditor->save($destinationPath);

            return $savedImage;
        }

        $imageEditor->resize($size['width'], $size['height'], $size['crop'] ?? true);
        $savedImage = $imageEditor->save($destinationPath);

        if ($savedImage instanceof WP_Error) {
            trigger_error($savedImage->get_error_message());
            return null;
        }

        return $savedImage;
    }

    public function deleteImagesForAttachment(int $attachmentId): bool
    {
        $isImage = wp_attachment_is_image($attachmentId);
        if (!$isImage) {
            return false;
        }

        $meta = wp_get_attachment_metadata($attachmentId);
        if (!is_array($meta)) {
            return false;
        }

        $imagePathInfo = pathinfo($meta['file']);
        if (!$imagePathInfo) {
            return false;
        }

        $uploadDir = static::getUploadDir($imagePathInfo['dirname']);
        if (!$uploadDir) {
            return false;
        }

        $globResult = glob($uploadDir['path'] . '/' . $imagePathInfo['filename'] . '-*') ?: [];
        foreach ($globResult as $filePath) {
            $filename = basename($filePath);

            if (preg_match('/^' . preg_quote($imagePathInfo['filename'], '/') . '-\d+x\d+\.' . $imagePathInfo['extension'] . '/', $filename)) {
                wp_delete_file($filePath);
            }
        }

        return true;
    }
}
