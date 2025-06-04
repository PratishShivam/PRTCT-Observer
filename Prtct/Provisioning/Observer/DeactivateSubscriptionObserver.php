<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class DeactivateSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private OrderRepositoryInterface $orderRepo,
        private OrderCollectionFactory   $orderCollectionFactory,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees subscription_id uit webhook payload
        $payload = $observer->getEvent()->getData('webhook_payload');
        $subId   = $payload['subscription_id'] ?? null;
        if (! $subId) {
            $this->logger->error("DeactivateSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Haal de order op via increment_id = subscription_id
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $subId)
            ->setPageSize(1);
        $order = $collection->getFirstItem();
        if (! $order->getId()) {
            $this->logger->error("DeactivateSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Haal client_api_key op via extension attributes
        $extension = $order->getExtensionAttributes();
        $clientKey = $extension ? $extension->getClientApiKey() : null;
        if (! $clientKey) {
            $this->logger->warning("DeactivateSubscriptionObserver: geen client key op order #{$subId}.");
            return;
        }

        // 4) Intrekken van alle abilities bij PRTCT
        $this->apiKeyService->changeAbilities($clientKey, []);

        // 5) Verwijder client_api_key en zet provisioned op false (0)
        $extension->setClientApiKey(null);
        $extension->setProvisioned(false);
        $order->setExtensionAttributes($extension);
        $order->setData('client_api_key', null);
        $order->setData('provisioned', 0);

        // 6) Sla gewijzigde order op
        $this->orderRepo->save($order);
        $this->logger->info("DeactivateSubscriptionObserver: order #{$subId} – client key gedeactiveerd en verwijderd.");
    }
}
