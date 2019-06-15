<?php

namespace ArthurHoaro\RssExtender\Bean;

use DateTime;
use FeedIo\Feed\Item;

class CachedItem extends Item
{
    protected $cachedAt;

    /**
     * Get the CachedAt.
     *
     * @return mixed
     */
    public function getCachedAt(): ?DateTime
    {
        return $this->cachedAt;
    }

    /**
     * Set the CachedAt.
     *
     * @param mixed $cachedAt
     *
     * @return CachedItem
     */
    public function setCachedAt(DateTime $cachedAt)
    {
        $this->cachedAt = $cachedAt;

        return $this;
    }


}