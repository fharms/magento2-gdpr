<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Opengento\Gdpr\Cron;

use Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResults;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Opengento\Gdpr\Api\Data\EraseEntityInterface;
use Opengento\Gdpr\Api\Data\EraseEntitySearchResultsInterface;
use Opengento\Gdpr\Api\EraseEntityManagementInterface;
use Opengento\Gdpr\Api\EraseEntityRepositoryInterface;
use Opengento\Gdpr\Model\Action\ActionFactory;
use Opengento\Gdpr\Model\Action\ContextBuilder;
use Opengento\Gdpr\Model\Action\Erase\ArgumentReader;
use Opengento\Gdpr\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Process erase of all scheduled entities
 */
final class EraseEntity
{
    private LoggerInterface $logger;

    private Config $config;

    private Registry $registry;

    private EraseEntityManagementInterface $eraseManagement;

    private EraseEntityRepositoryInterface $eraseRepository;

    private SearchCriteriaBuilder $criteriaBuilder;

    private DateTime $dateTime;

    private ActionFactory $actionFactory;

    private ContextBuilder $contextBuilder;

    private State $appState;

    public function __construct(
        LoggerInterface $logger,
        Config $config,
        Registry $registry,
        EraseEntityManagementInterface $eraseManagement,
        EraseEntityRepositoryInterface $eraseRepository,
        SearchCriteriaBuilder $criteriaBuilder,
        DateTime $dateTime,
        ActionFactory $actionFactory,
        ContextBuilder $contextBuilder,
        State $appState
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->registry = $registry;
        $this->eraseManagement = $eraseManagement;
        $this->eraseRepository = $eraseRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->dateTime = $dateTime;
        $this->actionFactory = $actionFactory;
        $this->contextBuilder = $contextBuilder;
        $this->appState = $appState;
    }

    public function execute(): void
    {
        if ($this->config->isModuleEnabled() && $this->config->isErasureEnabled()) {
            // Set area code for email template processing
            try {
                $this->appState->setAreaCode(Area::AREA_FRONTEND);
            } catch (LocalizedException $e) {
                // Area already set, continue processing
            }

            $oldValue = $this->registry->registry('isSecureArea');
            $this->registry->register('isSecureArea', true, true);

            foreach ($this->retrieveEraseEntityList()->getItems() as $eraseEntity) {
                try {
                    $this->processEraseEntity($eraseEntity);
                } catch (Exception $e) {
                    $this->logger->error($e->getMessage(), $e->getTrace());
                }
            }

            $this->registry->register('isSecureArea', $oldValue, true);
        }
    }

    private function processEraseEntity(EraseEntityInterface $eraseEntity): void
    {
        $action = $this->actionFactory->get('erase_execute');
        $actionContext = $this->contextBuilder
            ->setPerformedFrom(Area::AREA_CRONTAB)
            ->setPerformedBy('cron')
            ->setParameters([
                ArgumentReader::ERASE_ENTITY => $eraseEntity,
                \Opengento\Gdpr\Model\Action\ArgumentReader::ENTITY_TYPE => $eraseEntity->getEntityType(),
                \Opengento\Gdpr\Model\Action\ArgumentReader::ENTITY_ID => $eraseEntity->getEntityId()
            ])
            ->create();
        
        $action->execute($actionContext);
    }

    /**
     * @return EraseEntitySearchResultsInterface
     */
    private function retrieveEraseEntityList(): SearchResultsInterface
    {
        $this->criteriaBuilder->addFilter(
            EraseEntityInterface::SCHEDULED_AT,
            $this->dateTime->date(),
            'lteq'
        );
        $this->criteriaBuilder->addFilter(
            EraseEntityInterface::STATE,
            EraseEntityInterface::STATE_COMPLETE,
            'neq'
        );
        $this->criteriaBuilder->addFilter(
            EraseEntityInterface::STATUS,
            [EraseEntityInterface::STATUS_READY, EraseEntityInterface::STATUS_FAILED],
            'in'
        );

        try {
            return $this->eraseRepository->getList($this->criteriaBuilder->create());
        } catch (LocalizedException $e) {
            $this->logger->error('Failed to retrieve erase entity list: ' . $e->getMessage());
            $searchResults = new SearchResults();
            $searchResults->setItems([]);
            return $searchResults;
        }
    }
}
