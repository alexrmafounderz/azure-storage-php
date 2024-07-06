<?php

declare(strict_types=1);

namespace AzureOss\Storage\Blob\Models;

use JMS\Serializer\Annotation\SerializedName;
use JMS\Serializer\Annotation\Type;
use Psr\Http\Message\ResponseInterface;

final class BlobProperties
{
    public function __construct(
        #[SerializedName('Last-Modified')]
        #[Type("DateTimeImmutable<'" . \DateTimeInterface::RFC1123 . "'>")]
        public readonly \DateTimeInterface $lastModified,
        #[SerializedName('Content-Length')]
        public readonly int $contentLength,
        #[SerializedName('Content-Type')]
        public readonly string $contentType,
        #[SerializedName('Content-MD5')]
        public readonly string $contentMD5,
    ) {}

    public static function fromResponseHeaders(ResponseInterface $response): self
    {
        return new BlobProperties(
            new \DateTime($response->getHeaderLine('Last-Modified')),
            (int) $response->getHeaderLine('Content-Length'),
            $response->getHeaderLine('Content-Type'),
            $response->getHeaderLine('Content-MD5'),
        );
    }
}