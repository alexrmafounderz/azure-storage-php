<?php

declare(strict_types=1);

namespace AzureOss\Storage\Blob;

use AzureOss\Storage\Blob\Exceptions\BlobStorageExceptionFactory;
use AzureOss\Storage\Blob\Exceptions\ContainerAlreadyExistsExceptionBlob;
use AzureOss\Storage\Blob\Exceptions\ContainerNotFoundExceptionBlob;
use AzureOss\Storage\Blob\Exceptions\InvalidBlobUriException;
use AzureOss\Storage\Blob\Exceptions\UnableToGenerateSasException;
use AzureOss\Storage\Blob\Models\Blob;
use AzureOss\Storage\Blob\Models\BlobPrefix;
use AzureOss\Storage\Blob\Responses\ListBlobsResponseBody;
use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Middleware\ClientFactory;
use AzureOss\Storage\Common\Sas\SasProtocol;
use AzureOss\Storage\Common\Serializer\SerializerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use JMS\Serializer\SerializerInterface;
use Psr\Http\Message\UriInterface;

final class BlobContainerClient
{
    private readonly Client $client;

    private readonly BlobStorageExceptionFactory $exceptionFactory;

    private readonly SerializerInterface $serializer;

    public readonly string $containerName;

    /**
     * @throws InvalidBlobUriException
     */
    public function __construct(
        public readonly UriInterface $uri,
        public readonly ?StorageSharedKeyCredential $sharedKeyCredentials = null,
    ) {
        $this->containerName = BlobUriParser::getContainerName($uri);
        $this->client = (new ClientFactory())->create($sharedKeyCredentials);
        $this->serializer = (new SerializerFactory())->create();
        $this->exceptionFactory = new BlobStorageExceptionFactory($this->serializer);
    }

    public function getBlobClient(string $blobName): BlobClient
    {
        return new BlobClient(
            $this->uri->withPath($this->uri->getPath() . "/" . $blobName),
            $this->sharedKeyCredentials,
        );
    }

    /**
     * @param array<string, string|null> $query
     * @return array<string, string>
     */
    private function buildQuery(array $query): array
    {
        return array_filter([
            ...Query::parse($this->uri->getQuery()),
            ...$query,
        ]);
    }

    public function create(): void
    {
        try {
            $this->client->put($this->uri, [
                'query' => $this->buildQuery([
                    'restype' => 'container',
                ]),
            ]);
        } catch (RequestException $e) {
            throw $this->exceptionFactory->create($e);
        }
    }

    public function createIfNotExists(): void
    {
        try {
            $this->create();
        } catch (ContainerAlreadyExistsExceptionBlob) {
            // do nothing
        }
    }

    public function delete(): void
    {
        try {
            $this->client->delete($this->uri, [
                'query' => $this->buildQuery([
                    'restype' => 'container',
                ]),
            ]);
        } catch (RequestException $e) {
            throw $this->exceptionFactory->create($e);
        }
    }

    public function deleteIfExists(): void
    {
        try {
            $this->delete();
        } catch (ContainerNotFoundExceptionBlob $e) {
            // do nothing
        }
    }

    public function exists(): bool
    {
        try {
            $this->client->head($this->uri, [
                'query' => $this->buildQuery([
                    'restype' => 'container',
                ]),
            ]);

            return true;
        } catch (RequestException $e) {
            $e = $this->exceptionFactory->create($e);

            if ($e instanceof ContainerNotFoundExceptionBlob) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * @return \Iterator<int, Blob>
     */
    public function getBlobs(?string $prefix = null): \Iterator
    {
        do {
            $nextMarker = "";

            $response = $this->listBlobs($prefix, null, $nextMarker);

            foreach ($response->blobs as $blob) {
                yield $blob;
            }

            $nextMarker = $response->nextMarker;
        } while ($nextMarker !== "");
    }

    /**
     * @param string $delimiter
     * @return \Iterator<int, Blob|BlobPrefix>
     */
    public function getBlobsByHierarchy(?string $prefix = null, string $delimiter = "/"): \Iterator
    {
        do {
            $nextMarker = "";

            $response = $this->listBlobs($prefix, $delimiter, $nextMarker);

            foreach ($response->blobs as $blob) {
                yield $blob;
            }

            foreach ($response->blobPrefixes as $blobPrefix) {
                yield $blobPrefix;
            }

            $nextMarker = $response->nextMarker;
        } while ($nextMarker !== "");
    }

    private function listBlobs(?string $prefix, ?string $delimiter, string $marker): ListBlobsResponseBody
    {
        try {
            $response = $this->client->get($this->uri, [
                'query' => $this->buildQuery([
                    'restype' => 'container',
                    'comp' => 'list',
                    'prefix' => $prefix,
                    'marker' => $marker,
                    'delimiter' => $delimiter,
                ]),
            ]);

            /** @phpstan-ignore-next-line */
            return $this->serializer->deserialize($response->getBody()->getContents(), ListBlobsResponseBody::class, 'xml');
        } catch (RequestException $e) {
            throw $this->exceptionFactory->create($e);
        }
    }

    public function canGenerateSasUri(): bool
    {
        return $this->sharedKeyCredentials !== null;
    }

    public function generateSasUri(BlobSasBuilder $blobSasBuilder): UriInterface
    {
        if ($this->sharedKeyCredentials === null) {
            throw new UnableToGenerateSasException();
        }

        if (BlobUriParser::isDevelopmentUri($this->uri)) {
            $blobSasBuilder->setProtocol(SasProtocol::HTTPS_AND_HTTP);
        }

        $sas = $blobSasBuilder
            ->setContainerName($this->containerName)
            ->build($this->sharedKeyCredentials);

        return new Uri("$this->uri?$sas");
    }
}