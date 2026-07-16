<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Connector;

class AvailableConnector
{
    public function __construct(
        private readonly string $code,
        private readonly string $name,
        private readonly string $status,
        private readonly string $description,
        private readonly string $link = ''
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getLink(): string
    {
        return $this->link;
    }
}
