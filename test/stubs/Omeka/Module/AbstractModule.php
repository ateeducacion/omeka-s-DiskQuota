<?php
namespace Omeka\Module;

class AbstractModule
{
    protected $serviceLocator;
    
    public function setServiceLocator($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }
    
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
    
    // Add here other methods you need to simulate
}
