<?php
namespace Omeka;

class Settings
{
    public function get($key, $default = null)
    {
        return $default;
    }
    
    public function set($key, $value)
    {
        return $this;
    }
}
