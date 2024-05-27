<?php

namespace OffbeatWP\Images\Objects;

final class BreakPoint
{
    private int $attachmentId;
    private string $width;
    private ?string $unit = null;

    public function __construct(int $attachmentId, string $width)
    {
        $this->attachmentId = $attachmentId;
        $this->width = $width;

        if (str_ends_with($this->width, 'px')) {
            $this->unit = 'px';
        } elseif (str_ends_with($this->width, 'vw')) {
            $this->unit = 'vw';
        }
    }

    public function getAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function getWidth(): string
    {
        return $this->width;
    }

    /** @return 'px'|'vw'|null */
    public function getUnit(): ?string
    {
        return $this->unit;
    }
}