<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Block;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\Template\Context;
use Tradeaze\ApiIntegration\Helper\Config;
use Magento\Framework\View\Element\Template;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class OnSiteMessaging extends Template
{
    /**
     * @param Context $context
     * @param Config $tradeazeConfig
     * @param Tradeaze $tradeazeService
     * @param ProductRepositoryInterface $productRepository
     * @param Repository $assetRepository
     * @param array $data
     */
    public function __construct(
        protected readonly Context $context,
        protected readonly Config $tradeazeConfig,
        protected readonly Tradeaze $tradeazeService,
        protected readonly ProductRepositoryInterface $productRepository,
        protected readonly Repository $assetRepository,
        protected array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Check if the on-site messaging can be displayed
     *
     * @return bool
     */
    public function canShowOnsiteMessaging(): bool
    {
        $product = $this->getProduct();
        if (!$product) {
            return false;
        }

        if (!$this->tradeazeConfig->isEnabled() ||
            !$this->tradeazeService->isTradeazeProduct($product)
        ) {
            return false;
        }

        $action = $this->getRequest()->getFullActionName();

        return
            ($action === 'catalog_product_view' && $this->tradeazeConfig->isPDPMessagingEnabled()) ||
            ($action === 'checkout_cart_index' && $this->tradeazeConfig->isBasketMessagingEnabled());
    }

    /**
     * Get the URL for a view asset file (images, CSS, JS)
     *
     * @param string $fileId
     * @param array $params
     * @return string
     */
    public function getAssetUrl(string $fileId, array $params = []): string
    {
        $params = array_merge(['_secure' => true], $params);
        try {
            return $this->assetRepository->createAsset($fileId, $params)->getUrl();
        } catch (LocalizedException $e) {
            return '';
        }
    }

    /**
     * Get the product to check if it's eligible for Tradeaze delivery
     *
     * @return ProductInterface|null
     */
    public function getProduct(): ?ProductInterface
    {
        if ($this->getRequest()->getFullActionName() === 'catalog_product_view') {
            $productId = (int) $this->getRequest()->getParam('id');

            if (!$productId) {
                return null;
            }

            try {
                return $this->productRepository->getById($productId);
            } catch (NoSuchEntityException $e) {
                return null;
            }
        } elseif ($this->getItem() && $this->getItem()->getProduct()) {
            return $this->getItem()->getProduct();
        }

        return null;
    }
}
