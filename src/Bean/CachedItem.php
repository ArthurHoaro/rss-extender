<?php

namespace ArthurHoaro\RssExtender\Bean;

use DateTimeInterface;
use FeedIo\Feed\Item;

class CachedItem extends Item
{
    protected ?DateTimeInterface $cachedAt;

    public function getCachedAt(): ?DateTimeInterface
    {
        return $this->cachedAt;
    }

    public function setCachedAt(DateTimeInterface $cachedAt): self
    {
        $this->cachedAt = $cachedAt;

        return $this;
    }
}
