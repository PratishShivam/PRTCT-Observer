<?php
/**
 * - Haalt meerdere rijen uit uit prtct_api_keys
 */
namespace Prtct\Provisioning\Model\ResourceModel\ApiKey;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        // Initieert model en resource model voor collections
        $this->_init(
            \Prtct\Provisioning\Model\ApiKey::class,
            \Prtct\Provisioning\Model\ResourceModel\ApiKey::class
        );
    }
}
