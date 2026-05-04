<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\TradeazeEndpoints;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;
use Tradeaze\ApiIntegration\Service\Tradeaze as TradeazeService;

abstract class ClientAbstract
{
    /**
     * @var string
     */
    protected string $endpointPath;

    /**
     * @var string
     */
    protected string $method;

    /**
     * @var array
     */
    protected array $params;

    /**
     * @var SourceInterface|null
     */
    protected ?SourceInterface $resolvedSource = null;

    /**
     * @var string
     */
    protected string $resolvedSourceCode = '';

    protected const TRADEAZE_API_DEFAULT_TIMEOUT = 10;

    /**
     * @param Client $httpClient
     * @param Tradeaze $tradeazeCache
     * @param Config $tradeazeConfig
     * @param TradeazeService $tradeaze
     * @param LoggerInterface $logger
     * @param TimezoneInterface $timezone
     * @param InventorySourceValidator $inventorySourceValidator
     * @param AddressInterfaceFactory $addressFactory
     */
    public function __construct(
        private readonly Client $httpClient,
        private readonly Tradeaze $tradeazeCache,
        protected readonly Config $tradeazeConfig,
        protected readonly TradeazeService $tradeaze,
        protected readonly LoggerInterface $logger,
        protected readonly TimezoneInterface $timezone,
        protected readonly InventorySourceValidator $inventorySourceValidator,
        protected readonly AddressInterfaceFactory $addressFactory
    ) {
        $this->setEndpointPath();
        $this->setMethod();
    }

    /**
     * Build the request to be sent to Tradeaze
     *
     * @return array
     */
    abstract public function buildRequest(): array;

    /**
     * Parse data from the Tradeaze response object
     *
     * @param mixed $response
     * @return array
     * @throws IntegrationException
     */
    public function parseResponse(mixed $response): array
    {
        $responseObject = json_decode($response->getBody()->getContents(), true);

        if (isset($responseObject['error'])) {
            if (isset($responseObject['error']['data']['issues'])) {
                foreach ($responseObject['error']['data']['issues'] as $issue) {
                    $this->logger->error(__(
                        "Error for [%1], [%2]",
                        [implode(',', $issue['path']), $issue['message']]
                    ));
                }
            }
            throw new IntegrationException(__($responseObject['error']['message']));
        }

        return $responseObject;
    }

    /**
     * Determines which Tradeaze endpoint to use
     *
     * @return void
     */
    abstract protected function setEndpointPath(): void;

    /**
     * Determines which method to use for the request
     *
     * @return void
     */
    abstract protected function setMethod(): void;

    /**
     * Validate parameters
     *
     * @return void
     * @throws LocalizedException
     * @throws ValidatorException
     */
    abstract protected function validateParams(): void;

    /**
     * Execute the call to the Tradeaze API
     *
     * @param array $params
     * @return array
     * @throws IntegrationException
     * @throws LocalizedException
     * @throws ValidatorException
     */
    public function execute(array $params): array
    {
        $this->params = $params;
        $useCache = $this->params['use_cache'] ?? false;
        $this->validateParams();
        $request = $this->buildRequest();
        return $this->makeRequest($request, $useCache);
    }

    /**
     * Wrapper method of \GuzzleHttp\Client::request
     *
     * @param array $body
     * @param bool $useCache
     * @return array
     * @throws IntegrationException
     */
    private function makeRequest(array $body, bool $useCache): array
    {
        $bodyString = json_encode($body);
        $response = $useCache ? $this->tradeazeCache->loadResponseFromCache($bodyString) : null;
        if (!$response) {
            try {
                $headers = $this->getAuthenticationHeaders();
                $uri = $this->tradeazeConfig->getApiUrl() . $this->endpointPath;
                $response = $this->httpClient->request(
                    $this->method,
                    $uri,
                    [
                        'timeout' => self::TRADEAZE_API_DEFAULT_TIMEOUT,
                        'headers' => $headers,
                        'body' => $bodyString
                    ]
                );

            } catch (GuzzleException $e) {
                if (!method_exists($e, 'getResponse') || !$e->getResponse()) {
                    throw new IntegrationException(__($e->getMessage()), $e);
                }

                $response = $e->getResponse();
                $useCache = false;
            }

            $response = $this->parseResponse($response);
            if ($useCache) {
                $this->tradeazeCache->saveResponseToCache($bodyString, $response);
            }
        }
        return $response;
    }

    /**
     * Build request headers including the x-api-key from config
     *
     * @return array
     */
    private function getAuthenticationHeaders(): array
    {
        return  [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $this->tradeazeConfig->getApiToken(),
        ];
    }
}
