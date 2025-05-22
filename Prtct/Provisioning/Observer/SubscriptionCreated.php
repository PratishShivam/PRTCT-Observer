<?php
// app/code/Prtct/Provisioning/Observer/SubscriptionCreated.php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ApiKeyFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class SubscriptionCreated implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private ApiKeyFactory            $apiKeyFactory,
        private OrderRepositoryInterface $orderRepo,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Haal de order op
        $order = $observer->getEvent()->getOrder()
               ?: current($observer->getEvent()->getOrders());
        $subId = $order->getIncrementId();
        $this->logger->info("Provision order #{$subId}");

        // 2) Bouw payload en vraag client-sleutel aan
        $payload   = [
            'customer_email'  => $order->getCustomerEmail(),
            'subscription_id' => $subId,
            'plan'            => 'tier1', // stem af op SKU-logica
            'status'          => 'active'
        ];
        $clientKey = $this->apiKeyService->createClientKey($payload);

        if (! $clientKey) {
            $this->logger->error("Geen client API-key ontvangen voor {$subId}");
            return;
        }

        // 3) Sla op in eigen tabel
        $model = $this->apiKeyFactory->create();
        $model->setData([
            'customer_id'     => $order->getCustomerId(),
            'subscription_id' => $subId,
            'client_api_key'  => $clientKey,
            'status'          => 'active'
        ])->save();

        // 4) Markeer order als provisioned
        $order->setData('provisioned', true);
        $this->orderRepo->save($order);
        $this->logger->info("Order #{$subId} provisioned; client key opgeslagen.");
    }
}
