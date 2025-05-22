<?php
// app/code/Prtct/Provisioning/Observer/ProvisionSubscriptionObserver.php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\ApiKey\CollectionFactory;
use Psr\Log\LoggerInterface;

class ProvisionSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService      $apiKeyService,
        private CollectionFactory  $collectionFactory,
        private LoggerInterface    $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Haal subscription_id uit webhook payload
        $subId = $observer->getEvent()->getData('webhook_payload')['subscription_id'] ?? null;
        if (! $subId) {
            return;
        }

        // 2) Zoek record
        $record = $this->collectionFactory->create()
                   ->addFieldToFilter('subscription_id', $subId)
                   ->getFirstItem();
        if (! $record->getId()) {
            return;
        }

        // 3) Reactivate alle abilities
        $this->apiKeyService->changeAbilities($record->getClientApiKey(), [
            'health:check', 'pass:check', 'userpass:check', 'statistics:get'
        ]);

        // 4) Update eigen tabel
        $record->setStatus('active')->save();
        $this->logger->info("Client key voor {$subId} opnieuw geactiveerd.");
    }
}
