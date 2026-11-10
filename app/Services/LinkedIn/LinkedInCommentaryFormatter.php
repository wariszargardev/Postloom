<?php

namespace App\Services\LinkedIn;

class LinkedInCommentaryFormatter
{
    private const HASHTAG_PATTERN = '/(?<![\p{L}\p{N}_#&])#([\p{L}\p{N}_]+)/u';

    private const RESERVED_PATTERN = '/([\\\\|{}@\[\]()<>#*_~])/u';

    /**
     * Turn plain text into LinkedIn "little text": reserved characters are escaped so they show as typed,
     * and #words become real hashtags.
     */
    public function format(string $text): string
    {
        $formatted = '';
        $offset = 0;

        preg_match_all(self::HASHTAG_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$hashtag, $position]) {
            $formatted .= $this->escape(substr($text, $offset, $position - $offset));
            $formatted .= '{hashtag|\\#|'.$this->escape($matches[1][$index][0]).'}';
            $offset = $position + strlen($hashtag);
        }

        return $formatted.$this->escape(substr($text, $offset));
    }

    public function escape(string $text): string
    {
        return (string) preg_replace(self::RESERVED_PATTERN, '\\\\$1', $text);
    }
}
