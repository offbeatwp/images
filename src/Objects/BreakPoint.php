<?php

namespace OffbeatWP\Images\Objects;

final class BreakPoint
{
    private int $attachmentId;
    private string $width;

    public function __construct(int $attachmentId, string $width)
    {
        $this->attachmentId = $attachmentId;
        $this->width = $width;
    }

    public function getAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function getWidth(): string
    {
        return $this->width;
    }
}