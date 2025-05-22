<?php
// app/code/Prtct/Provisioning/Observer/DeactivateSubscriptionObserver.php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\ApiKey\CollectionFactory;
use Psr\Log\LoggerInterface;

class DeactivateSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService      $apiKeyService,
        private CollectionFactory  $collectionFactory,
        private LoggerInterface    $logger
    ) {}

    public function execute(Observer $observer)
    {
        // Haal subscription_id uit webhook payload
        $subId = $observer->getEvent()->getData('webhook_payload')['subscription_id'] ?? null;
        if (! $subId) {
            return;
        }

        // Zoek bijbehorende record
        $record = $this->collectionFactory->create()
                   ->addFieldToFilter('subscription_id', $subId)
                   ->getFirstItem();
        if (! $record->getId()) {
            return;
        }

        // 1) Revoke alle abilities (lege array)
        $this->apiKeyService->changeAbilities($record->getClientApiKey(), []);

        // 2) Update eigen tabel
        $record->setStatus('inactive')->save();
        $this->logger->info("Client key voor {$subId} gedeactiveerd.");
    }
}
