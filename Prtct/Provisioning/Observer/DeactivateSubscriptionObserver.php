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
        $payload = $observer->getEvent()->getData('webhook_payload'); // Haal de payload op uit het event
        $subId   = $payload['subscription_id'] ?? null; // Haal de subscription_id uit de payload, of null als deze niet bestaat
        if (! $subId) {
            $this->logger->error("DeactivateSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Haal de order op via increment_id = subscription_id
        $collection = $this->orderCollectionFactory->create() // Maak een nieuwe order-collectie // verzameling van model-instanties (zoals orders of klanten)
            ->addFieldToFilter('increment_id', $subId) // Filter de collectie op het increment_id dat overeenkomt met de subscription_id
            ->setPageSize(1); // Beperk de collectie tot 1 item
        $order = $collection->getFirstItem(); 
        if (! $order->getId()) { // Controleer of de order bestaat
            $this->logger->error("DeactivateSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Haal client_api_key op via extension attributes
        $extension = $order->getExtensionAttributes(); // Haal de extensie-attributen van de order op
        $clientKey = $extension ? $extension->getClientApiKey() : null; // Haal de client_api_key op uit de extensie-attributen // of null als deze niet bestaat
        if (! $clientKey) {
            $this->logger->warning("DeactivateSubscriptionObserver: geen client key op order #{$subId}.");
            return;
        }

        // 4) Intrekken van alle abilities bij PRTCT
        $this->apiKeyService->changeAbilities($clientKey, []); // Verwijder alle abilities van de client key bij PRTCT

        // 5) Verwijder client_api_key en zet provisioned op false (0)
        $extension->setClientApiKey(null); // Verwijder de client_api_key uit de extensie-attributen
        $extension->setProvisioned(false); // Zet provisioned op false (0) in de extensie-attributen
        $order->setExtensionAttributes($extension); // Sla de gewijzigde extensie-attributen op in de order
        $order->setData('client_api_key', null); // Verwijder de client_api_key uit de order data
        $order->setData('provisioned', 0); // Zet provisioned op false (0) in de order data

        // 6) Sla gewijzigde order op
        $this->orderRepo->save($order);
        $this->logger->info("DeactivateSubscriptionObserver: order #{$subId} – client key gedeactiveerd en verwijderd.");
    }
}
