<?php

namespace OffbeatWP\Images\Helpers;

use Error;
use InvalidArgumentException;
use OffbeatWP\Images\Objects\BreakPoint;

final class ImageHelper
{
    public const MIN_VIEWPORT_WIDTH = 320;
    public const MAX_WIDTH_INTERVAL = 200;
    public const MAX_VIEWPORT_WIDTH = 2000;

    /**
     * @param int|int[] $attachmentId
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float} $args
     */
    public function generateResponsiveImage(int|array $attachmentId, array $args = []): string
    {
        $args = apply_filters('offbeat/responsiveImage/args', $args, $attachmentId);

        $containedMaxWidth = apply_filters('offbeat/responsiveImage/containedMaxWidth', $args['containedMaxWidth'] ?? null, $args);
        /** @var array<int, string> $sizes */
        $sizes = apply_filters('offbeat/responsiveImage/sizes', $args['sizes'] ?? null, $args);
        $aspectRatio = apply_filters('offbeat/responsiveImage/aspectRatio', $args['aspectRatio'] ?? null, $args);

        // If attachmentId is an array with size keys, put it through the sizes filter
        if (is_array($attachmentId)) {
            $attachmentId = apply_filters('offbeat/responsiveImage/sizes', $attachmentId, $args);

            if (empty($attachmentId[0])) {
                throw new InvalidArgumentException('When passing an array of attachmentIDs to generateResponsiveImage, it must contain an ID for a 0px width');
            }
        }

        // If return value of sizes is invalid, change it to the default array
        if (!$sizes || !is_array($sizes)) {
            $sizes = [0 => '100%'];
        }

        $breakpoints = $this->generateBreakpoints($attachmentId, $sizes, $containedMaxWidth);

        $sources = $this->generateSources($breakpoints, $aspectRatio);

        return $this->generateResponsiveImageTag($attachmentId, $sources, $args);
    }

    /**
     * @param array<int, string> $sizes
     * @return BreakPoint[] An array of strings with pixel values. EG: '42px'
     */
    protected function generateBreakpoints(int $attachmentId, array $sizes, string|int|float|null $containedMaxWidth): array
    {
        // If there is no 0 size defined we assume a display width of 100%
        if (!isset($sizes[0])) {
            $sizes[0] = '100%';
        }

        // If there is no contained max width defined we assume a width of 100vw
        if (!$containedMaxWidth) {
            $containedMaxWidth = '100vw';
        }

        // if the contained max width is percentage convert it to viewport width, otherwise convert it to an integer
        if (is_numeric($containedMaxWidth)) {
            $convertedMaxWidth = (int)$containedMaxWidth;
        } else {
            $convertedMaxWidth = $this->getViewportWidth($containedMaxWidth);
        }

        // Remove all sizes where key is not a number
        $sizes = array_filter($sizes, fn($key) => is_numeric($key), ARRAY_FILTER_USE_KEY);

        // Sort sizes by key (breakpoints)
        ksort($sizes);

        $breakpoints = [];

        foreach ($sizes as $breakpointWidth => $imageSize) {
            $percentage = null;
            $imageWidth = null;
            $nextBreakpointWidth = $this->getNextKey($sizes, $breakpointWidth);

            // Check if the size needs to be calculate based on a percentage
            if (preg_match('/^(?<percentage>\d+(\.\d+)?)%$/', $imageSize, $matches)) {
                $percentage = (float)$matches['percentage'];

                // Make calculation when the containedMaxWidth is based on the viewport width
                if (preg_match('/^(?<viewportWidth>\d+)vw$/', $convertedMaxWidth, $matches)) {
                    $imageWidth = floor((int)$matches['viewportWidth'] * ($percentage / 100)) . 'vw';
                } elseif (is_numeric($convertedMaxWidth)) {
                    // if breakpoint width is smaller then the contained max width
                    // we add a size width a relative width otherwise an absolute width
                    if ($breakpointWidth < $convertedMaxWidth) {
                        $imageWidth = floor($percentage) . 'vw';
                    } else {
                        $imageWidth = ceil((int)$convertedMaxWidth * ($percentage / 100)) . 'px';
                    }
                }
            } elseif (is_numeric($imageSize)) {
                $imageWidth = $imageSize . 'px';
            } elseif (str_ends_with($imageSize, 'px')) {
                $imageWidth = $imageSize;
            }

            if ($imageWidth) {
                $breakpoints[$breakpointWidth] = new BreakPoint($attachmentId, $imageWidth);
            }

            // in two cases we add an extra size:
            // 1. If current breakpoint < contained max width, but next breakpoint is wider
            // 2. If there is no next breakpoint, but we didn't reached the contained max width yet
            if (
                is_int($convertedMaxWidth) &&
                is_float($percentage) &&
                (
                    ($nextBreakpointWidth && $breakpointWidth < $convertedMaxWidth && $nextBreakpointWidth > $convertedMaxWidth) ||
                    (!$nextBreakpointWidth && $breakpointWidth < $convertedMaxWidth)
                )
            ) {
                $breakpoints[$convertedMaxWidth] = new BreakPoint($attachmentId, ceil($convertedMaxWidth * ($percentage / 100)) . 'px');
            }
        }

        return $breakpoints;
    }

    private function getViewportWidth(string $containedMaxWidth): string
    {
        $result = preg_replace_callback('/^(?<percentage>\d+(\.\d+)?)%$/', function ($matches) {
            return floor((float)$matches['percentage']) . 'vw';
        }, $containedMaxWidth);

        if ($result === null) {
            throw new Error(preg_last_error_msg());
        }

        return $result;
    }

    /**
     * @param string[] $breakpoints
     * @return float[]|int[]
     */
    public function calculateImageWidths(array $breakpoints): array
    {
        if (!$breakpoints) {
            return [];
        }

        /** @var int[] $foundImageWidths */
        $foundImageWidths = [];
        $imageWidths = [];

        foreach ($breakpoints as $breakpointWidth => $breakpoint) {
            if (!$breakpoint) {
                continue;
            }

            if (preg_match('/^(?<viewportWidth>\d+)vw$/', $breakpoint, $matches)) {
                $breakpointMinViewportWidth = max($breakpointWidth, self::MIN_VIEWPORT_WIDTH);
                $nextBreakpointWidth = $this->getNextKey($breakpoints, $breakpointWidth);

                if ($nextBreakpointWidth) {
                    $breakpointMaxViewportWidth = $nextBreakpointWidth - 1;
                } else {
                    $breakpointMaxViewportWidth = self::MAX_VIEWPORT_WIDTH;
                }

                $foundImageWidths[] = (int)($breakpointMinViewportWidth * ((int)$matches['viewportWidth'] / 100));
                $foundImageWidths[] = (int)($breakpointMaxViewportWidth * ((int)$matches['viewportWidth'] / 100));
            } elseif (preg_match('/^(?<imageWidth>\d+)px$/', $breakpoint, $matches)) {
                $foundImageWidths[] = (int)$matches['imageWidth'];
            }
        }

        sort($foundImageWidths);

        $minImageWidth = reset($foundImageWidths);
        $maxImageWidth = end($foundImageWidths);

        if ($minImageWidth === $maxImageWidth) {
            return [$minImageWidth];
        }

        $steps = ceil(($maxImageWidth - $minImageWidth) / self::MAX_WIDTH_INTERVAL);

        for ($i = 0; $i <= $steps; $i++) {
            $imageWidths[] = $minImageWidth + (self::MAX_WIDTH_INTERVAL * $i);
        }

        return $imageWidths;
    }

    /**
     * @param string[]|float[]|int[]|null[] $sizes
     * @return string[]
     */
    public function generateSrcSet(int $attachmentId, array $sizes, string|float|int|null $aspectRatio = null, bool $pixelDensitySrcSet = false): array
    {
        $srcSet = [];
        $imageModifier = $aspectRatio ? 'c' : '';

        $imageWidths = $this->calculateImageWidths($sizes);

        foreach ($imageWidths as $imageWidth) {
            $imageHeight = offbeat('images')->getOriginalImageHeight($attachmentId);
            $aspectRatio = self::calculateAspectRatio($aspectRatio, $attachmentId);

            if ($aspectRatio) {
                $imageHeight = round($imageWidth / $aspectRatio);
            }

            if ($pixelDensitySrcSet) {
                foreach ([1, 2] as $pixelDensity) {
                    $image = offbeat('images')->getImage($attachmentId, "*{$imageWidth}x{$imageHeight}{$imageModifier}/{$pixelDensity}x");

                    if (!$image) {
                        $image = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);

                        if (!$image) {
                            trigger_error('Could not get max image (pixel density ' . $pixelDensity . ') for attachment #' . $attachmentId . ' with ratio ' . $aspectRatio);
                        }
                        $srcSet[] = $image['url'] . ' ' . $pixelDensity . 'x';
                        break;
                    }

                    $srcSet[] = $image['url'] . ' ' . $pixelDensity . 'x';
                }

            } else {
                $image = offbeat('images')->getImage($attachmentId, "*{$imageWidth}x{$imageHeight}{$imageModifier}");

                if (!$image) {
                    $image = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);

                    if (!$image) {
                        trigger_error('Could not get max image for attachment #' . $attachmentId . ' with ratio ' . $aspectRatio);
                    }
                    $srcSet[] = $image['url'] . ' ' . $image['width'] . 'w';
                    break;
                }

                $srcSet[] = $image['url'] . ' ' . $image['width'] . 'w';
            }
        }

        if ($srcSet) {
            return $srcSet;
        }

        $maxImage = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);
        $srcSet[] = $maxImage['url'] . ' ' . $maxImage['width'] . 'w';

        return $srcSet;
    }

    /**
     * @param BreakPoint[] $sizes
     * @return array{sizes: string[]|null[], media_query: string, srcset?: string[]}[]
     */
    protected function generateSources(array $sizes, ?string $aspectRatio): array
    {
        $sources = [];
        $sourceSizes = [];

        foreach ($sizes as $breakpoint => $data) {
            $source = ['sizes' => []];

            $nextBreakpoint = $this->getNextKey($sizes, $breakpoint);
            $nextData = $sizes[$nextBreakpoint] ?? null;

            $sourceSizes[$breakpoint] = $data->getWidth();

            // We are going to group the relative sources in source. So if current and next is
            // a relative width, we're going to skip it.
            if ($nextData && str_ends_with($data->getWidth(), 'vw') && str_ends_with($nextData->getWidth(), 'vw')) {
                continue;
            }

            // If there is a next breakpoint use that as max-width, otherwise use min-width
            if ($nextBreakpoint) {
                $source['media_query'] = 'max-width: ' . ($nextBreakpoint - 1) . 'px';
            } else {
                $source['media_query'] = 'min-width: ' . ($breakpoint) . 'px';
            }

            // If current width is relative, and the next one is absolute (or there is no next)
            // we going to define the source.
            if (str_ends_with($data->getWidth(), 'vw') && (!$nextData || str_ends_with($nextData->getWidth(), 'px'))) {
                if ($nextBreakpoint) {
                    $sourceSizes[$nextBreakpoint] = null;
                }

                $source['sizes'] = $sourceSizes;
                $source['srcset'] = $this->generateSrcSet($data->getAttachmentId(), $sourceSizes, $aspectRatio);

                $sources[] = $source;

                continue;
            }

            // Absolute definitions will width a more strict srcset (defining pixel density images)
            if (str_ends_with($data->getWidth(), 'px')) {
                $source['srcset'] = $this->generateSrcSet($data->getAttachmentId(), [$data->getWidth()], $aspectRatio, true);

                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @pure
     * @param string[]|null $sizes
     */
    protected function generateSizesAttribute(?array $sizes)
    {
        $sizesAttributeParts = [];

        if ($sizes) {
            foreach ($sizes as $breakpoint => $width) {
                if (!$width) {
                    continue;
                }

                $nextBreakpoint = $this->getNextKey($sizes, $breakpoint);
                $nextWidth = $sizes[$nextBreakpoint] ?? null;

                if ($nextBreakpoint && $nextWidth) {
                    $sizesAttributeParts[] = '(max-width: ' . ($nextBreakpoint - 1) . 'px) ' . $width;
                } else {
                    $sizesAttributeParts[] = $width;
                }
            }
        }

        return $sizesAttributeParts ? implode(', ', $sizesAttributeParts) : null;
    }

    /**
     * @param array{sizes: string[]|null[], media_query: string, srcset?: string[]}[] $sources
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float, caption?: string} $args
     */
    protected function generateResponsiveImageTag(int $attachmentId, array $sources, array $args): string
    {
        $sourcesHtml = [];

        foreach ($sources as $source) {
            $sizesAttribute = $this->generateSizesAttribute($source['sizes']);
            $sourcesHtml[] = '<source srcset="' . implode(', ', $source['srcset']) . '" ' . ($sizesAttribute ? 'sizes="' . $sizesAttribute . '" ' : null) . 'media="(' . $source['media_query'] . ')">';
        }

        $optionalAttributes = [
            'loading' => $args['loading'] ?? 'lazy',
            'alt' => $args['alt'] ?? null,
            'decoding' => $args['decoding'] ?? null
        ];

        $styles = [];
        $aspectRatio = $args['aspectRatio'] ?? null;
        $objectFit = $args['objectFit'] ?? 'cover';
        
        $className = $args['className'] ?? null;
        $link = $args['link'] ?? null;
        $linkTarget = $args['linkTarget'] ?? null;

        if ($objectFit) {
            $styles[] = 'object-fit: ' . $objectFit;
        }

        if ($aspectRatio) {
            $styles[] = 'aspect-ratio: ' . $aspectRatio;
        }

        $fallbackImage = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);

        $classNames = ['wp-block', 'wp-block-offbeatwp-image'];

        if ($className) {
            $classNames[] = $className;
        }

        $attribeHtmlString = '';
        foreach ($optionalAttributes as $key => $value) {
            if ($value !== null) {
                $attribeHtmlString .= $key . '="' . $value . '" ';
            }
        }

        $imageTag = '
            <figure>
                <picture class="' . implode(' ', $classNames) . '">
                    '. implode("\n", $sourcesHtml) .'
                    <img src="' . $fallbackImage['url'] . '" class="img-fluid" width="' . $fallbackImage['width']  . '" height="' . $fallbackImage['height'] . '" ' . $attribeHtmlString . 'style="'. implode('; ', $styles) .'" fetchpriority="' . ($args['fetchPriority'] ?? 'auto')  . '" />
                </picture>
                ' . (!empty($args['caption']) ? '<figcaption><div>' . $args['caption'] . '</div></figcaption>' : '') . '
            </figure>
        ';

        if ($link) {
            if ($link === 'image') {
                $link = wp_get_attachment_url($attachmentId);

                if (!$linkTarget) {
                    $linkTarget = '_blank';
                }
            }

            $linkTagAttributes = [
                'href' => $link,
                'target' => $linkTarget
            ];

            $linkTagAttributes = apply_filters('offbeat/responsiveImage/linkTagAttributes', $linkTagAttributes, $attachmentId, $args);

            $linkTagAttributes = implode(' ', array_map(function ($key) use ($linkTagAttributes) {
                if (is_bool($linkTagAttributes[$key])) {
                    return $linkTagAttributes[$key] ? $key : '';
                }
                return $key . '="' . $linkTagAttributes[$key] . '"';
            }, array_keys($linkTagAttributes)));

            $imageTag = '<a ' . $linkTagAttributes . '>' . $imageTag . '</a>';
        }

        return $imageTag;
    }

    public static function calculateAspectRatio(string|float|int|null $aspectRatio, int $attachmentId): int|float
    {
        if (is_float($aspectRatio) || is_int($aspectRatio)) {
            return $aspectRatio;
        }

        if (is_string($aspectRatio) && preg_match('#^(?<widthRatio>\d+)/(?<heightRatio>\d+)$#', $aspectRatio, $matches)) {
            return (int)$matches['widthRatio'] / (int)$matches['heightRatio'];
        }

        $originalImageSize = wp_get_attachment_image_src($attachmentId, 'full');

        if (is_array($originalImageSize) && !empty($originalImageSize)) {
            return (int)$originalImageSize[1] / (int)$originalImageSize[2];
        }
        
        return 3 / 2;
    }

    /** @pure */
    protected function getNextKey(array $array, int|string $key): int|null
    {
        $arrayKeys = array_keys($array);
        $index = array_search($key, $arrayKeys, true);

        if ($index === false) {
            return null;
        }

        return $arrayKeys[$index + 1] ?? null;
    }
}
