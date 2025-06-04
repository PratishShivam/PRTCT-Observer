<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class ProvisionSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private OrderRepositoryInterface $orderRepo,
        private OrderCollectionFactory   $orderCollectionFactory,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees webhook payload
        $payload = $observer->getEvent()->getData('webhook_payload');
        $subId   = $payload['subscription_id'] ?? null;
        if (! $subId) {
            $this->logger->error("ProvisionSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Haal order op via increment_id
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $subId)
            ->setPageSize(1);
        $order = $collection->getFirstItem();
        if (! $order->getId()) {
            $this->logger->error("ProvisionSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Haal bestaande client_api_key op
        $extension = $order->getExtensionAttributes();
        $clientKey = $extension ? $extension->getClientApiKey() : null;
        if (! $clientKey) {
            $this->logger->error("ProvisionSubscriptionObserver: geen client key op order #{$subId} voor reactivering.");
            return;
        }

        // 4) Re-activate alle abilities bij PRTCT
        $abilities = [
            'health:check',
            'pass:check',
            'userpass:check',
            'statistics:get'
        ];
        $success = $this->apiKeyService->changeAbilities($clientKey, $abilities);
        if (! $success) {
            $this->logger->error("ProvisionSubscriptionObserver: kon abilities niet re-activeren voor key {$clientKey}.");
            return;
        }

        // 5) Markeer order als provisioned = true
        $extension->setProvisioned(true);
        $order->setExtensionAttributes($extension);
        $order->setData('provisioned', 1);
        $this->orderRepo->save($order);

        $this->logger->info("ProvisionSubscriptionObserver: client key {$clientKey} opnieuw geactiveerd voor order #{$subId}.");
    }
}
// This observer listens for subscription provisioning events and reactivates the client API key