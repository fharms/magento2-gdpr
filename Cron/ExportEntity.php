<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Opengento\Gdpr\Cron;

use Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Opengento\Gdpr\Api\Data\ExportEntityInterface;
use Opengento\Gdpr\Api\ExportEntityManagementInterface;
use Opengento\Gdpr\Api\ExportEntityRepositoryInterface;
use Opengento\Gdpr\Model\Action\ActionFactory;
use Opengento\Gdpr\Model\Action\ContextBuilder;
use Opengento\Gdpr\Model\Action\Export\ArgumentReader;
use Opengento\Gdpr\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Export all scheduled entities
 */
final class ExportEntity
{
    private LoggerInterface $logger;

    private Config $config;

    private ExportEntityRepositoryInterface $exportRepository;

    private ExportEntityManagementInterface $exportManagement;

    private SearchCriteriaBuilder $criteriaBuilder;
    
    private ActionFactory $actionFactory;
    
    private ContextBuilder $contextBuilder;

    public function __construct(
        LoggerInterface $logger,
        Config $config,
        ExportEntityRepositoryInterface $exportRepository,
        ExportEntityManagementInterface $exportManagement,
        SearchCriteriaBuilder $criteriaBuilder,
        ActionFactory $actionFactory,
        ContextBuilder $contextBuilder
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->exportRepository = $exportRepository;
        $this->exportManagement = $exportManagement;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->actionFactory = $actionFactory;
        $this->contextBuilder = $contextBuilder;
    }

    public function execute(): void
    {
        if ($this->config->isModuleEnabled() && $this->config->isExportEnabled()) {
            $this->criteriaBuilder->addFilter(ExportEntityInterface::EXPORTED_AT, true, 'null');
            $this->criteriaBuilder->addFilter(ExportEntityInterface::FILE_PATH, true, 'null');

            try {
                $exportList = $this->exportRepository->getList($this->criteriaBuilder->create());

                foreach ($exportList->getItems() as $exportEntity) {
                    try {
                        // Use the action system to export and send notification
                        $action = $this->actionFactory->get('export_execute');
                        $actionContext = $this->contextBuilder
                            ->setPerformedFrom(Area::AREA_CRONTAB)
                            ->setPerformedBy('cron')
                            ->setParameters([
                                ArgumentReader::EXPORT_ENTITY => $exportEntity,
                                \Opengento\Gdpr\Model\Action\ArgumentReader::ENTITY_TYPE => $exportEntity->getEntityType(),
                                \Opengento\Gdpr\Model\Action\ArgumentReader::ENTITY_ID => $exportEntity->getEntityId()
                            ])
                            ->create();
                        $action->execute($actionContext);
                    } catch (NoSuchEntityException $e) {
                        $this->logger->error($e->getLogMessage(), $e->getTrace());
                        $this->exportRepository->delete($exportEntity);
                    }
                }
            } catch (Exception $e) {
                $this->logger->critical($e->getMessage(), $e->getTrace());
            }
        }
    }
}
