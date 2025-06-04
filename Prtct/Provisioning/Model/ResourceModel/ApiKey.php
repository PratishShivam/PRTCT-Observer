<?php
namespace Prtct\Provisioning\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ApiKey extends AbstractDb
{
    protected function _construct()
    {
        // Koppelt ResourceModel aan de tabel en PK-kolom
        $this->_init('prtct_api_keys', 'entity_id');
    }
}
