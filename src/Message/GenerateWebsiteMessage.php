<?php

namespace App\Message;

class GenerateWebsiteMessage
{
    public function __construct(
        private int $promptId
    ) {}

    public function getPromptId(): int
    {
        return $this->promptId;
    }
}