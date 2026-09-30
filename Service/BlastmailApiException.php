<?php

namespace Plugin\BlastmailSync\Service;

class BlastmailApiException extends \RuntimeException
{
    public static function fromResponse(int $status, string $body): self
    {
        $msg = trim($body);
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['error']['message'])) {
            $msg = (string) $json['error']['message'];
        } elseif (preg_match('#<message>(.*?)</message>#s', $body, $m)) {
            $msg = $m[1];
        }

        return new self(sprintf('blastmail API エラー (HTTP %d): %s', $status, mb_substr($msg, 0, 300)), $status);
    }
}
