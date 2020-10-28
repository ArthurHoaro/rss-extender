<?php

/**
 * Htmlspecialchars wrapper
 * Support multidimensional array of strings.
 *
 * @param mixed $input Data to escape: a single string or an array of strings.
 *
 * @return string|array escaped.
 */
function escape($input)
{
    if (null === $input) {
        return null;
    }

    if (is_bool($input) || is_int($input) || is_float($input) || $input instanceof DateTimeInterface) {
        return $input;
    }

    if (is_array($input)) {
        $out = array();
        foreach ($input as $key => $value) {
            $out[escape($key)] = escape($value);
        }
        return $out;
    }
    return htmlspecialchars($input, ENT_COMPAT, 'UTF-8', false);
}
