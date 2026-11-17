<?php

declare(strict_types=1);

namespace OpinionPadi\Offers;

/**
 * Read the click id and padi code a customer arrived with.
 *
 * Padi links land on your site with `op_click` (the click id) and `op_ref`
 * (the padi's code) in the URL. The browser SDK keeps them in first-party
 * cookies with the same names, so your server can read them at checkout.
 */
final class Attribution
{
    public const CLICK_PARAM = 'op_click';

    public const REF_PARAM = 'op_ref';

    /**
     * From a landing URL (or just its query string).
     *
     * @return array{click_id: ?string, ref: ?string}
     */
    public static function fromUrl(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            // A bare query string such as "op_click=...&op_ref=...".
            $query = str_contains($url, '=') && ! str_contains($url, '://') ? ltrim($url, '?') : '';
        }

        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            // The first value wins, as a browser would read it.
            if (! array_key_exists($name, $values)) {
                $values[$name] = urldecode($value);
            }
        }

        return [
            'click_id' => self::clean($values[self::CLICK_PARAM] ?? null),
            'ref' => self::clean($values[self::REF_PARAM] ?? null),
        ];
    }

    /**
     * From cookies: pass `$_COOKIE`, any name => value array, or a raw
     * `Cookie` header string.
     *
     * @param  array<string, mixed>|string  $cookies
     * @return array{click_id: ?string, ref: ?string}
     */
    public static function fromCookies(array|string $cookies): array
    {
        if (is_string($cookies)) {
            $parsed = [];
            foreach (explode(';', $cookies) as $part) {
                $part = trim($part);
                if ($part === '' || ! str_contains($part, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $part, 2);
                $name = trim($name);
                if (! array_key_exists($name, $parsed)) {
                    $parsed[$name] = rawurldecode(trim(trim($value), '"'));
                }
            }
            $cookies = $parsed;
        }

        return [
            'click_id' => self::clean($cookies[self::CLICK_PARAM] ?? null),
            'ref' => self::clean($cookies[self::REF_PARAM] ?? null),
        ];
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
