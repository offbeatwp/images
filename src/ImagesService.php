<?php

namespace OffbeatWP\Images;

use OffbeatWP\Images\Helpers\ImageHelper;
use OffbeatWP\Contracts\View;
use OffbeatWP\Images\Hooks\FocalPointInitAction;
use OffbeatWP\Images\Repositories\ImagesRepository;
use OffbeatWP\Services\AbstractService;

final class ImagesService extends AbstractService
{
    /** @var class-string<ImagesRepository>[] */
    public array $bindings = [
        'images' => ImagesRepository::class
    ];

    public function register(View $view): void
    {
        add_filter('image_downsize', function ($out, $attachmentId, $size) {
            if (!is_string($size)) {
                return $out;
            }

            $isImage = wp_attachment_is_image($attachmentId);
            if (!$isImage) {
                return $out;
            }

            $image = offbeat('images')->getImage($attachmentId, $size);

            if ($image) {
                return [$image['url'], $image['width'], $image['height'], true];
            }

            return $out;
        }, 10, 3);

        add_filter('wp_get_attachment_image_attributes', static function ($attr, $attachment, $size) {
            $srcsetList = [];

            if (isset($attr['*srcset']) && is_array($attr['*srcset'])) {
                $attr['*srcset'][] = $size;
                $attr['*srcset'] = array_unique($attr['*srcset']);

                foreach ($attr['*srcset'] as $srcsetSize) {
                    $image = image_downsize($attachment->ID, $srcsetSize);

                    if ($image) {
                        $srcsetList[$image[1]] = "{$image[0]} {$image[1]}w";
                    }
                }

                if ($srcsetList) {
                    ksort($srcsetList);
                    $attr['srcset'] = implode(', ', $srcsetList);
                } else {
                    $attr['srcset'] = null;
                }

                unset($attr['*srcset']);
            }

            return $attr;
        }, 10, 3);

        add_filter('intermediate_image_sizes_advanced', function ($newSizes, $imageMeta, $attachmentId) {
            $onDemandImageSizes = offbeat('images')->getOnDemandImageSizes();

            foreach ($onDemandImageSizes as $onDemandImageSizeKey => $onDemandImageSize) {
                if (isset($newSizes[$onDemandImageSizeKey])) {
                    unset($newSizes[$onDemandImageSizeKey]);
                }
            }

            return $newSizes;
        }, 10, 3);

        add_filter('wp_image_src_get_dimensions', function ($dimensions, $imageSrc, $imageMeta, $attachmentId) {
            // If there are already dimensions, we don't have to get it for this image
            if ($dimensions) {
                return $dimensions;
            }

            $uploadDir = offbeat('images')::getUploadDir();
            
            if (
                !$uploadDir || // If no uploaddir for on demand images, there is no point to continue;
                strpos($imageSrc, $uploadDir['url']) === false // The url must be a ODI url to continue
            ) {
                return $dimensions; 
            }

            $imagePath = str_replace($uploadDir['url'], $uploadDir['path'], $imageSrc);

            // File must exist
            if (!is_file($imagePath )) {
                return $dimensions;
            }

            $imageInfo = wp_getimagesize($imagePath);

            // Can we get the info of the file, if not return initial value
            if (!$imageInfo) {
                return $dimensions;
            }

            return [$imageInfo[0], $imageInfo[1]];
        }, 10, 4);


        add_filter('offbeat/responsiveImage/args', function ($args, $attachmentId) {
            if (!isset($args['lightbox'])) {
                return $args;
            }

            $args['link'] = 'image';

            return $args;
        }, 10, 2);

        add_filter('offbeat/responsiveImage/linkTagAttributes', function ($attributes, $attachmentId, $args) {
            if (!isset($args['lightbox'])) {
                return $attributes;
            }

            if (!empty($args['lightbox-gallery'])) {
                $attributes['data-gall'] = $args['lightbox-gallery'];
            }

            if(!isset($attributes['class'])) {
                $attributes['class'] = '';
            }

            $attributes['class'] .= ' lightbox-link';

            return $attributes;
        }, 10, 3);

        add_filter( 'wp_image_src_get_dimensions', function ($dimensions, $imageSrc, $imageMeta, $attachmentId) {
            // If there are already dimensions, we don't have to get it for this image
            if ($dimensions) {
                return $dimensions;
            }

            $uploadDir = ImagesRepository::getUploadDir();
            
            if (
                !$uploadDir || // If no uploaddir for on demand images, there is no point to continue;
                strpos($imageSrc, $uploadDir['url']) === false // The url must be a ODI url to continue
            ) {
                return $dimensions; 
            }

            $imagePath = str_replace($uploadDir['url'], $uploadDir['path'], $imageSrc);

            // File must exist
            if (!is_file($imagePath )) {
                return $dimensions;
            }

            $imageInfo = wp_getimagesize($imagePath);

            // Can we get the info of the file, if not return initial value
            if (!$imageInfo) {
                return $dimensions;
            }

            return [$imageInfo[0], $imageInfo[1]];
        }, 10, 4);

        offbeat('hooks')->addAction('init', FocalPointInitAction::class);

        $view->registerGlobal('image', new ImageHelper());
    }
}