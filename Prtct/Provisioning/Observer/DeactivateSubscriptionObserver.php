<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class DeactivateSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private OrderRepositoryInterface $orderRepo,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees subscription_id uit Mollie-webhook
        $payload = $observer->getEvent()->getData('webhook_payload');
        $subId   = $payload['subscription_id'] ?? null;
        if (! $subId) {
            $this->logger->error("DeactivateSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Laad order op via increment_id = subscription_id
        try {
            $order = $this->orderRepo->get($subId);
        } catch (\Exception $e) {
            $this->logger->error("DeactivateSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Haal opgeslagen token
        $clientKey = $order->getData('client_api_key');
        if (! $clientKey) {
            $this->logger->warning("DeactivateSubscriptionObserver: geen client_api_key op order #{$subId}.");
            return;
        }

        // 4) Intrek alle abilities in PRTCT
        $success = $this->apiKeyService->changeAbilities($clientKey, []);
        if (! $success) {
            $this->logger->error(
                "DeactivateSubscriptionObserver: kon abilities niet intrekken voor key {$clientKey}."
            );
        }

        // 5) Markeer order als niet-provisioned (token blijft bewaard)
        $order->setData('provisioned', 0);
        $this->orderRepo->save($order);

        $this->logger->info("DeactivateSubscriptionObserver: order #{$subId} gedeprovisioneerd (token bewaard).");
    }
}
