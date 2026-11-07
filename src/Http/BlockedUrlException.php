<?php

namespace ArthurHoaro\RssExtender\Http;

use RuntimeException;

class BlockedUrlException extends RuntimeException
{
    public static function findIn(\Throwable $e): ?self
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }
}
