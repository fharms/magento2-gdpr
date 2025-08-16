<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Opengento\Gdpr\Model\Customer\Delete\Processor;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\SessionCleanerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Opengento\Gdpr\Model\Customer\OrigDataRegistry;
use Opengento\Gdpr\Service\Erase\ProcessorInterface;

final class CustomerDataProcessor implements ProcessorInterface
{
    /**
     * @var CustomerRepositoryInterface
     */
    private CustomerRepositoryInterface $customerRepository;

    /**
     * @var SessionCleanerInterface
     */
    private SessionCleanerInterface $sessionCleaner;

    /**
     * @var OrigDataRegistry
     */
    private OrigDataRegistry $origDataRegistry;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        SessionCleanerInterface $sessionCleaner,
        OrigDataRegistry $origDataRegistry
    ) {
        $this->customerRepository = $customerRepository;
        $this->sessionCleaner = $sessionCleaner;
        $this->origDataRegistry = $origDataRegistry;
    }

    /**
     * @inheritdoc
     * @throws LocalizedException
     */
    public function execute(int $customerId): bool
    {
        $this->storeOriginalCustomerData($customerId);
        $this->sessionCleaner->clearFor($customerId);
        $this->deleteCustomer($customerId);

        return true;
    }

    private function storeOriginalCustomerData(int $customerId): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $this->origDataRegistry->set(clone $customer);
        } catch (NoSuchEntityException $e) {
            // Customer already deleted, nothing to store
        }
    }

    private function deleteCustomer(int $customerId): void
    {
        try {
            $this->customerRepository->deleteById($customerId);
        } catch (NoSuchEntityException $e) {
            // Customer already deleted, nothing to do
        }
    }
}
