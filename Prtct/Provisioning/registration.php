<?php
// Registreer de module bij Magento
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,        // Module
    'Prtct_Provisioning',              // VendorNaam_ModuleNaam
    __DIR__                            // Pad naar deze map
);
