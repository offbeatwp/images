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
     * @param int|int[] $attachmentIds
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float} $args
     */
    public function generateResponsiveImage(int|array $attachmentIds, array $args = []): string
    {
        // Filter args and aspectRatio
        $args = apply_filters('offbeat/responsiveImage/args', $args, $attachmentIds);
        $aspectRatio = apply_filters('offbeat/responsiveImage/aspectRatio', $args['aspectRatio'] ?? null, $args);

        // If attachmentId is an array with size keys, put it through the sizes filter
        if (is_array($attachmentIds)) {
            $attachmentIds = $this->cleanSizes(apply_filters('offbeat/responsiveImage/sizes', $attachmentIds, $args));

            if (empty($attachmentIds[0])) {
                throw new InvalidArgumentException('When passing an array of attachmentIDs to generateResponsiveImage, it must contain an ID for a 0px width');
            }
        } else {
            $attachmentIds = [0 => $attachmentIds];
        }

        // Filter and sanitize sizes
        $sizes = apply_filters('offbeat/responsiveImage/sizes', $args['sizes'] ?? null, $args);
        if ($sizes && is_array($sizes)) {
            $sizes = $this->cleanSizes($sizes);
        } else {
            $sizes = [0 => '100%'];
        }

        // Filter and sanitize contained max width
        $containedMaxWidth = apply_filters('offbeat/responsiveImage/containedMaxWidth', $args['containedMaxWidth'] ?? null, $args);
        if (!$containedMaxWidth) {
            $containedMaxWidth = '100vw';
        } elseif (is_numeric($containedMaxWidth)) {
            $containedMaxWidth = (int)$containedMaxWidth;
        } else {
            $containedMaxWidth = $this->getViewportWidth($containedMaxWidth);
        }

        // Generate image tags
        $breakpoints = $this->generateBreakpoints($attachmentIds, $sizes, $containedMaxWidth);

        $sources = $this->generateSources($breakpoints, $aspectRatio);

        return $this->generateResponsiveImageTag($attachmentIds[0], $sources, $args);
    }

    /** Removes all non-numeric keys from the passed array and converts all numeric-string keys to integers */
    private function cleanSizes(iterable $array): array
    {
        $output = [];

        foreach ($array as $key => $value) {
            if (is_numeric($key)) {
                $output[(int)$key] = $value;
            }
        }

        return $output;
    }

    /**
     * @param non-empty-array<int, int> $attachmentIds
     * @param array<int, string> $imageSizes
     * @return array<int, BreakPoint> An array of strings with pixel values. EG: '42px'
     */
    protected function generateBreakpoints(array $attachmentIds, array $imageSizes, string|int $containedMaxWidth): array
    {
        // Combine breakpoints of attachmentIds and imageSizes
        /** @var int[] $breakpointWidths */
        $breakpointWidths = array_unique([...array_keys($imageSizes), ...array_keys($attachmentIds)]);

        // Sort sizes by key (breakpoints)
        sort($breakpointWidths);

        $breakpoints = [];
        $imageSize = '100%';
        $imageId = 0;

        foreach ($breakpointWidths as $breakpointWidth) {
            if (array_key_exists($breakpointWidth, $imageSizes)) {
                $imageSize = $imageSizes[$breakpointWidth];
            }

            if (array_key_exists($breakpointWidth, $attachmentIds)) {
                $imageId = $attachmentIds[$breakpointWidth];
            }

            $nextBreakpointWidth = $this->getNextKey($imageSizes, $breakpointWidth);

            // Check if the size needs to be calculate based on a percentage
            $percentage = null;
            $imageWidth = null;

            if (preg_match('/^(?<percentage>\d+(\.\d+)?)%$/', $imageSize, $matches)) {
                $percentage = (float)$matches['percentage'];

                // Make calculation when the containedMaxWidth is based on the viewport width
                if (preg_match('/^(?<viewportWidth>\d+)vw$/', $containedMaxWidth, $matches)) {
                    $imageWidth = floor((int)$matches['viewportWidth'] * ($percentage / 100)) . 'vw';
                } elseif (is_numeric($containedMaxWidth)) {
                    // if breakpoint width is smaller then the contained max width
                    // we add a size width a relative width otherwise an absolute width
                    if ($breakpointWidth < $containedMaxWidth) {
                        $imageWidth = floor($percentage) . 'vw';
                    } else {
                        $imageWidth = ceil((int)$containedMaxWidth * ($percentage / 100)) . 'px';
                    }
                }
            } elseif (is_numeric($imageSize)) {
                $imageWidth = $imageSize . 'px';
            } elseif (str_ends_with($imageSize, 'px')) {
                $imageWidth = $imageSize;
            }

            if ($imageWidth) {
                $breakpoints[$breakpointWidth] = new BreakPoint($imageId, $imageWidth);
            }

            // in two cases we add an extra size:
            // 1. If current breakpoint < contained max width, but next breakpoint is wider
            // 2. If there is no next breakpoint, but we didn't reached the contained max width yet
            if (
                is_int($containedMaxWidth) &&
                is_float($percentage) &&
                (
                    ($nextBreakpointWidth && $breakpointWidth < $containedMaxWidth && $nextBreakpointWidth > $containedMaxWidth) ||
                    (!$nextBreakpointWidth && $breakpointWidth < $containedMaxWidth)
                )
            ) {
                $breakpoints[$containedMaxWidth] = new BreakPoint($imageId, ceil($containedMaxWidth * ($percentage / 100)) . 'px');
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
     * @param array<int, BreakPoint> $sizes
     * @return array{sizes: string[]|null[], media_query: string, srcset?: string[]}[]
     */
    protected function generateSources(array $sizes, ?string $aspectRatio): array
    {
        $sources = [];
        $sourceSizes = [];

        foreach ($sizes as $breakpointWidth => $breakpoint) {
            $source = ['sizes' => []];

            $nextBreakpointWidth = $this->getNextKey($sizes, $breakpointWidth);
            $nextBreakpoint = $sizes[$nextBreakpointWidth] ?? null;

            $sourceSizes[$breakpointWidth] = $breakpoint->getWidth();

            // We are going to group the relative sources in source. So if current and next is
            // a relative width, we're going to skip it.
            if ($nextBreakpoint && str_ends_with($breakpoint->getWidth(), 'vw') && str_ends_with($nextBreakpoint->getWidth(), 'vw')) {
                continue;
            }

            // If there is a next breakpoint use that as max-width, otherwise use min-width
            if ($nextBreakpointWidth) {
                $source['media_query'] = 'max-width: ' . ($nextBreakpointWidth - 1) . 'px';
            } else {
                $source['media_query'] = 'min-width: ' . ($breakpointWidth) . 'px';
            }

            // If current width is relative, and the next one is absolute (or there is no next)
            // we going to define the source.
            if (str_ends_with($breakpoint->getWidth(), 'vw') && (!$nextBreakpoint || str_ends_with($nextBreakpoint->getWidth(), 'px'))) {
                if ($nextBreakpointWidth) {
                    $sourceSizes[$nextBreakpointWidth] = null;
                }

                $source['sizes'] = $sourceSizes;
                $source['srcset'] = $this->generateSrcSet($breakpoint->getAttachmentId(), $sourceSizes, $aspectRatio);

                $sources[] = $source;

                continue;
            }

            // Absolute definitions will width a more strict srcset (defining pixel density images)
            if (str_ends_with($breakpoint->getWidth(), 'px')) {
                $source['srcset'] = $this->generateSrcSet($breakpoint->getAttachmentId(), [$breakpoint->getWidth()], $aspectRatio, true);

                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @pure
     * @param array<int, string|null> $sizes
     */
    protected function generateSizesAttribute(array $sizes): ?string
    {
        $sizesAttributeParts = [];

        foreach ($sizes as $breakpointWidth => $width) {
            if ($width) {
                $nextBreakpointWidth = $this->getNextKey($sizes, $breakpointWidth);
                $nextWidth = $sizes[$nextBreakpointWidth] ?? null;

                if ($nextBreakpointWidth && $nextWidth) {
                    $sizesAttributeParts[] = '(max-width: ' . ($nextBreakpointWidth - 1) . 'px) ' . $width;
                } else {
                    $sizesAttributeParts[] = $width;
                }
            }
        }

        return $sizesAttributeParts ? implode(', ', $sizesAttributeParts) : null;
    }

    /**
     * @param array{sizes: string[]|null[], media_query: string, srcset?: string[]}[] $sources
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float, caption?: string, objectFit?: string, className?: string, link?: string, linkTarget?: string, decoding?: string} $args
     */
    protected function generateResponsiveImageTag(int $fallbackAttachmentId, array $sources, array $args): string
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

        $fallbackImage = offbeat('images')->getMaxImage($fallbackAttachmentId, $aspectRatio);

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
                $link = wp_get_attachment_url($fallbackAttachmentId);

                if (!$linkTarget) {
                    $linkTarget = '_blank';
                }
            }

            $linkTagAttributes = [
                'href' => $link,
                'target' => $linkTarget
            ];

            $linkTagAttributes = apply_filters('offbeat/responsiveImage/linkTagAttributes', $linkTagAttributes, $fallbackAttachmentId, $args);

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
