<?php

namespace App\Services\Publishing;

use App\Enums\SocialProvider;

class PublisherRegistry
{
    /**
     * @param  array<int, Publisher>  $publishers
     */
    public function __construct(private array $publishers = []) {}

    public function for(SocialProvider $provider): ?Publisher
    {
        foreach ($this->publishers as $publisher) {
            if ($publisher->provider() === $provider) {
                return $publisher;
            }
        }

        return null;
    }
}
