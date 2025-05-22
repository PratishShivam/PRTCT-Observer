<?php
// app/code/Prtct/Provisioning/Model/ApiKey.php
namespace Prtct\Provisioning\Model;

use Magento\Framework\Model\AbstractModel;

class ApiKey extends AbstractModel
{
    protected function _construct()
    {
        // Koppelt model aan ResourceModel
        $this->_init(\Prtct\Provisioning\Model\ResourceModel\ApiKey::class);
    }

    /**
     * Retourneer de client API-sleutel
     *
     * @return string
     */
    public function getClientApiKey(): string
    {
        return (string)$this->getData('client_api_key');
    }
}
