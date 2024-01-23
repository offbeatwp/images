<?php

namespace OffbeatWP\Images\Helpers;

final class ImageHelper
{
    public const MIN_VIEWPORT_WIDTH = 320;
    public const MAX_WIDTH_INTERVAL = 200;
    public const MAX_VIEWPORT_WIDTH = 2000;

    /**
     * @param int $attachment
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float} $args
     * @return string
     */
    public function generateResponsiveImage(int $attachment, array $args = []): string
    {
        $args = apply_filters('offbeat/responsiveImage/args', $args, $attachment);

        $containedMaxWidth = apply_filters('offbeat/responsiveImage/containedMaxWidth', $args['containedMaxWidth'] ?? null);
        $sizes = apply_filters('offbeat/responsiveImage/sizes', $args['sizes'] ?? null);
        $aspectRatio = apply_filters('offbeat/responsiveImage/aspectRatio', $args['aspectRatio'] ?? null);

        if (!$sizes || !is_array($sizes)) {
            $sizes = [0 => '100%'];
        }

        $sizes = $this->cleanSizes($sizes);
        $sizes = $this->transformSizes($sizes, $containedMaxWidth);

        $sources = $this->generateSources($attachment, $sizes, $aspectRatio ?? null);

        return $this->generateResponsiveImageTag($attachment, $sources, $args);
    }

    /**
     * @param string[] $sizes
     * @return string[]
     */
    protected function cleanSizes(array $sizes): array
    {
        $lastImageWidth = null;

        foreach ($sizes as $breakpoint => $imageWidth) {
            if ($lastImageWidth && $lastImageWidth === $imageWidth) {
                unset($sizes[$breakpoint]);
            }

            $lastImageWidth = $imageWidth;
        }

        return $sizes;
    }

    /**
     * @param string[] $sizes
     * @param string|int|float $containedMaxWidth
     * @return string[]
     */
    protected function transformSizes(array $sizes, $containedMaxWidth): array
    {
        // If there is no 0 size defined we assume a display width of 100%
        if (!isset($sizes[0])) {
            $sizes[0] = '100%';
        }

        // If there is no contained max width defined we assume a width of 100vw
        if (!$containedMaxWidth) {
            $containedMaxWidth = '100vw';
        }

        // if the contained max width is percentage convert it to viewport width
        if (is_numeric($containedMaxWidth)) {
            $containedMaxWidth = (int)$containedMaxWidth;
        } else {
            $containedMaxWidth = preg_replace_callback('/^(?<percentage>\d+(\.\d+)?)%$/', function ($matches) {
                return floor((float)$matches['percentage']) . 'vw';
            }, $containedMaxWidth);
        }

        // Remove all sizes where key is not a number
        $sizes = array_filter($sizes, fn($key) => is_numeric($key), ARRAY_FILTER_USE_KEY);

        // Sort sizes by key (breakpoints)
        ksort($sizes);

        $sizesReturn = [];

        foreach ($sizes as $breakpointWidth => $imageSize) {
            $percentage = null;
            $imageWidth = null;
            $nextBreakpointWidth = $this->getNextKey($sizes, $breakpointWidth);

            // Check if the size needs to be calculate based on a percentage
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
            } elseif (preg_match('/px$/', $imageSize)) {
                $imageWidth = $imageSize;
            }

            if ($imageWidth) {
                $sizesReturn[$breakpointWidth] = $imageWidth;
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
                $sizesReturn[$containedMaxWidth] = ceil($containedMaxWidth * ($percentage / 100)) . 'px';
            }
        }

        return $sizesReturn;
    }

    /**
     * @param string[] $sizes
     * @return float[]|int[]
     */
    public function calculateImageWidths(array $sizes): array
    {
        if (!$sizes) {
            return [];
        }

        /** @var int[] $foundImageWidths */
        $foundImageWidths = [];
        $imageWidths = [];

        foreach ($sizes as $breakpoint => $size) {
            if (!$size) {
                continue;
            }

            if (preg_match('/^(?<viewportWidth>\d+)vw$/', $size, $matches)) {
                $breakpointMinViewportWidth = max($breakpoint, self::MIN_VIEWPORT_WIDTH);
                $nextBreakpoint = $this->getNextKey($sizes, $breakpoint);

                if ($nextBreakpoint) {
                    $breakpointMaxViewportWidth = $nextBreakpoint - 1;
                } else {
                    $breakpointMaxViewportWidth = self::MAX_VIEWPORT_WIDTH;
                }

                $foundImageWidths[] = (int)($breakpointMinViewportWidth * ((int)$matches['viewportWidth'] / 100));
                $foundImageWidths[] = (int)($breakpointMaxViewportWidth * ((int)$matches['viewportWidth'] / 100));
            } elseif (preg_match('/^(?<imageWidth>\d+)px$/', $size, $matches)) {
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
     * @param int $attachmentId
     * @param string[]|float[]|int[]|null[] $sizes
     * @param string|float|int|null $aspectRatio
     * @param bool $pixelDensitySrcSet
     * @return string[]
     */
    public function generateSrcSet(int $attachmentId, array $sizes, $aspectRatio = null, bool $pixelDensitySrcSet = false): array
    {
        $srcSet = [];
        $imageModifier = $aspectRatio ? 'c' : '';

        $imageWidths = $this->calculateImageWidths($sizes);

        foreach ($imageWidths as $imageWidth) {
            $imageHeight = offbeat('images')->getOriginalImageHeight($attachmentId);
            $aspectRatio = self::calculateAspectRatio($aspectRatio);

            if ($aspectRatio) {
                $imageHeight = round($imageWidth / $aspectRatio);
            }

            if ($pixelDensitySrcSet) {
                foreach ([1, 2] as $pixelDensity) {
                    $image = offbeat('images')->getImage($attachmentId, "*{$imageWidth}x{$imageHeight}{$imageModifier}/{$pixelDensity}x");

                    if (!$image) {
                        $image = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);
                        $srcSet[] = $image['url'] . ' ' . $pixelDensity . 'x';

                        break;
                    }

                    $srcSet[] = $image['url'] . ' ' . $pixelDensity . 'x';
                }

            } else {
                $image = offbeat('images')->getImage($attachmentId, "*{$imageWidth}x{$imageHeight}{$imageModifier}");

                if (!$image) {
                    $image = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);
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
     * @param int $attachmentId
     * @param string[] $sizes
     * @param string|null $aspectRatio
     * @return array{sizes: string[]|null[], media_query: string, srcset?: string[]}[]
     */
    protected function generateSources(int $attachmentId, array $sizes, ?string $aspectRatio = null): array
    {
        $sources = [];
        $sourceSizes = [];

        foreach ($sizes as $breakpoint => $width) {
            $source = ['sizes' => []];

            $nextWidth = $this->getNextValue($sizes, $breakpoint);
            $nextBreakpoint = $this->getNextKey($sizes, $breakpoint);

            $sourceSizes[$breakpoint] = $width;

            // We are going to group the relative sources in source. So if current and next is
            // a relative width, we're going to skip it.
            if ($nextWidth && preg_match('/vw$/', $width) && preg_match('/vw$/', $nextWidth)) {
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
            if (preg_match('/vw$/', $width) && (!$nextWidth || preg_match('/px$/', $nextWidth))) {
                if ($nextBreakpoint) {
                    $sourceSizes[$nextBreakpoint] = null;
                }

                $source['sizes'] = $sourceSizes;
                $source['srcset'] = $this->generateSrcSet($attachmentId, $sourceSizes, $aspectRatio);

                $sources[] = $source;

                continue;
            }

            // Absolute definitions will width a more strict srcset (defining pixel density images)
            if (preg_match('/px$/', $width)) {
                $source['srcset'] = $this->generateSrcSet($attachmentId, [$width], $aspectRatio, true);

                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @param string[]|null $sizes
     * @return string|null
     */
    protected function generateSizesAttribute(?array $sizes): ?string
    {
        $sizesAttributeParts = [];

        if ($sizes) {
            foreach ($sizes as $breakpoint => $width) {
                $nextBreakpoint = $this->getNextKey($sizes, $breakpoint);
                $nextWidth = $this->getNextValue($sizes, $breakpoint);

                if (!$width) {
                    continue;
                }

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
     * @param int $attachmentId
     * @param array{sizes: string[]|null[], media_query: string, srcset?: string[]}[] $sources
     * @param array{url?: string, class?: string, loading?: string, alt?: string, sizes?: string[], aspectRatio?: string, lightbox?: bool, containedMaxWidth?: string|int|float} $args
     * @return string
     */
    protected function generateResponsiveImageTag(int $attachmentId, array $sources, array $args): string
    {
        $sourcesHtml = [];

        foreach ($sources as $source) {
            $sizesAttribute = $this->generateSizesAttribute($source['sizes']);
            $sourcesHtml[] = '<source srcset="' . implode(', ', $source['srcset']) . '" ' . ($sizesAttribute ? 'sizes="' . $sizesAttribute . '" ' : null) . 'media="(' . $source['media_query'] . ')">';
        }

        $alt = $args['alt'] ?? null;
        $loading = 'lazy';
        $class = 'img-fluid';
        $styles = ['object-fit: cover'];
        $aspectRatio = $args['aspectRatio'] ?? null;
        $className = $args['className'] ?? null;
        $link = $args['link'] ?? null;
        $linkTarget = $args['linkTarget'] ?? null;

        if ($aspectRatio) {
            $styles[] = 'aspect-ratio: ' . $aspectRatio;
        }

        $fallbackImage = offbeat('images')->getMaxImage($attachmentId, $aspectRatio);

        $classNames = ['wp-block', 'wp-block-offbeatwp-image'];

        if ($className) {
            $classNames[] = $className;
        }

        $imageTag = '
            <figure>
                <picture class="' . implode(' ', $classNames) . '">
                    '. implode("\n", $sourcesHtml) .'
                    <img src="' . $fallbackImage['url'] . '" width="' . $fallbackImage['width']  . '" height="' . $fallbackImage['height'] . '" class="' . $class . '" loading="' . $loading . '" alt="'. $alt .'" style="'. implode('; ', $styles) .'" />
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

    /**
     * @param string|float|int|null $aspectRatio
     * @return float|int
     */
    public static function calculateAspectRatio($aspectRatio)
    {
        if (is_float($aspectRatio) || is_int($aspectRatio)) {
            return $aspectRatio;
        }

        if (is_string($aspectRatio) && preg_match('#^(?<widthRatio>\d+)/(?<heightRatio>\d+)$#', $aspectRatio, $matches)) {
            return (int)$matches['widthRatio'] / (int)$matches['heightRatio'];
        }

        return 3 / 2; // @TODO Needs some fixing

        // throw new InvalidArgumentException('An invalid aspect ratio was provided. Aspect ratio must be a number or a "width/height" string');
    }

    /**
     * @param string[] $array
     * @param int|string $key
     * @return int|string|null
     */
    protected function getNextKey(array $array, $key)
    {
        $arrayKeys = array_keys($array);
        $index = array_search($key, $arrayKeys, true);

        if ($index === false) {
            return null;
        }

        return $arrayKeys[$index + 1] ?? null;
    }

    /**
     * @param string[] $array
     * @param int|string $key
     * @return string|null
     */
    protected function getNextValue(array $array, $key): ?string
    {
        $nextKey = $this->getNextKey($array, $key);

        if (!$nextKey) {
            return null;
        }

        return $array[$nextKey];
    }
}