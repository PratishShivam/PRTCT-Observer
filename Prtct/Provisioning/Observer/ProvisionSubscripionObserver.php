<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class ProvisionSubscriptionObserver implements ObserverInterface
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
            $this->logger->error("ProvisionSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Laad order op via increment_id = subscription_id
        try {
            $order = $this->orderRepo->get($subId);
        } catch (\Exception $e) {
            $this->logger->error("ProvisionSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Lees opgeslagen token
        $clientKey = $order->getData('client_api_key');
        if (! $clientKey) {
            $this->logger->error("ProvisionSubscriptionObserver: geen client_api_key op order #{$subId}.");
            return;
        }

        // 4) Heractiveer abilities in PRTCT
        $abilities = [
            'health:check',
            'pass:check',
            'userpass:check',
            'statistics:get'
        ];
        if (! $this->apiKeyService->changeAbilities($clientKey, $abilities)) {
            $this->logger->error(
                "ProvisionSubscriptionObserver: kon abilities niet re-activeren voor key {$clientKey}."
            );
            return;
        }

        // 5) Markeer order als provisioned
        $order->setData('provisioned', 1);
        $this->orderRepo->save($order);

        $this->logger->info("ProvisionSubscriptionObserver: token {$clientKey} heractiveerd voor order #{$subId}.");
    }
}
