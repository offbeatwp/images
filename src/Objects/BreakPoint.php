<?php

namespace OffbeatWP\Images\Objects;

final class BreakPoint
{
    private int $attachmentId;
    private string $width;
    private string $unit;
    private $aspectRatio;

    public function __construct(int $attachmentId, string $width, $aspectRatio = null)
    {
        $this->attachmentId = $attachmentId;
        $this->width = $width;
        $this->unit = str_ends_with($this->width, 'vw') ? 'vw' : 'px';
        $this->aspectRatio = $aspectRatio;
    }

    public function getAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function getWidth(): string
    {
        return $this->width;
    }

    /** @return 'px'|'vw' */
    public function getUnit(): string
    {
        return $this->unit;
    }
    public function getAspectRatio(): ?string
    {
        return $this->aspectRatio;
    }
}