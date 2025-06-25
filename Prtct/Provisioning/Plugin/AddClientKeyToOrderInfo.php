<?php
namespace Prtct\Provisioning\Plugin;

use Magento\Sales\Block\Order\Info;

class AddClientKeyToOrderInfo
{
    public function afterToHtml(Info $subject, string $result): string
    {
        // 1) Check dat we in precies wéér die ene block-instantie zitten
        if ($subject->getNameInLayout() !== 'sales.order.info') {
            return $result;
        }

        // 2) Haal de key op
        $order     = $subject->getOrder();
        $clientKey = $order->getData('client_api_key');
        if (!$clientKey) {
            return $result;
        }

        // 3) Hierdoor wordt er geen dubbele key toegevoegd
        if (strpos($result, 'Client API Key:') !== false) {
            return $result;
        }

        // 4) Bouwen van je HTML
        $html  = '<div class="box-content" style="margin-top: 15px;">';
        $html .= '<strong>Client API Key:</strong> ' . $subject->escapeHtml($clientKey);
        $html .= '</div>';

        // 5) Return gecombineerd
        return $result . $html;
    }
}
