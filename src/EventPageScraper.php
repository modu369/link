<?php

class EventPageScraper
{
    public function extract(string $html): array
    {
        $result = [
            'open_price' => null,
            'current_price' => null,
            'up_price' => null,
            'down_price' => null,
        ];

        if (preg_match('/price to beat[^\$]*\$([0-9,]+(?:\.[0-9]{2})?)/i', $html, $match)) {
            $result['open_price'] = (float) str_replace(',', '', $match[1]);
        }

        if (preg_match('/current price[^\$]*\$([0-9,]+(?:\.[0-9]{2})?)/i', $html, $match)) {
            $result['current_price'] = (float) str_replace(',', '', $match[1]);
        }

        if (preg_match('/\bUp\b[^\d]*([0-9]{1,3})¢/i', $html, $match)) {
            $result['up_price'] = (float) $match[1];
        }

        if (preg_match('/\bDown\b[^\d]*([0-9]{1,3})¢/i', $html, $match)) {
            $result['down_price'] = (float) $match[1];
        }

        return $result;
    }
}
