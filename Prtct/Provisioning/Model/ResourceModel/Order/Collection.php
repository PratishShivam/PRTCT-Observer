<?php
namespace Prtct\Provisioning\Model\ResourceModel\Order;

use Magento\Sales\Model\ResourceModel\Order\Collection as CoreOrderCollection;

class Collection extends CoreOrderCollection
{
    // Door te extenderen kunnen we filters gebruiken op sales_order,
    // bijvoorbeeld ->addFieldToFilter('client_api_key', ['notnull'=>true])
}
