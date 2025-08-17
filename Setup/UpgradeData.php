<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */

declare(strict_types=1);

namespace Opengento\Gdpr\Setup;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Store\Model\ScopeInterface;

class UpgradeData implements UpgradeDataInterface
{
    private WriterInterface $configWriter;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        WriterInterface $configWriter,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
    }

    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '4.4.4', '<')) {
            $this->migrateAllowedStatesToStatuses($setup);
        }

        $setup->endSetup();
    }

    /**
     * Migrate gdpr/erasure/allowed_states configuration to gdpr/erasure/allowed_statuses
     */
    private function migrateAllowedStatesToStatuses(ModuleDataSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $configTable = $setup->getTable('core_config_data');

        // Get all allowed_states configurations
        $select = $connection->select()
            ->from($configTable)
            ->where('path = ?', 'gdpr/erasure/allowed_states');

        $configs = $connection->fetchAll($select);

        foreach ($configs as $config) {
            // Check if allowed_statuses already exists for this scope
            $existingSelect = $connection->select()
                ->from($configTable, 'COUNT(*)')
                ->where('path = ?', 'gdpr/erasure/allowed_statuses')
                ->where('scope = ?', $config['scope'])
                ->where('scope_id = ?', $config['scope_id']);

            $existingCount = (int) $connection->fetchOne($existingSelect);

            if ($existingCount === 0) {
                // Insert new allowed_statuses configuration with same value
                $connection->insert($configTable, [
                    'scope' => $config['scope'],
                    'scope_id' => $config['scope_id'],
                    'path' => 'gdpr/erasure/allowed_statuses',
                    'value' => $config['value']
                ]);
            }

            // Delete old allowed_states configuration
            $connection->delete($configTable, [
                'config_id = ?' => $config['config_id']
            ]);
        }
    }
}