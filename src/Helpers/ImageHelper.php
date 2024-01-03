<?php

namespace OffbeatWP\Images\Helpers;

use OffbeatWP\Images\ImagesService;

final class ImageHelper
{
    protected ImagesService $service;

    public function __construct(ImagesService $service)
    {  
        $this->service = $service;
    }

    public function generateResponsivePicture(int $attachment, array $sources, array $args = []): string
    {
        $sourcesHtml = [];

        krsort($sources);

        foreach ($sources as $width => $source) {
            $srcset = [];

            foreach ([1, 2] as $pixelDensity) {
                $image = $this->service->getImage($attachment, $source . '/' . $pixelDensity . 'x');

                if (!$image) {
                    continue;
                }
                
                $srcset[] = $image['url'] . ' ' . $pixelDensity . 'x';
            }

            if ($srcset) {
                $sourcesHtml[] = '<source srcset="' . implode(', ', $srcset) . '" media="(min-width: ' . $width . 'px)">';
            }
        }

        $fallbackImage = wp_get_attachment_image_src($attachment, 'xl-large');

        $class = $args['class'] ?? null;
        $loading = $args['loading'] ?? 'eager';
        $alt = isset($args['alt']) ? ' alt="' . $args['alt'] . '"' : '';

        return '
            <picture>
                '. implode("\n", $sourcesHtml) .'
                <img src="' . $fallbackImage[0] . '" class="' . $class . '" loading="' . $loading . '"'. $alt .' />
            </picture>
        ';
    }
}